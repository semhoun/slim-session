<?php
declare(strict_types=1);

namespace Semhoun\SlimSession;

final readonly class Lease
{
    public function __construct(
        public string $namespace,
        public string $id,
        public string $owner,
        public float $expiresAt,
    ) {}
}
