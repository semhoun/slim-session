<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Http;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Semhoun\SlimSession\Exception\LockTimeoutException;
use Semhoun\SlimSession\Exception\SessionNotFoundException;
use Semhoun\SlimSession\Exception\StorageUnavailableException;
use Semhoun\SlimSession\Exception\WriteConflictException;
use Semhoun\SlimSession\Jwt\InvalidTokenException;
use Semhoun\SlimSession\Jwt\JwtService;
use Semhoun\SlimSession\Jwt\VerifiedToken;
use Semhoun\SlimSession\SessionContext;
use Semhoun\SlimSession\SessionManager;
use Semhoun\SlimSession\SessionState;

final class SessionMiddleware implements MiddlewareInterface
{
    public const METADATA_RESPONSE = 'X-Slim-Session-Metadata-Response';

    /** @param null|\Closure(ServerRequestInterface):bool $activityPolicy Server-owned activity policy. */
    public function __construct(
        private readonly SessionManager $manager,
        private readonly JwtService $jwt,
        private readonly HttpConfig $config,
        private readonly ClockInterface $clock,
        private readonly ResponseFactoryInterface $responses,
        private readonly ?\Closure $activityPolicy = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $context = null;
        try {
            $credential = $this->credential($request);
            $token = $credential === null ? null : $this->jwt->verify($credential);
            $activity = $this->activityPolicy === null || ($this->activityPolicy)($request);
            $context = $token === null ? $this->manager->absent() : $this->manager->load($token->sessionId, $activity);
            if ($token !== null && !hash_equals($context->record()->verificationSecret ?? '', $token->verificationSecret)) {
                throw new InvalidTokenException('Invalid session credential.');
            }
            if ($token !== null && $this->config->transport === 'cookie') {
                $this->protect($request, $context);
            }
            if ($token !== null && $this->clock->now()->getTimestamp() >= $this->jwt->refreshAt($token->issuedAt, $token->expiresAt)) {
                $context->renew();
            }
            $admission = $token === null ? null : new AdmissionMetadata($this->config->transport, $token->expiresAt);
            $response = $handler->handle($request
                ->withAttribute(SessionGuard::ATTRIBUTE, $context)
                ->withAttribute(SessionGuard::ADMISSION_ATTRIBUTE, $admission));
            if ($context->state() === SessionState::Open) {
                $context->saveAndClose();
            }
            return $this->finalize($response, $context, $token);
        } catch (\Throwable $error) {
            if ($context !== null) {
                try {
                    $context->discard();
                } catch (\Throwable) {
                    // Preserve the original failure; no credential is emitted on this path.
                }
            }
            $status = match (true) {
                $error instanceof HttpSessionException => $error->status,
                $error instanceof InvalidTokenException, $error instanceof SessionNotFoundException => 401,
                $error instanceof LockTimeoutException, $error instanceof StorageUnavailableException => 503,
                $error instanceof WriteConflictException => 409,
                default => null,
            };
            if ($status === null) {
                throw $error;
            }
            $response = $this->responses->createResponse($status)->withHeader('Cache-Control', 'no-store');
            if ($status === 401 && $this->config->transport === 'cookie') {
                $response = $response->withAddedHeader('Set-Cookie', $this->cookie('', 0));
            }
            return $response;
        }
    }

    private function credential(ServerRequestInterface $request): ?string
    {
        if ($this->config->transport === 'header') {
            $headers = $request->getHeader($this->config->credentialHeader);
            if ($headers === []) {
                return null;
            }
            if (count($headers) !== 1 || !preg_match('/^Bearer ([A-Za-z0-9_.-]+)$/D', $headers[0], $match)) {
                throw new HttpSessionException(401, 'Ambiguous session credential.');
            }
            return $match[1];
        }
        $found = [];
        foreach ($request->getHeader('Cookie') as $line) {
            foreach (explode(';', $line) as $cookie) {
                $parts = explode('=', trim($cookie), 2);
                if ($parts[0] === $this->config->cookieName) {
                    $found[] = $parts[1] ?? '';
                }
            }
        }
        if (count($found) > 1 || (count($found) === 1 && $found[0] === '')) {
            throw new HttpSessionException(401, 'Ambiguous session credential.');
        }
        return $found[0] ?? null;
    }

    private function protect(ServerRequestInterface $request, SessionContext $context): void
    {
        if (in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $origins = $request->getHeader('Origin');
        $csrf = $request->getHeader($this->config->csrfHeader);
        if (($this->config->originRequired && (count($origins) !== 1 || !in_array($origins[0], [$this->config->canonicalOrigin, ...$this->config->allowedOrigins], true)))
            || ($this->config->csrfRequired && (count($csrf) !== 1 || !hash_equals($context->record()->csrfToken ?? '', $csrf[0]) || $csrf[0] === ''))) {
            throw new HttpSessionException(403, 'Invalid origin or CSRF proof.');
        }
    }

    private function finalize(ResponseInterface $response, SessionContext $context, ?VerifiedToken $token): ResponseInterface
    {
        $pending = $context->pendingResponse();
        $metadata = ['authenticated' => false, 'transport' => $this->config->transport];
        $response = $response->withHeader('Cache-Control', 'no-store');
        if ($pending?->destroyed) {
            if ($this->config->transport === 'cookie') {
                $response = $response->withAddedHeader('Set-Cookie', $this->cookie('', 0));
            }
        } elseif (($record = $pending->record ?? $context->record()) !== null) {
            $expiry = $token?->expiresAt;
            $refreshAt = $token === null ? null : $this->jwt->refreshAt($token->issuedAt, $token->expiresAt);
            if ($pending?->issueToken) {
                $issued = $this->jwt->issueWithExpiry($record->id, $record->absoluteExpiresAt, $record->verificationSecret);
                $encoded = $issued->token;
                $expiry = $issued->expiresAt;
                $refreshAt = $issued->refreshAt;
                $response = $this->config->transport === 'cookie'
                    ? $response->withAddedHeader('Set-Cookie', $this->cookie($encoded, max(0, $expiry - $this->clock->now()->getTimestamp())))
                    : $response->withHeader($this->config->responseTokenHeader, $encoded);
            }
            $metadata = ['authenticated' => true, 'transport' => $this->config->transport,
                'tokenExpiresAt' => $expiry, 'tokenRefreshAt' => $refreshAt,
                'idleExpiresAt' => $record->idleExpiresAt, 'absoluteExpiresAt' => $record->absoluteExpiresAt];
            $response = $response->withHeader('X-Session-Token-Refresh-At', (string) $refreshAt);
            foreach (['token' => $expiry, 'idle' => $record->idleExpiresAt, 'absolute' => $record->absoluteExpiresAt] as $name => $value) {
                $response = $response->withHeader('X-Session-' . ucfirst($name) . '-Expires-At', (string) $value);
            }
            if ($this->config->transport === 'cookie') {
                $metadata['csrfToken'] = $record->csrfToken;
                $response = $response->withHeader($this->config->csrfHeader, $record->csrfToken);
            }
        }
        if ($response->hasHeader(self::METADATA_RESPONSE)) {
            $body = $response->getBody();
            $body->rewind();
            $body->write(json_encode($metadata, JSON_THROW_ON_ERROR));
            $response = $response->withoutHeader(self::METADATA_RESPONSE)->withHeader('Content-Type', 'application/json');
        }
        return $response;
    }

    private function cookie(string $token, int $maxAge): string
    {
        return $this->config->cookieName . '=' . $token . '; Path=/; Max-Age=' . $maxAge
            . '; Secure; HttpOnly; SameSite=' . $this->config->sameSite;
    }
}
