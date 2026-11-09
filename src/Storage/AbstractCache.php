<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Storage;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use Semhoun\SlimSession\Exception\StorageUnavailableException;

/** Authenticated PHP serialization is separate from strict JSON session records. */
abstract class AbstractCache implements CacheInterface
{
    public function __construct(protected readonly string $namespace, private readonly string $serializationKey)
    {
        PdoInfrastructure::validateNamespace($namespace);
        if (strlen($serializationKey) < 32) {
            throw new CacheInvalidArgumentException('Cache serialization authentication requires at least 32 secret bytes.');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $key = $this->key($key);
        $envelope = $this->readRaw($key);
        if ($envelope === null) {
            return $default;
        }
        $payload = $this->verifyEnvelope($key, $envelope);
        try {
            // Object construction is allowed only after authenticating trusted producer bytes.
            return unserialize($payload, ['allowed_classes' => true]);
        } catch (\Throwable $error) {
            throw new StorageUnavailableException('Invalid authenticated cache payload.', 0, $error);
        }
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $key = $this->key($key);
        $seconds = $this->ttl($ttl);
        if ($seconds !== null && $seconds <= 0) {
            return $this->deleteRaw($key);
        }
        try {
            if (is_resource($value)) {
                throw new CacheInvalidArgumentException('Resources cannot be cached.');
            }
            $payload = serialize($value);
        } catch (\Throwable $error) {
            throw new CacheInvalidArgumentException('Value cannot be serialized.', 0, $error);
        }
        return $this->writeRaw($key, $this->signature($key, $payload) . base64_encode($payload), $seconds);
    }

    public function delete(string $key): bool
    {
        return $this->deleteRaw($this->key($key));
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = $this->keys($keys);
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /** @param iterable<array-key, mixed> $values */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $entries = [];
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new CacheInvalidArgumentException('Cache keys must be strings.');
            }
            $this->key($key);
            $entries[] = [$key, $value];
        }
        $success = true;
        foreach ($entries as [$key, $value]) {
            $success = $this->set($key, $value, $ttl) && $success;
        }
        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($this->keys($keys) as $key) {
            $success = $this->delete($key) && $success;
        }
        return $success;
    }

    public function has(string $key): bool
    {
        $key = $this->key($key);
        $envelope = $this->readRaw($key);
        if ($envelope === null) {
            return false;
        }
        $this->verifyEnvelope($key, $envelope);
        return true;
    }

    private function verifyEnvelope(string $key, string $envelope): string
    {
        $payload = base64_decode(substr($envelope, 64), true);
        if ($payload === false || !hash_equals($this->signature($key, $payload), substr($envelope, 0, 64))) {
            throw new StorageUnavailableException('Cache payload authentication failed.');
        }
        return $payload;
    }

    abstract protected function readRaw(string $key): ?string;
    abstract protected function writeRaw(string $key, string $payload, ?int $ttl): bool;
    abstract protected function deleteRaw(string $key): bool;

    private function key(string $key): string
    {
        if ($key === '' || preg_match('/[{}()\/\\\\@:]/', $key)) {
            throw new CacheInvalidArgumentException('Invalid PSR-16 cache key.');
        }
        return hash('sha256', $key);
    }

    /**
     * @param iterable<mixed> $keys
     * @return list<string>
     */
    private function keys(iterable $keys): array
    {
        $validated = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new CacheInvalidArgumentException('Cache keys must be strings.');
            }
            $this->key($key);
            $validated[] = $key;
        }
        return $validated;
    }

    private function ttl(null|int|DateInterval $ttl): ?int
    {
        if ($ttl instanceof DateInterval) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }
        return $ttl;
    }

    private function signature(string $key, string $payload): string
    {
        return hash_hmac('sha256', $this->namespace . "\0" . $key . "\0" . $payload, $this->serializationKey);
    }
}
