<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Storage;

use PDO;
use Semhoun\SlimSession\Exception\StorageUnavailableException;

final class PdoCache extends AbstractCache
{
    private readonly PdoInfrastructure $db;

    public function __construct(PDO $connection, string $namespace, string $serializationKey, string $tablePrefix = 'slim_session_')
    {
        parent::__construct($namespace, $serializationKey);
        $this->db = new PdoInfrastructure($connection, $tablePrefix);
    }

    public function clear(): bool
    {
        return $this->operation(function (): bool {
            $this->db->execute('DELETE FROM ' . $this->db->table('cache') . ' WHERE namespace=?', [$this->namespace]);
            return true;
        });
    }

    public function cleanup(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 10_000) {
            throw new CacheInvalidArgumentException('Cleanup limit must be between 1 and 10000.');
        }
        return $this->db->transaction(function () use ($limit): int {
            $table = $this->db->table('cache');
            $now = $this->db->timeExpression();
            $keys = $this->db->execute("SELECT cache_key FROM $table WHERE namespace=? AND expires_at <= $now ORDER BY expires_at LIMIT $limit", [$this->namespace])->fetchAll(PDO::FETCH_COLUMN);
            $count = 0;
            foreach ($keys as $key) {
                $count += $this->db->execute("DELETE FROM $table WHERE namespace=? AND cache_key=? AND expires_at <= $now", [$this->namespace, $key])->rowCount();
            }
            return $count;
        });
    }

    protected function readRaw(string $key): ?string
    {
        return $this->operation(function () use ($key): ?string {
            $now = $this->db->timeExpression();
            $payload = $this->db->execute('SELECT payload FROM ' . $this->db->table('cache') . " WHERE namespace=? AND cache_key=? AND (expires_at IS NULL OR expires_at > $now)", [$this->namespace, $key])->fetchColumn();
            return $payload === false ? null : (string) $payload;
        });
    }

    protected function writeRaw(string $key, string $payload, ?int $ttl): bool
    {
        return $this->operation(function () use ($key, $payload, $ttl): bool {
            $table = $this->db->table('cache');
            $expires = $ttl === null ? 'NULL' : $this->db->timeExpression() . ' + ?';
            $sql = "INSERT INTO $table (namespace,cache_key,payload,expires_at) VALUES (?,?,?,$expires)";
            $sql .= $this->db->driver === 'mysql'
                ? ' ON DUPLICATE KEY UPDATE payload=VALUES(payload),expires_at=VALUES(expires_at)'
                : ' ON CONFLICT (namespace,cache_key) DO UPDATE SET payload=excluded.payload,expires_at=excluded.expires_at';
            $parameters = [$this->namespace, $key, $payload];
            if ($ttl !== null) {
                $parameters[] = $ttl;
            }
            $this->db->execute($sql, $parameters);
            return true;
        });
    }

    protected function deleteRaw(string $key): bool
    {
        return $this->operation(function () use ($key): bool {
            $this->db->execute('DELETE FROM ' . $this->db->table('cache') . ' WHERE namespace=? AND cache_key=?', [$this->namespace, $key]);
            return true;
        });
    }

    private function operation(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (\PDOException $error) {
            throw new StorageUnavailableException('PDO cache operation failed.', 0, $error);
        }
    }
}
