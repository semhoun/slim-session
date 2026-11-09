<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Storage;

use Semhoun\SlimSession\Lease;
use Semhoun\SlimSession\SessionRecord;

interface SessionStore
{
    // Acquisition uses a bounded monotonic wait; lease expiry uses storage-authoritative time.
    public function acquire(string $namespace, string $id, string $owner, int $ttlMilliseconds, int $waitMilliseconds): Lease;
    public function read(string $namespace, string $id): ?SessionRecord;
    // Insert only. False denotes an ID collision; a lost lease must throw WriteConflictException.
    public function create(string $namespace, SessionRecord $record, Lease $lease): bool;
    // All mutations atomically check namespace/ID, live owner, original deadlines, and revision.
    public function save(string $namespace, SessionRecord $record, int $expectedRevision, Lease $lease): void;
    public function renewLease(Lease $lease, int $ttlMilliseconds): Lease;
    public function release(Lease $lease): void;
    public function revoke(string $namespace, string $id, int $expectedRevision, Lease $lease): void;
    // Rotation preserves creation/absolute deadlines and atomically transfers the owned lease.
    public function rotate(string $namespace, string $oldId, int $expectedRevision, SessionRecord $newRecord, Lease $lease): Lease;
}
