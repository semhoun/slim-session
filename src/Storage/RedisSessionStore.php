<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Storage;

use Semhoun\SlimSession\Exception\LockTimeoutException;
use Semhoun\SlimSession\Exception\StorageUnavailableException;
use Semhoun\SlimSession\Exception\WriteConflictException;
use Semhoun\SlimSession\Lease;
use Semhoun\SlimSession\SessionRecord;

/** Standalone Redis only; Redis Cluster has not been certified. */
final class RedisSessionStore implements SessionStore
{
    private const SCRIPT = <<<'LUA'
local t = redis.call('TIME')
local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
local op, owner = ARGV[1], ARGV[2]
local function liveLease(key)
    return redis.call('HGET', key, 'owner') == owner and tonumber(redis.call('HGET', key, 'expires') or '0') > now
end
local function liveRecord(key)
    return tonumber(redis.call('HGET', key, 'expires') or '0') > now
end
local function writeRecord(key)
    redis.call('HSET', key, 'payload', ARGV[5], 'revision', ARGV[6], 'expires', ARGV[7], 'created', ARGV[8], 'absolute', ARGV[9])
    redis.call('PEXPIREAT', key, ARGV[7])
end
local function writeLease(key, deadline)
    redis.call('HSET', key, 'owner', owner, 'expires', deadline)
    redis.call('PEXPIREAT', key, deadline)
end
if op == 'read' then
    if not liveRecord(KEYS[1]) then return {1, ''} end
    return {1, redis.call('HGET', KEYS[1], 'payload')}
end
if op == 'acquire' then
    if tonumber(redis.call('HGET', KEYS[2], 'expires') or '0') > now then return {0, 'busy'} end
    local deadline = now + tonumber(ARGV[3])
    writeLease(KEYS[2], deadline)
    return {1, tostring(deadline)}
end
if op == 'release' then
    if redis.call('HGET', KEYS[2], 'owner') == owner then redis.call('DEL', KEYS[2]) end
    return {1, ''}
end
if not liveLease(KEYS[2]) then return {0, 'lease'} end
if op == 'renew' then
    local deadline = now + tonumber(ARGV[3])
    writeLease(KEYS[2], deadline)
    return {1, tostring(deadline)}
end
if op == 'create' then
    if redis.call('EXISTS', KEYS[1]) == 1 then return {2, 'collision'} end
    if tonumber(ARGV[7]) <= now then return {0, 'deadline'} end
    if ARGV[6] ~= '0' then return {0, 'revision'} end
    writeRecord(KEYS[1])
    return {1, ''}
end
if not liveRecord(KEYS[1]) or redis.call('HGET', KEYS[1], 'revision') ~= ARGV[4] then return {0, 'record'} end
if op == 'save' or op == 'rotate' then
    if redis.call('HGET', KEYS[1], 'created') ~= ARGV[8] or redis.call('HGET', KEYS[1], 'absolute') ~= ARGV[9] then return {0, 'lifetime'} end
end
if op == 'save' then
    if tonumber(ARGV[7]) <= now then return {0, 'deadline'} end
    writeRecord(KEYS[1])
elseif op == 'revoke' then
    redis.call('DEL', KEYS[1], KEYS[2])
elseif op == 'rotate' then
    if ARGV[6] ~= '0' then return {0, 'revision'} end
    if tonumber(ARGV[7]) <= now then return {0, 'deadline'} end
    if redis.call('EXISTS', KEYS[3]) == 1 or tonumber(redis.call('HGET', KEYS[4], 'expires') or '0') > now then return {0, 'collision'} end
    local deadline = tonumber(redis.call('HGET', KEYS[2], 'expires'))
    writeRecord(KEYS[3])
    writeLease(KEYS[4], deadline)
    redis.call('DEL', KEYS[1], KEYS[2])
    return {1, tostring(deadline)}
else
    return {0, 'operation'}
end
return {1, ''}
LUA;

    public function __construct(private readonly \Redis $connection, private readonly string $prefix = 'slim_session')
    {
        PdoInfrastructure::validateNamespace($prefix);
        // Serializer/prefix options alter keys and Lua argument types; use a dedicated raw connection.
        if ($connection->getOption(\Redis::OPT_SERIALIZER) !== \Redis::SERIALIZER_NONE || $connection->getOption(\Redis::OPT_PREFIX)) {
            throw new \InvalidArgumentException('Redis session storage requires an unprefixed connection without a serializer.');
        }
    }

