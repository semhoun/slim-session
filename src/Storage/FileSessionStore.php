<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Storage;

use Psr\Clock\ClockInterface;
use Semhoun\SlimSession\Clock\SystemClock;
use Semhoun\SlimSession\Exception\LockTimeoutException;
use Semhoun\SlimSession\Exception\StorageUnavailableException;
use Semhoun\SlimSession\Exception\WriteConflictException;
use Semhoun\SlimSession\Lease;
use Semhoun\SlimSession\SessionRecord;

/** Local, private POSIX filesystem storage; never use on network filesystems. */
final class FileSessionStore implements SessionStore, SessionStoreMaintenance
{
    private readonly string $directory;
    private readonly ClockInterface $clock;

    public function __construct(string $directory, ?ClockInterface $clock = null, private readonly int $lockTimeoutMilliseconds = 2000)
    {
        if ($lockTimeoutMilliseconds < 0 || $directory === '' || str_contains($directory, "\0")) {
            throw new \InvalidArgumentException('Invalid filesystem storage configuration.');
        }
        $real = realpath($directory);
        if ($real === false || rtrim($directory, '/') !== $real) {
            throw new StorageUnavailableException('Storage requires an existing canonical absolute directory.');
        }
        $this->directory = $real;
        $this->checkDirectory($real);
        $this->clock = $clock ?? new SystemClock();
    }

    public function acquire(string $namespace, string $id, string $owner, int $ttlMilliseconds, int $waitMilliseconds): Lease
    {
        $this->identity($id);
        $this->identity($owner);
        if ($ttlMilliseconds < 1 || $waitMilliseconds < 0) { throw new \InvalidArgumentException('Invalid lease duration.'); }
        $until = hrtime(true) + $waitMilliseconds * 1_000_000;
        do {
            $lease = $this->locked($namespace, function (string $dir) use ($namespace, $id, $owner, $ttlMilliseconds): ?Lease {
                $current = $this->lease($dir, $namespace, $id);
                if ($current !== null && $current->expiresAt > $this->now()) { return null; }
                $lease = new Lease($namespace, $id, $owner, $this->now() + $ttlMilliseconds / 1000);
                $this->put($dir, $id . '.lease', $this->encodeLease($lease), function () use ($lease): void {
                    if ($lease->expiresAt <= $this->now()) { throw new LockTimeoutException('Lease expired before acquisition completed.'); }
                });
                return $lease;
            }, $until);
            if ($lease !== null) { return $lease; }
            if (hrtime(true) >= $until) { break; }
            usleep((int) min(1000, max(1, ($until - hrtime(true)) / 1000)));
        } while (true);
        throw new LockTimeoutException('Session lease acquisition timed out.');
    }

    public function read(string $namespace, string $id): ?SessionRecord
    {
        $this->identity($id);
        return $this->locked($namespace, function (string $dir) use ($id): ?SessionRecord {
            $record = $this->record($dir, $id);
            return $record !== null && !$record->isExpired((int) $this->now()) ? $record : null;
        });
    }

    public function create(string $namespace, SessionRecord $record, Lease $lease): bool
    {
        $this->identity($record->id);
        $payload = RecordCodec::encode($record);
        RecordCodec::decode($payload, $record->id);
        return $this->locked($namespace, function (string $dir) use ($namespace, $record, $lease, $payload): bool {
            $this->owned($dir, $namespace, $record->id, $lease);
            $this->validReplacement($record, 0);
            if ($this->record($dir, $record->id) !== null) { return false; }
            $this->put($dir, $record->id . '.json', $payload, function () use ($dir, $namespace, $record, $lease): void {
                $this->owned($dir, $namespace, $record->id, $lease);
                $this->validReplacement($record, 0);
            });
            return true;
        });
    }

    public function save(string $namespace, SessionRecord $record, int $expectedRevision, Lease $lease): void
    {
        $this->identity($record->id);
        $payload = RecordCodec::encode($record);
        RecordCodec::decode($payload, $record->id);
        $this->locked($namespace, function (string $dir) use ($namespace, $record, $expectedRevision, $lease, $payload): void {
            $old = $this->current($dir, $namespace, $record->id, $expectedRevision, $lease);
            $this->validReplacement($record, $expectedRevision + 1, $old);
            $this->put($dir, $record->id . '.json', $payload, function () use ($dir, $namespace, $record, $expectedRevision, $lease): void {
                $old = $this->current($dir, $namespace, $record->id, $expectedRevision, $lease);
                $this->validReplacement($record, $expectedRevision + 1, $old);
            });
        });
    }

