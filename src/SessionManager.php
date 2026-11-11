<?php
declare(strict_types=1);
namespace Semhoun\SlimSession;

use Psr\Clock\ClockInterface;
use Semhoun\SlimSession\Exception\SessionNotFoundException;
use Semhoun\SlimSession\Random\RandomSource;
use Semhoun\SlimSession\Random\SecureRandom;
use Semhoun\SlimSession\Storage\SessionStore;

final readonly class SessionManager
{
    public function __construct(
        private SessionStore $store,
        private SessionConfig $config,
        private ClockInterface $clock,
        private RandomSource $random = new SecureRandom(),
    ) {}

    public function absent(): SessionContext
    {
        return new SessionContext($this->store, $this->config, $this->clock, $this->random);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data = [], ?int $absoluteExpiresAt = null): SessionContext
    {
        $context = $this->absent();
        $context->create($data, $absoluteExpiresAt);
        return $context;
    }

    public function load(string $id, bool $activity = true): SessionContext
    {
        if (!SessionId::valid($id)) {
            throw new SessionNotFoundException('Session is unavailable.');
        }
        $lease = $this->store->acquire($this->config->namespace, $id, bin2hex($this->random->bytes(32)), $this->config->leaseMilliseconds, $this->config->acquisitionTimeoutMilliseconds);
        try {
            // Reload only after acquisition; an earlier read could be stale after waiting.
            $record = $this->store->read($this->config->namespace, $id);
            if ($record === null || $record->id !== $id || $record->schemaVersion !== 2
                || !preg_match('/\A[a-f0-9]{64}\z/D', $record->verificationSecret)
                || $record->isExpired($this->clock->now()->getTimestamp())) {
                throw new SessionNotFoundException('Session is unavailable.');
            }
            return new SessionContext($this->store, $this->config, $this->clock, $this->random, $record, $lease, $activity);
        } catch (\Throwable $e) {
            $this->store->release($lease);
            throw $e;
        }
    }
}