    public function acquire(string $namespace, string $id, string $owner, int $ttlMilliseconds, int $waitMilliseconds): Lease
    {
        if ($ttlMilliseconds <= 0 || $waitMilliseconds < 0) {
            throw new \InvalidArgumentException('Invalid lease duration.');
        }
        $until = hrtime(true) + $waitMilliseconds * 1_000_000;
        do {
            $result = $this->run('acquire', $namespace, $id, $owner, $ttlMilliseconds);
            if ($result[0] === 1) {
                return new Lease($namespace, $id, $owner, (float) $result[1] / 1000);
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
        $result = $this->run('read', $namespace, $id);
        return $result[1] === '' ? null : RecordCodec::decode((string) $result[1], $id);
    }

    public function create(string $namespace, SessionRecord $record, Lease $lease): bool
    {
        $this->assertIdentity($namespace, $record->id, $lease);
        $result = $this->run('create', $namespace, $record->id, $lease->owner, record: $record);
        if ($result[0] === 2) {
            return false;
        }
        $this->assertSuccess($result);
        return true;
    }

    public function save(string $namespace, SessionRecord $record, int $expectedRevision, Lease $lease): void
    {
        $this->assertIdentity($namespace, $record->id, $lease);
        if ($record->revision !== $expectedRevision + 1) {
            throw new WriteConflictException('Invalid session revision.');
        }
        $this->assertSuccess($this->run('save', $namespace, $record->id, $lease->owner, expectedRevision: $expectedRevision, record: $record));
    }

    public function renewLease(Lease $lease, int $ttlMilliseconds): Lease
    {
        if ($ttlMilliseconds <= 0) {
            throw new \InvalidArgumentException('Invalid lease duration.');
        }
        $result = $this->run('renew', $lease->namespace, $lease->id, $lease->owner, $ttlMilliseconds);
        $this->assertSuccess($result);
        return new Lease($lease->namespace, $lease->id, $lease->owner, (float) $result[1] / 1000);
    }

    public function release(Lease $lease): void
    {
        $this->run('release', $lease->namespace, $lease->id, $lease->owner);
    }

    public function revoke(string $namespace, string $id, int $expectedRevision, Lease $lease): void
    {
        $this->assertIdentity($namespace, $id, $lease);
        $this->assertSuccess($this->run('revoke', $namespace, $id, $lease->owner, expectedRevision: $expectedRevision));
    }

    public function rotate(string $namespace, string $oldId, int $expectedRevision, SessionRecord $newRecord, Lease $lease): Lease
    {
        $this->assertIdentity($namespace, $oldId, $lease);
        if ($oldId === $newRecord->id) {
            throw new WriteConflictException('Rotation requires a new identifier.');
        }
        $result = $this->run('rotate', $namespace, $oldId, $lease->owner, expectedRevision: $expectedRevision, record: $newRecord, newId: $newRecord->id);
        $this->assertSuccess($result);
        return new Lease($namespace, $newRecord->id, $lease->owner, (float) $result[1] / 1000);
    }

    /** @return array{int, mixed} */
    private function run(string $operation, string $namespace, string $id, string $owner = '', int $ttl = 0, int $expectedRevision = 0, ?SessionRecord $record = null, ?string $newId = null): array
    {
        PdoInfrastructure::validateNamespace($namespace);
        foreach (array_filter([$id, $owner, $newId], static fn ($value) => $value !== null && $value !== '') as $value) {
            if (!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $value)) {
                throw new \InvalidArgumentException('Invalid session storage identifier.');
            }
        }
        if ($id === '' || ($owner === '' && $operation !== 'read')) {
            throw new \InvalidArgumentException('Empty session storage identifier.');
        }
        $base = $this->prefix . ':' . $namespace;
        $keys = ["$base:session:$id", "$base:lease:$id", "$base:session:" . ($newId ?? $id), "$base:lease:" . ($newId ?? $id)];
        $args = [$operation, $owner, (string) $ttl, (string) $expectedRevision, $record === null ? '' : RecordCodec::encode($record), (string) ($record === null ? 0 : $record->revision), (string) ($record === null ? 0 : min($record->idleExpiresAt, $record->absoluteExpiresAt) * 1000), (string) ($record === null ? 0 : $record->createdAt), (string) ($record === null ? 0 : $record->absoluteExpiresAt)];
        try {
            $result = $this->connection->eval(self::SCRIPT, [...$keys, ...$args], 4);
            if (!is_array($result) || count($result) !== 2) {
                throw new \RuntimeException('Invalid Redis script result.');
            }
            return $result;
        } catch (\Throwable $error) {
            throw new StorageUnavailableException('Redis session storage operation failed.', 0, $error);
        }
    }

    private function assertIdentity(string $namespace, string $id, Lease $lease): void
    {
        if ($lease->namespace !== $namespace || $lease->id !== $id || $lease->owner === '') {
            throw new WriteConflictException('Lease does not match the session.');
        }
    }

    /** @param array{int, mixed} $result */
    private function assertSuccess(array $result): void
    {
        if ($result[0] !== 1) {
            throw new WriteConflictException('Session lease, revision, existence, or deadline check failed.');
        }
    }
}