    public function renewLease(Lease $lease, int $ttlMilliseconds): Lease
    {
        if ($ttlMilliseconds < 1) { throw new \InvalidArgumentException('Invalid lease duration.'); }
        return $this->locked($lease->namespace, function (string $dir) use ($lease, $ttlMilliseconds): Lease {
            $this->owned($dir, $lease->namespace, $lease->id, $lease);
            $new = new Lease($lease->namespace, $lease->id, $lease->owner, $this->now() + $ttlMilliseconds / 1000);
            $this->put($dir, $lease->id . '.lease', $this->encodeLease($new), function () use ($dir, $lease, $new): void {
                $this->owned($dir, $lease->namespace, $lease->id, $lease);
                if ($new->expiresAt <= $this->now()) { throw new WriteConflictException('Lease deadline passed.'); }
            });
            return $new;
        });
    }

    public function release(Lease $lease): void
    {
        $this->identity($lease->id);
        $this->identity($lease->owner);
        $this->locked($lease->namespace, function (string $dir) use ($lease): void {
            if ($this->lease($dir, $lease->namespace, $lease->id)?->owner === $lease->owner) {
                $this->remove($dir, $lease->id . '.lease');
            }
        });
    }

    public function revoke(string $namespace, string $id, int $expectedRevision, Lease $lease): void
    {
        $this->locked($namespace, function (string $dir) use ($namespace, $id, $expectedRevision, $lease): void {
            $this->current($dir, $namespace, $id, $expectedRevision, $lease);
            // Keeping the lease until release is safe even if the process exits here.
            $this->remove($dir, $id . '.json');
        });
    }

    public function rotate(string $namespace, string $oldId, int $expectedRevision, SessionRecord $newRecord, Lease $lease): Lease
    {
        $this->identity($newRecord->id);
        $payload = RecordCodec::encode($newRecord);
        RecordCodec::decode($payload, $newRecord->id);
        return $this->locked($namespace, function (string $dir) use ($namespace, $oldId, $expectedRevision, $newRecord, $lease, $payload): Lease {
            $old = $this->current($dir, $namespace, $oldId, $expectedRevision, $lease);
            $this->validReplacement($newRecord, 0, $old);
            if ($oldId === $newRecord->id || $this->record($dir, $newRecord->id) !== null
                || ($this->lease($dir, $namespace, $newRecord->id)->expiresAt ?? 0) > $this->now()) {
                throw new WriteConflictException('Session rotation conflicted.');
            }
            $owned = $this->owned($dir, $namespace, $oldId, $lease);
            $newLease = new Lease($namespace, $newRecord->id, $owned->owner, $owned->expiresAt);
            // The published journal is the commit point. Recovery always completes forward.
            $this->put($dir, 'rotation', json_encode(['old' => $oldId, 'record' => $payload, 'lease' => $this->encodeLease($newLease)], JSON_THROW_ON_ERROR), function () use ($dir, $namespace, $oldId, $expectedRevision, $lease, $newRecord): void {
                $old = $this->current($dir, $namespace, $oldId, $expectedRevision, $lease);
                $this->validReplacement($newRecord, 0, $old);
            });
            $this->recover($dir, $namespace);
            return $newLease;
        });
    }

