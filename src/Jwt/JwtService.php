<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Jwt;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Psr\Clock\ClockInterface;

final class JwtService
{
    /** @var array<string, string> */
    private readonly array $keys;

    /** @param array<array-key, mixed> $keys Raw secrets, each at least 32 random bytes. */
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly string $issuer,
        private readonly string $audience,
        array $keys,
        private readonly string $activeKeyId,
        public readonly int $lifetime = 86400,
        public readonly ?int $renewalWindow = null,
        private readonly int $maximumBytes = 4096,
        private readonly int $clockTolerance = 0,
    ) {
        if ($issuer === '' || $audience === '' || !isset($keys[$activeKeyId]) || $lifetime < 1 || ($renewalWindow !== null && $renewalWindow < 0) || $maximumBytes < 256 || $clockTolerance < 0) {
            throw new \InvalidArgumentException('Invalid JWT configuration.');
        }
        $validated = [];
        foreach ($keys as $id => $secret) {
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) || !is_string($secret) || strlen($secret) < 32) {
                throw new \InvalidArgumentException('JWT keys require local identifiers and secrets of at least 32 bytes.');
            }
            $validated[$id] = $secret;
        }
        $this->keys = $validated;
    }

    public function expiresAt(int $absoluteExpiresAt): int
    {
        return min($this->clock->now()->getTimestamp() + $this->lifetime, $absoluteExpiresAt);
    }

    public function refreshAt(int $issuedAt, int $expiresAt): int
    {
        if ($this->renewalWindow !== null) {
            return max($issuedAt, $expiresAt - $this->renewalWindow);
        }
        $duration = $expiresAt - $issuedAt;
        return $issuedAt + intdiv($duration, 2) + $duration % 2;
    }

    public function issue(string $sessionId, int $absoluteExpiresAt, string $verificationSecret): string
    {
        return $this->issueWithExpiry($sessionId, $absoluteExpiresAt, $verificationSecret)->token;
    }

    public function issueWithExpiry(string $sessionId, int $absoluteExpiresAt, string $verificationSecret): IssuedToken
    {
        $now = $this->clock->now();
        $now = $now->setTimestamp($now->getTimestamp());
        $expiry = min($now->getTimestamp() + $this->lifetime, $absoluteExpiresAt);
        if (!\Semhoun\SlimSession\SessionId::valid($sessionId) || !preg_match('/\A[a-f0-9]{64}\z/D', $verificationSecret) || $expiry <= $now->getTimestamp()) {
            throw new InvalidTokenException('Cannot issue a token for an expired session.');
        }
        $configuration = $this->configuration($this->activeKeyId);
        $token = $configuration->builder()->withHeader('kid', $this->activeKeyId)
            ->issuedBy($this->issuer)->permittedFor($this->audience)->relatedTo($sessionId)
            ->withClaim('sv', 2)->withClaim('svp', $verificationSecret)
            ->issuedAt($now)->canOnlyBeUsedAfter($now)->expiresAt($now->setTimestamp($expiry))
            ->getToken($configuration->signer(), $configuration->signingKey())->toString();
        if (strlen($token) > $this->maximumBytes) {
            throw new InvalidTokenException('Token exceeds configured size.');
        }
        return new IssuedToken($token, $expiry, $this->refreshAt($now->getTimestamp(), $expiry));
    }

    public function verify(string $encoded): VerifiedToken
    {
        try {
            if (strlen($encoded) > $this->maximumBytes || !preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $encoded)) {
                throw new \UnexpectedValueException();
            }
            [$headerPart, $claimsPart] = explode('.', $encoded);
            $header = $this->decode($headerPart);
            $claims = $this->decode($claimsPart);
            if (array_diff(array_keys($header), ['typ', 'alg', 'kid']) !== [] || ($header['alg'] ?? null) !== 'HS256'
                || (isset($header['typ']) && $header['typ'] !== 'JWT') || !is_string($header['kid'] ?? null) || !isset($this->keys[$header['kid']])) {
                throw new \UnexpectedValueException();
            }
            $expected = ['sub', 'iss', 'aud', 'iat', 'nbf', 'exp', 'sv', 'svp'];
            if (count($claims) !== count($expected) || array_diff($expected, array_keys($claims)) !== []
                || !is_string($claims['sub']) || !\Semhoun\SlimSession\SessionId::valid($claims['sub']) || !is_string($claims['iss']) || $claims['iss'] !== $this->issuer
                || $claims['sv'] !== 2 || !is_string($claims['svp']) || !preg_match('/\A[a-f0-9]{64}\z/D', $claims['svp'])
                || ($claims['aud'] !== $this->audience && $claims['aud'] !== [$this->audience])
                || !is_int($claims['iat']) || !is_int($claims['nbf']) || !is_int($claims['exp'])) {
                throw new \UnexpectedValueException();
            }
            $now = $this->clock->now()->getTimestamp();
            if ($claims['iat'] < 0 || $claims['nbf'] < $claims['iat'] || $claims['exp'] <= $claims['nbf']
                || $claims['iat'] > $now + $this->clockTolerance || $claims['nbf'] > $now + $this->clockTolerance
                || $claims['exp'] <= $now - $this->clockTolerance || $claims['exp'] - $claims['iat'] > $this->lifetime) {
                throw new \UnexpectedValueException();
            }
            $configuration = $this->configuration($header['kid']);
            $token = $configuration->parser()->parse($encoded);
            if (!$token instanceof UnencryptedToken || !$configuration->signer()->verify($token->signature()->hash(), $token->payload(), $configuration->verificationKey())) {
                throw new \UnexpectedValueException();
            }
            return new VerifiedToken($claims['sub'], $claims['iat'], $claims['exp'], $claims['svp']);
        } catch (\Throwable $error) {
            throw new InvalidTokenException('Invalid session credential.', 0, $error);
        }
    }

    private function configuration(string $id): Configuration
    {
        return Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($this->keys[$id]));
    }

    /** @return array<string, mixed> */
    private function decode(string $part): array
    {
        $decoded = base64_decode(strtr($part, '-_', '+/'), true);
        if ($decoded === false || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $part) {
            throw new \UnexpectedValueException();
        }
        $value = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException();
        }
        return $value;
    }
}
