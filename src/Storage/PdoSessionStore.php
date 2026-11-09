<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Storage;

use PDO;
use Semhoun\SlimSession\Exception\LockTimeoutException;
use Semhoun\SlimSession\Exception\StorageUnavailableException;
use Semhoun\SlimSession\Exception\WriteConflictException;
use Semhoun\SlimSession\Lease;
use Semhoun\SlimSession\SessionRecord;

final class PdoSessionStore implements SessionStore, SessionStoreMaintenance
{
    private readonly PdoInfrastructure $db;

    public function __construct(PDO $connection, string $tablePrefix = 'slim_session_')
    {
        $this->db = new PdoInfrastructure($connection, $tablePrefix);
    }

    public function acquire(string $namespace, string $id, string $owner, int $ttlMilliseconds, int $waitMilliseconds): Lease
    {
        $this->validateIdentity($namespace, $id, $owner);
        if ($ttlMilliseconds <= 0 || $waitMilliseconds < 0) {
            throw new \InvalidArgumentException('Invalid lease duration.');
        }
        $until = hrtime(true) + $waitMilliseconds * 1_000_000;
        do {
            try {
                $lease = $this->db->transaction(function () use ($namespace, $id, $owner, $ttlMilliseconds): ?Lease {
                    $table = $this->db->table('leases');
                    $this->db->execute($this->db->insertIgnore($table, 'namespace,id,owner,expires_at', '?,?,?,?'), [$namespace, $id, '', 0]);
                    $this->lockLeaseRow($namespace, $id, true);
                    $now = $this->db->timeExpression();
                    $updated = $this->db->execute("UPDATE $table SET owner=?, expires_at=$now + ? WHERE namespace=? AND id=? AND expires_at <= $now", [$owner, $ttlMilliseconds / 1000, $namespace, $id]);
                    if ($updated->rowCount() !== 1) {
                        return null;
                    }
                    $expires = $this->db->execute("SELECT expires_at FROM $table WHERE namespace=? AND id=?", [$namespace, $id])->fetchColumn();
                    return new Lease($namespace, $id, $owner, (float) $expires);
                });
            } catch (StorageUnavailableException $error) {
                $previous = $error->getPrevious();
                $isContention = $previous instanceof \PDOException && match ($this->db->driver) {
                    'sqlite' => in_array((int) ($previous->errorInfo[1] ?? 0), [5, 6], true),
                    'mysql' => in_array((int) ($previous->errorInfo[1] ?? 0), [1205, 1213, 3572], true),
                    'pgsql' => in_array((string) ($previous->errorInfo[0] ?? ''), ['55P03', '40P01', '40001'], true),
                    default => false,
                };
                if (!$isContention) {
                    throw $error;
                }
                $lease = null;
            }
            if ($lease !== null) {
                return $lease;
            }
            if (hrtime(true) >= $until) {
                break;
            }
            usleep((int) min(10_000, max(1, ($until - hrtime(true)) / 1000)));
        } while (true);
        throw new LockTimeoutException('Session lease acquisition timed out.');
    }

    public function read(string $namespace, string $id): ?SessionRecord
    {
        $this->validateIdentity($namespace, $id);
        try {
            $now = $this->db->timeExpression();
            $payload = $this->db->execute('SELECT payload FROM ' . $this->db->table('records') . " WHERE namespace=? AND id=? AND expires_at > $now", [$namespace, $id])->fetchColumn();
            return $payload === false ? null : RecordCodec::decode((string) $payload, $id);
        } catch (\PDOException $error) {
            throw new StorageUnavailableException('Session storage read failed.', 0, $error);
        }
    }

    public function create(string $namespace, SessionRecord $record, Lease $lease): bool
    {
        $this->assertIdentity($namespace, $record->id, $lease);
        if ($record->revision !== 0) {
            throw new WriteConflictException('New sessions require revision zero.');
        }
        return $this->db->transaction(function () use ($namespace, $record, $lease): bool {
            $this->assertLease($lease);
            $this->assertRecordDeadline($record);
            $table = $this->db->table('records');
            $result = $this->db->execute($this->db->insertIgnore($table, 'namespace,id,revision,payload,expires_at', '?,?,?,?,?'), [$namespace, $record->id, $record->revision, RecordCodec::encode($record), min($record->idleExpiresAt, $record->absoluteExpiresAt)]);
            $this->assertLease($lease);
            $this->assertRecordDeadline($record);
            return $result->rowCount() === 1;
        });
    }