    /** Best-effort sweep: bounded deletions and a 50ms scan budget, excluding filesystem latency. */
    public function cleanup(string $namespace, int $limit = 100): int
    {
        if ($limit < 1 || $limit > 10_000) { throw new \InvalidArgumentException('Cleanup limit must be between 1 and 10000.'); }
        try {
            return $this->locked($namespace, function (string $dir) use ($namespace, $limit): int {
                $records = 0;
                $leases = 0;
                $until = hrtime(true) + 50_000_000;
                foreach (new \DirectoryIterator($dir) as $entry) {
                    if (hrtime(true) >= $until || ($records >= $limit && $leases >= $limit)) { break; }
                    if (!preg_match('/\A([a-zA-Z0-9_-]{1,128})\.(json|lease)\z/D', $entry->getFilename(), $match)) { continue; }
                    $id = $match[1];
                    if ($match[2] === 'json' && $records < $limit) {
                        $record = $this->record($dir, $id);
                        if ($record !== null && $record->isExpired((int) $this->now())
                            && ($this->lease($dir, $namespace, $id)->expiresAt ?? 0) <= $this->now()) {
                            $this->remove($dir, $entry->getFilename());
                            ++$records;
                        }
                    } elseif ($match[2] === 'lease' && $leases < $limit) {
                        $lease = $this->lease($dir, $namespace, $id);
                        if ($lease !== null && $lease->expiresAt <= $this->now()) {
                            $this->remove($dir, $entry->getFilename());
                            ++$leases;
                        }
                    }
                }
                return $records + $leases;
            });
        } catch (LockTimeoutException|\UnexpectedValueException $error) {
            throw new StorageUnavailableException('Filesystem cleanup unavailable.', 0, $error);
        }
    }

    private function validReplacement(SessionRecord $record, int $revision, ?SessionRecord $old = null): void
    {
        if ($record->revision !== $revision || $record->isExpired((int) $this->now())
            || ($old !== null && ($record->createdAt !== $old->createdAt || $record->absoluteExpiresAt !== $old->absoluteExpiresAt))) {
            throw new WriteConflictException('Invalid session replacement.');
        }
    }

    private function current(string $dir, string $namespace, string $id, int $revision, Lease $lease): SessionRecord
    {
        $this->owned($dir, $namespace, $id, $lease);
        $record = $this->record($dir, $id);
        if ($record === null || $record->revision !== $revision || $record->isExpired((int) $this->now())) {
            throw new WriteConflictException('Session is absent, expired, or changed.');
        }
        return $record;
    }

    private function owned(string $dir, string $namespace, string $id, Lease $lease): Lease
    {
        $this->identity($id);
        $this->identity($lease->owner);
        $current = $this->lease($dir, $namespace, $id);
        if ($lease->namespace !== $namespace || $lease->id !== $id || $current === null
            || !hash_equals($current->owner, $lease->owner) || $current->expiresAt <= $this->now()) {
            throw new WriteConflictException('Session lease was lost.');
        }
        return $current;
    }

    private function record(string $dir, string $id): ?SessionRecord
    {
        $this->identity($id);
        $payload = $this->get($dir, $id . '.json');
        return $payload === null ? null : RecordCodec::decode($payload, $id);
    }

    private function lease(string $dir, string $namespace, string $id): ?Lease
    {
        $payload = $this->get($dir, $id . '.lease');
        return $payload === null ? null : $this->decodeLease($payload, $namespace, $id);
    }

