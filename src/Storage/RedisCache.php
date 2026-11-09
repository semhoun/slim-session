<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Storage;

use Semhoun\SlimSession\Exception\StorageUnavailableException;

final class RedisCache extends AbstractCache
{
    private readonly string $keyPrefix;

    public function __construct(private readonly \Redis $connection, string $namespace, string $serializationKey, string $prefix = 'slim_session')
    {
        parent::__construct($namespace, $serializationKey);
        PdoInfrastructure::validateNamespace($prefix);
        if ($connection->getOption(\Redis::OPT_SERIALIZER) !== \Redis::SERIALIZER_NONE || $connection->getOption(\Redis::OPT_PREFIX)) {
            throw new CacheInvalidArgumentException('Redis cache requires an unprefixed connection without a serializer.');
        }
        $this->keyPrefix = "$prefix:$namespace:cache:";
    }

    public function clear(): bool
    {
        return $this->operation(function (): bool {
            $cursor = null;
            do {
                $keys = $this->connection->scan($cursor, $this->keyPrefix . '*', 100);
                if (is_array($keys) && $keys !== []) {
                    $this->connection->del($keys);
                }
            } while ($cursor !== 0);
            return true;
        });
    }

    protected function readRaw(string $key): ?string
    {
        return $this->operation(function () use ($key): ?string {
            $payload = $this->connection->get($this->keyPrefix . $key);
            return $payload === false ? null : (string) $payload;
        });
    }

    protected function writeRaw(string $key, string $payload, ?int $ttl): bool
    {
        return $this->operation(fn (): bool => $this->connection->set($this->keyPrefix . $key, $payload, $ttl === null ? [] : ['ex' => $ttl]));
    }

    protected function deleteRaw(string $key): bool
    {
        return $this->operation(function () use ($key): bool {
            $this->connection->del($this->keyPrefix . $key);
            return true;
        });
    }

    private function operation(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $error) {
            throw new StorageUnavailableException('Redis cache operation failed.', 0, $error);
        }
    }
}