    public function save(string $namespace, SessionRecord $record, int $expectedRevision, Lease $lease): void
    {
        $this->assertIdentity($namespace, $record->id, $lease);
        if ($record->revision !== $expectedRevision + 1) {
            throw new WriteConflictException('Invalid session revision.');
        }
        $this->db->transaction(function () use ($namespace, $record, $expectedRevision, $lease): void {
            $this->assertLease($lease);
            $originalDeadline = $this->assertCurrentRecord($namespace, $record->id, $expectedRevision, $record);
            $this->assertRecordDeadline($record);
            $now = $this->db->timeExpression();
            $result = $this->db->execute('UPDATE ' . $this->db->table('records') . " SET revision=?,payload=?,expires_at=? WHERE namespace=? AND id=? AND revision=? AND expires_at > $now", [$record->revision, RecordCodec::encode($record), min($record->idleExpiresAt, $record->absoluteExpiresAt), $namespace, $record->id, $expectedRevision]);
            if ($result->rowCount() !== 1) {
                throw new WriteConflictException('Session no longer exists, expired, or changed.');
            }
            $this->assertLease($lease);
            $this->assertDeadline($originalDeadline);
            $this->assertRecordDeadline($record);
        });
    }

    public function renewLease(Lease $lease, int $ttlMilliseconds): Lease
    {
        if ($ttlMilliseconds <= 0) {
            throw new \InvalidArgumentException('Invalid lease duration.');
        }
        return $this->db->transaction(function () use ($lease, $ttlMilliseconds): Lease {
            $this->assertLease($lease);
            $now = $this->db->timeExpression();
            $table = $this->db->table('leases');
            $this->db->execute("UPDATE $table SET expires_at=$now + ? WHERE namespace=? AND id=? AND owner=? AND expires_at > $now", [$ttlMilliseconds / 1000, $lease->namespace, $lease->id, $lease->owner]);
            $this->assertLease($lease);
            $expires = $this->db->execute("SELECT expires_at FROM $table WHERE namespace=? AND id=?", [$lease->namespace, $lease->id])->fetchColumn();
            return new Lease($lease->namespace, $lease->id, $lease->owner, (float) $expires);
        });
    }

    public function release(Lease $lease): void
    {
        $this->validateIdentity($lease->namespace, $lease->id, $lease->owner);
        $this->db->transaction(function () use ($lease): void {
            $this->db->execute('DELETE FROM ' . $this->db->table('leases') . ' WHERE namespace=? AND id=? AND owner=?', [$lease->namespace, $lease->id, $lease->owner]);
        });
    }

    public function revoke(string $namespace, string $id, int $expectedRevision, Lease $lease): void
    {
        $this->assertIdentity($namespace, $id, $lease);
        $this->db->transaction(function () use ($namespace, $id, $expectedRevision, $lease): void {
            $this->assertLease($lease);
            $originalDeadline = $this->assertCurrentRecord($namespace, $id, $expectedRevision);
            $now = $this->db->timeExpression();
            $result = $this->db->execute('DELETE FROM ' . $this->db->table('records') . " WHERE namespace=? AND id=? AND revision=? AND expires_at > $now", [$namespace, $id, $expectedRevision]);
            if ($result->rowCount() !== 1) {
                throw new WriteConflictException('Session no longer exists, expired, or changed.');
            }
            $this->assertLease($lease);
            $this->assertDeadline($originalDeadline);
            $this->db->execute('DELETE FROM ' . $this->db->table('leases') . ' WHERE namespace=? AND id=? AND owner=?', [$namespace, $id, $lease->owner]);
        });
    }

    public function rotate(string $namespace, string $oldId, int $expectedRevision, SessionRecord $newRecord, Lease $lease): Lease
    {
        $this->assertIdentity($namespace, $oldId, $lease);
        $this->validateIdentity($namespace, $newRecord->id);
        if ($newRecord->id === $oldId || $newRecord->revision !== 0) {
            throw new WriteConflictException('Rotation requires a new identifier.');
        }
        return $this->db->transaction(function () use ($namespace, $oldId, $expectedRevision, $newRecord, $lease): Lease {
            $expires = $this->assertLease($lease);
            $this->assertRecordDeadline($newRecord);
            $originalDeadline = $this->assertCurrentRecord($namespace, $oldId, $expectedRevision, $newRecord);
            $records = $this->db->table('records');
            $leases = $this->db->table('leases');
            $now = $this->db->timeExpression();
            $insert = $this->db->execute($this->db->insertIgnore($records, 'namespace,id,revision,payload,expires_at', '?,?,?,?,?'), [$namespace, $newRecord->id, $newRecord->revision, RecordCodec::encode($newRecord), min($newRecord->idleExpiresAt, $newRecord->absoluteExpiresAt)]);
            if ($insert->rowCount() !== 1) {
                throw new WriteConflictException('New session identifier already exists.');
            }
            $this->db->execute($this->db->insertIgnore($leases, 'namespace,id,owner,expires_at', '?,?,?,?'), [$namespace, $newRecord->id, '', 0]);
            $this->lockLeaseRow($namespace, $newRecord->id);
            $transfer = $this->db->execute("UPDATE $leases SET owner=?,expires_at=? WHERE namespace=? AND id=? AND expires_at <= $now", [$lease->owner, $expires, $namespace, $newRecord->id]);
            if ($transfer->rowCount() !== 1) {
                throw new WriteConflictException('New session identifier is leased.');
            }
            $deleted = $this->db->execute("DELETE FROM $records WHERE namespace=? AND id=? AND revision=? AND expires_at > $now", [$namespace, $oldId, $expectedRevision]);
            if ($deleted->rowCount() !== 1) {
                throw new WriteConflictException('Session no longer exists, expired, or changed.');
            }
            $this->assertLease($lease);
            $this->assertRecordDeadline($newRecord);
            $this->assertDeadline($originalDeadline);
            $this->db->execute("DELETE FROM $leases WHERE namespace=? AND id=? AND owner=?", [$namespace, $oldId, $lease->owner]);
            return new Lease($namespace, $newRecord->id, $lease->owner, $expires);
        });
    }

