<?php
declare(strict_types=1);

namespace Semhoun\SlimSession;

final readonly class SessionRecord
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $flash
     */
    public function __construct(
        public string $id,
        public int $revision,
        public array $data,
        public array $flash,
        public int $createdAt,
        public int $lastActivityAt,
        public int $idleExpiresAt,
        public int $absoluteExpiresAt,
        public string $csrfToken,
        public int $schemaVersion = 1,
    ) {}

    public function isExpired(int $now): bool
    {
        return $now >= $this->idleExpiresAt || $now >= $this->absoluteExpiresAt;
    }
}
