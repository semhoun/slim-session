<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Jwt;

final readonly class IssuedToken
{
    public function __construct(public string $token, public int $expiresAt, public int $refreshAt) {}
}