    /** Delete at most $limit expired rows per table in this application namespace. */
    public function cleanup(string $namespace, int $limit = 100): int
    {
        PdoInfrastructure::validateNamespace($namespace);
        if ($limit < 1 || $limit > 10_000) {
            throw new \InvalidArgumentException('Cleanup limit must be between 1 and 10000.');
        }
        return $this->db->transaction(function () use ($namespace, $limit): int {
            $count = 0;
            $now = $this->db->timeExpression();
            foreach (['records', 'leases'] as $suffix) {
                $table = $this->db->table($suffix);
                $ids = $this->db->execute("SELECT id FROM $table WHERE namespace=? AND expires_at <= $now ORDER BY expires_at LIMIT $limit", [$namespace])->fetchAll(PDO::FETCH_COLUMN);
                foreach ($ids as $id) {
                    $count += $this->db->execute("DELETE FROM $table WHERE namespace=? AND id=? AND expires_at <= $now", [$namespace, $id])->rowCount();
                }
            }
            return $count;
        });
    }

    /** @return array<string, mixed>|false */
    private function lockLeaseRow(string $namespace, string $id, bool $nowait = false): array|false
    {
        $suffix = $this->db->driver === 'sqlite' ? '' : ' FOR UPDATE' . ($nowait ? ' NOWAIT' : '');
        return $this->db->execute('SELECT owner,expires_at FROM ' . $this->db->table('leases') . ' WHERE namespace=? AND id=?' . $suffix, [$namespace, $id])->fetch(PDO::FETCH_ASSOC);
    }

    private function assertLease(Lease $lease): float
    {
        $row = $this->lockLeaseRow($lease->namespace, $lease->id);
        if ($row === false || !hash_equals((string) $row['owner'], $lease->owner) || (float) $row['expires_at'] <= $this->db->now()) {
            throw new WriteConflictException('Session lease was lost.');
        }
        return (float) $row['expires_at'];
    }

    private function assertRecordDeadline(SessionRecord $record): void
    {
        $this->assertDeadline(min($record->idleExpiresAt, $record->absoluteExpiresAt));
    }

    private function assertDeadline(float $deadline): void
    {
        if ($deadline <= $this->db->now()) {
            throw new WriteConflictException('Session deadline passed.');
        }
    }

    private function assertCurrentRecord(string $namespace, string $id, int $revision, ?SessionRecord $replacement = null): float
    {
        $suffix = $this->db->driver === 'sqlite' ? '' : ' FOR UPDATE';
        $row = $this->db->execute('SELECT revision,expires_at,payload FROM ' . $this->db->table('records') . ' WHERE namespace=? AND id=?' . $suffix, [$namespace, $id])->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['revision'] !== $revision) {
            throw new WriteConflictException('Session no longer exists or changed.');
        }
        $this->assertDeadline((float) $row['expires_at']);
        if ($replacement !== null) {
            $original = RecordCodec::decode((string) $row['payload'], $id);
            if ($replacement->absoluteExpiresAt !== $original->absoluteExpiresAt || $replacement->createdAt !== $original->createdAt) {
                throw new WriteConflictException('Immutable session lifetime changed.');
            }
        }
        return (float) $row['expires_at'];
    }

    private function assertIdentity(string $namespace, string $id, Lease $lease): void
    {
        $this->validateIdentity($namespace, $id, $lease->owner);
        if ($lease->namespace !== $namespace || $lease->id !== $id) {
            throw new WriteConflictException('Lease does not match the session.');
        }
    }

    private function validateIdentity(string $namespace, string $id, ?string $owner = null): void
    {
        PdoInfrastructure::validateNamespace($namespace);
        foreach ($owner === null ? [$id] : [$id, $owner] as $value) {
            if (!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $value)) {
                throw new \InvalidArgumentException('Invalid session storage identifier.');
            }
        }
    }
}
