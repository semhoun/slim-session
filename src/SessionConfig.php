<?php
declare(strict_types=1);
namespace Semhoun\SlimSession;

final readonly class SessionConfig
{
    public function __construct(
        public string $namespace,
        public int $idleLifetime = 86400,
        public int $absoluteLifetime = 604800,
        public int $leaseMilliseconds = 30000,
        public int $acquisitionTimeoutMilliseconds = 2000,
        public int $maxDataBytes = 65536,
        public int $maxDataDepth = 32,
    ) {
        if ($namespace === '' || strlen($namespace) > 128 || !preg_match('/^[a-zA-Z0-9_.-]+$/D', $namespace)
            || min($idleLifetime, $absoluteLifetime, $leaseMilliseconds, $maxDataBytes, $maxDataDepth) < 1
            || $acquisitionTimeoutMilliseconds < 0) {
            throw new \InvalidArgumentException('Invalid session configuration.');
        }
    }
}
