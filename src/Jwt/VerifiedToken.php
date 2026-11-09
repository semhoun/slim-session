<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Jwt;

final readonly class VerifiedToken
{
    public function __construct(public string $sessionId, public int $issuedAt, public int $expiresAt, public string $verificationSecret)
    {
    }
}
