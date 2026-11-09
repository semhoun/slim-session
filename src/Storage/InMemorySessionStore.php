<?php
declare(strict_types=1);
namespace Semhoun\SlimSession\Storage;

use Psr\Clock\ClockInterface;
use Semhoun\SlimSession\Exception\LockTimeoutException;
use Semhoun\SlimSession\Exception\WriteConflictException;
use Semhoun\SlimSession\Lease;
use Semhoun\SlimSession\SessionRecord;

/** Single-process test store, not a production backend. */
final class InMemorySessionStore implements SessionStore
{
    /** @var array<string, SessionRecord> */
    private array $records = [];
    /** @var array<string, Lease> */
    private array $leases = [];

    public function __construct(private readonly ClockInterface $clock) {}

    public function acquire(string $namespace, string $id, string $owner, int $ttlMilliseconds, int $waitMilliseconds): Lease
    {
        if ($ttlMilliseconds < 1 || $waitMilliseconds < 0 || $owner === '') { throw new \InvalidArgumentException('Invalid lease configuration.'); }
        $key = $this->key($namespace, $id);
        $deadline = hrtime(true) + $waitMilliseconds * 1000000;
        do {
            $now = $this->now();
            if (!isset($this->leases[$key]) || $this->leases[$key]->expiresAt <= $now) {
                return $this->leases[$key] = new Lease($namespace, $id, $owner, $now + $ttlMilliseconds / 1000);
            }
            if (hrtime(true) >= $deadline) { break; }
            usleep(1000);
        } while (true);
        throw new LockTimeoutException('Session lease acquisition timed out.');
    }

    public function read(string $namespace, string $id): ?SessionRecord
    {
        $record = $this->records[$this->key($namespace, $id)] ?? null;
        return $record !== null && !$record->isExpired((int) floor($this->now())) ? $record : null;
    }

    public function create(string $namespace, SessionRecord $record, Lease $lease): bool
    {
        $this->assertLease($namespace, $record->id, $lease);
        $key = $this->key($namespace, $record->id);
        if (isset($this->records[$key])) { return false; }
        if ($record->revision !== 0 || $record->isExpired((int) floor($this->now()))) { throw new WriteConflictException('Invalid new session.'); }
        $this->records[$key] = $record;
        return true;
    }

    public function save(string $namespace, SessionRecord $record, int $expectedRevision, Lease $lease): void
    {
        $old = $this->assertRecord($namespace, $record->id, $expectedRevision, $lease);
        if ($record->revision !== $expectedRevision + 1 || $record->absoluteExpiresAt !== $old->absoluteExpiresAt || $record->createdAt !== $old->createdAt || $record->isExpired((int) floor($this->now()))) {
            throw new WriteConflictException('Invalid session update.');
        }
        $this->records[$this->key($namespace, $record->id)] = $record;
    }

    public function renewLease(Lease $lease, int $ttlMilliseconds): Lease
    {
        if ($ttlMilliseconds < 1) { throw new \InvalidArgumentException('Invalid lease duration.'); }
        $this->assertLease($lease->namespace, $lease->id, $lease);
        return $this->leases[$this->key($lease->namespace, $lease->id)] = new Lease($lease->namespace, $lease->id, $lease->owner, $this->now() + $ttlMilliseconds / 1000);
    }

    public function release(Lease $lease): void
    {
        $key = $this->key($lease->namespace, $lease->id);
        if (($this->leases[$key]->owner ?? null) === $lease->owner) { unset($this->leases[$key]); }
    }

    public function revoke(string $namespace, string $id, int $expectedRevision, Lease $lease): void
    {
        $this->assertRecord($namespace, $id, $expectedRevision, $lease);
        unset($this->records[$this->key($namespace, $id)]);
    }

    public function rotate(string $namespace, string $oldId, int $expectedRevision, SessionRecord $newRecord, Lease $lease): Lease
    {
        $old = $this->assertRecord($namespace, $oldId, $expectedRevision, $lease);
        $newKey = $this->key($namespace, $newRecord->id);
        if (isset($this->records[$newKey]) || (isset($this->leases[$newKey]) && $this->leases[$newKey]->expiresAt > $this->now()) || $newRecord->revision !== 0 || $newRecord->absoluteExpiresAt !== $old->absoluteExpiresAt || $newRecord->createdAt !== $old->createdAt || $newRecord->isExpired((int) floor($this->now()))) {
            throw new WriteConflictException('Session rotation conflicted.');
        }
        $newLease = new Lease($namespace, $newRecord->id, $lease->owner, $this->leases[$this->key($namespace, $oldId)]->expiresAt);
        unset($this->records[$this->key($namespace, $oldId)], $this->leases[$this->key($namespace, $oldId)]);
        $this->records[$newKey] = $newRecord;
        $this->leases[$newKey] = $newLease;
        return $newLease;
    }

    private function assertLease(string $namespace, string $id, Lease $lease): void
    {
        $current = $this->leases[$this->key($namespace, $id)] ?? null;
        if ($lease->namespace !== $namespace || $lease->id !== $id || $current === null || $current->owner !== $lease->owner || $current->expiresAt <= $this->now()) {
            throw new WriteConflictException('Session lease was lost.');
        }
    }

    private function assertRecord(string $namespace, string $id, int $revision, Lease $lease): SessionRecord
    {
        $this->assertLease($namespace, $id, $lease);
        $record = $this->records[$this->key($namespace, $id)] ?? null;
        if ($record === null || $record->revision !== $revision || $record->isExpired((int) floor($this->now()))) {
            throw new WriteConflictException('Session is absent, expired, or changed.');
        }
        return $record;
    }

    private function key(string $namespace, string $id): string { return strlen($namespace) . ':' . $namespace . ':' . $id; }
    private function now(): float { return (float) $this->clock->now()->format('U.u'); }
}