    private function encodeLease(Lease $lease): string
    {
        return json_encode(get_object_vars($lease), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function decodeLease(string $payload, string $namespace, string $id): Lease
    {
        $data = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['namespace'] ?? null) !== $namespace || ($data['id'] ?? null) !== $id
            || !is_string($data['owner'] ?? null) || !preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $data['owner'])
            || (!is_float($data['expiresAt'] ?? null) && !is_int($data['expiresAt'] ?? null)) || !is_finite((float) $data['expiresAt'])) {
            throw new StorageUnavailableException('Invalid stored lease.');
        }
        return new Lease($namespace, $id, $data['owner'], (float) $data['expiresAt']);
    }

    private function recover(string $dir, string $namespace): void
    {
        $payload = $this->get($dir, 'rotation');
        if ($payload === null) { return; }
        $data = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_string($data['old'] ?? null) || !is_string($data['record'] ?? null) || !is_string($data['lease'] ?? null)) {
            throw new StorageUnavailableException('Invalid rotation journal.');
        }
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $data['old'])) { throw new StorageUnavailableException('Invalid rotation journal identifier.'); }
        $record = RecordCodec::decode($data['record']);
        $this->decodeLease($data['lease'], $namespace, $record->id);
        if ($data['old'] === $record->id || $record->revision !== 0) { throw new StorageUnavailableException('Invalid rotation journal.'); }
        $this->remove($dir, $data['old'] . '.json');
        $this->remove($dir, $data['old'] . '.lease');
        $this->put($dir, $record->id . '.json', $data['record']);
        $this->put($dir, $record->id . '.lease', $data['lease']);
        $this->remove($dir, 'rotation');
    }

    /**
     * @template T
     * @param callable(string): T $operation
     * @return T
     */
    private function locked(string $namespace, callable $operation, ?float $until = null): mixed
    {
        if (!preg_match('/\A[a-zA-Z0-9_.-]{1,128}\z/D', $namespace)) { throw new \InvalidArgumentException('Invalid storage namespace.'); }
        $until ??= hrtime(true) + $this->lockTimeoutMilliseconds * 1_000_000;
        $this->checkDirectory($this->directory);
        $dir = $this->directory . '/' . hash('sha256', $namespace);
        if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) { throw new StorageUnavailableException('Cannot create namespace directory.'); }
        $this->checkDirectory($dir);
        $path = $dir . '/lock';
        $this->regular($path);
        $mask = umask(0077);
        try { $handle = @fopen($path, 'x+b'); } finally { umask($mask); }
        if ($handle !== false) {
            if (!@chmod($path, 0600)) { fclose($handle); throw new StorageUnavailableException('Cannot secure lock file.'); }
        } else {
            $this->regular($path);
            $handle = @fopen($path, 'r+b');
        }
        if ($handle === false) { throw new StorageUnavailableException('Cannot open namespace lock.'); }
        try {
            $this->regular($path);
            do {
                if (@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                    $this->recover($dir, $namespace);
                    return $operation($dir);
                }
                if ($wouldBlock !== 1) { throw new StorageUnavailableException('Namespace locking failed.'); }
                if (hrtime(true) >= $until) { throw new LockTimeoutException('Namespace lock acquisition timed out.'); }
                usleep((int) min(1000, max(1, ($until - hrtime(true)) / 1000)));
            } while (true);
        } catch (\JsonException $error) {
            throw new StorageUnavailableException('Invalid filesystem storage JSON.', 0, $error);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function checkDirectory(string $path): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 0077) !== 0
            || ($stat['mode'] & 0700) !== 0700 || !is_writable($path)) {
            throw new StorageUnavailableException('Storage directory must be private and writable (0700).');
        }
    }

    private function regular(string $path): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) { return false; }
        if (($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0 || $stat['nlink'] !== 1) {
            throw new StorageUnavailableException('Unsafe storage file.');
        }
        return true;
    }

    private function get(string $dir, string $name): ?string
    {
        if (!$this->regular($dir . '/' . $name)) { return null; }
        $payload = @file_get_contents($dir . '/' . $name);
        if ($payload === false) { throw new StorageUnavailableException('Storage read failed.'); }
        return $payload;
    }

    private function put(string $dir, string $name, string $payload, ?callable $beforeCommit = null): void
    {
        $path = $dir . '/' . $name;
        $this->regular($path);
        $temp = $dir . '/tmp-' . bin2hex(random_bytes(16));
        $mask = umask(0077);
        try { $handle = @fopen($temp, 'x+b'); } finally { umask($mask); }
        if ($handle === false) { throw new StorageUnavailableException('Cannot create storage temporary file.'); }
        try {
            if (!@chmod($temp, 0600) || @fwrite($handle, $payload) !== strlen($payload) || !@fflush($handle) || !@fsync($handle)) {
                throw new StorageUnavailableException('Storage write failed.');
            }
            if ($beforeCommit !== null) { $beforeCommit(); }
            if (!@rename($temp, $path)) { throw new StorageUnavailableException('Storage replacement failed.'); }
        } finally {
            fclose($handle);
            $this->remove($dir, basename($temp));
        }
    }

    private function remove(string $dir, string $name): void
    {
        $path = $dir . '/' . $name;
        if ($this->regular($path) && !@unlink($path)) { throw new StorageUnavailableException('Storage removal failed.'); }
    }

    private function identity(string $id): void
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $id)) { throw new \InvalidArgumentException('Invalid session storage identifier.'); }
    }

    private function now(): float { return (float) $this->clock->now()->format('U.u'); }
}
