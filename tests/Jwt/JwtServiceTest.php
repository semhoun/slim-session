<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Tests\Jwt;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Semhoun\SlimSession\Jwt\InvalidTokenException;
use Semhoun\SlimSession\Jwt\JwtService;

final class JwtServiceTest extends TestCase
{
    private function clock(int $time = 1700000000): ClockInterface
    {
        return new class ($time) implements ClockInterface {
            public function __construct(public int $time) {}
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('@' . $this->time); }
        };
    }

    private function service(?ClockInterface $clock = null): JwtService
    {
        return new JwtService($clock ?? $this->clock(), 'application-a', 'audience-a', ['current' => str_repeat('a', 32), 'previous' => str_repeat('b', 32)], 'current');
    }

    public function testRoundTripAndPayloadContainsOnlyTechnicalClaims(): void
    {
        $service = $this->service();
        $encoded = $service->issue('session-id', 1700000100);
        $verified = $service->verify($encoded);
        self::assertSame('session-id', $verified->sessionId);
        self::assertSame(1700000100, $verified->expiresAt);
        $claims = json_decode(base64_decode(strtr(explode('.', $encoded)[1], '-_', '+/')), true, 16, JSON_THROW_ON_ERROR);
        self::assertEqualsCanonicalizing(['sub', 'iss', 'aud', 'iat', 'nbf', 'exp'], array_keys($claims));
    }

    public function testOldLocalKeyCanVerifyButCannotSelectRemoteKeys(): void
    {
        $old = new JwtService($this->clock(), 'application-a', 'audience-a', ['previous' => str_repeat('b', 32)], 'previous');
        self::assertSame('id', $this->service()->verify($old->issue('id', 1700100000))->sessionId);
        $this->expectException(InvalidTokenException::class);
        $this->service()->verify($this->signed(['alg' => 'HS256', 'kid' => 'https://keys.invalid/key']));
    }

    public function testExpiredOfflineTokenCannotRefreshEvenIfSessionRemainsLive(): void
    {
        $clock = $this->clock();
        $service = $this->service($clock);
        $encoded = $service->issue('live-server-record', 1700604800);
        $clock->time += 86400;
        $this->expectException(InvalidTokenException::class);
        $service->verify($encoded);
    }

    public function testWeakKeyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JwtService($this->clock(), 'issuer', 'audience', ['key' => 'short'], 'key');
    }

    public function testRealClockIssuesIntegerClaimsThatVerify(): void
    {
        $clock = new \Semhoun\SlimSession\Clock\SystemClock();
        $service = $this->service($clock);
        $token = $service->issue('real-clock-session', $clock->now()->getTimestamp() + 100);
        self::assertSame('real-clock-session', $service->verify($token)->sessionId);
    }

    public function testTokenSizeLimitIsAppliedBeforeParsing(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->service()->verify(str_repeat('a', 4097) . '.a.a');
    }

    public function testCannotIssueAtAbsoluteDeadline(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->service()->issue('session', 1700000000);
    }

    public function testMalformedKeyRingFailsAtConfigurationBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JwtService($this->clock(), 'issuer', 'audience', ['key' => ['invalid']], 'key');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTokens')]
    public function testRejectsForgedMalformedAndCrossApplicationTokens(array $header, array $overrides, string $key): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->service()->verify($this->signed($header, $overrides, $key));
    }

    public static function invalidTokens(): array
    {
        $header = ['alg' => 'HS256', 'kid' => 'current'];
        return [
            'wrong signature' => [$header, [], str_repeat('z', 32)],
            'none' => [['alg' => 'none', 'kid' => 'current'], [], str_repeat('a', 32)],
            'hs512' => [['alg' => 'HS512', 'kid' => 'current'], [], str_repeat('a', 32)],
            'unknown key' => [['alg' => 'HS256', 'kid' => 'missing'], [], str_repeat('a', 32)],
            'remote header' => [$header + ['jku' => 'https://invalid'], [], str_repeat('a', 32)],
            'issuer' => [$header, ['iss' => 'other-app'], str_repeat('a', 32)],
            'audience' => [$header, ['aud' => ['audience-a', 'other']], str_repeat('a', 32)],
            'application data' => [$header, ['data' => ['admin' => true]], str_repeat('a', 32)],
            'string date' => [$header, ['exp' => '1700000100'], str_repeat('a', 32)],
            'float date' => [$header, ['iat' => 1700000000.5], str_repeat('a', 32)],
            'future issued' => [$header, ['iat' => 1700000001], str_repeat('a', 32)],
            'future nbf' => [$header, ['nbf' => 1700000001], str_repeat('a', 32)],
            'expired' => [$header, ['exp' => 1700000000], str_repeat('a', 32)],
            'overlong lifetime' => [$header, ['exp' => 1700086401], str_repeat('a', 32)],
            'invalid subject' => [$header, ['sub' => []], str_repeat('a', 32)],
        ];
    }

    private function signed(array $header, array $overrides = [], string $key = ''): string
    {
        $key = $key === '' ? str_repeat('a', 32) : $key;
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $payload = ['sub' => 'id', 'iss' => 'application-a', 'aud' => ['audience-a'], 'iat' => 1700000000, 'nbf' => 1700000000, 'exp' => 1700000100];
        $unsigned = $encode(json_encode($header, JSON_THROW_ON_ERROR)) . '.' . $encode(json_encode(array_replace($payload, $overrides), JSON_THROW_ON_ERROR));
        return $unsigned . '.' . $encode(hash_hmac('sha256', $unsigned, $key, true));
    }
}
