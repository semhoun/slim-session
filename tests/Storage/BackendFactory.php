<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Tests\Storage;

use PDO;
use Psr\SimpleCache\CacheInterface;
use Semhoun\SlimSession\Storage\PdoCache;
use Semhoun\SlimSession\Storage\PdoSessionStore;
use Semhoun\SlimSession\Storage\RedisCache;
use Semhoun\SlimSession\Storage\RedisSessionStore;
use Semhoun\SlimSession\Storage\SessionStore;

final class BackendFactory
{
    private static array $installed = [];

    public static function backends(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        foreach (['pgsql', 'mysql', 'mariadb'] as $driver) {
            if (getenv('SESSION_TEST_' . strtoupper($driver) . '_DSN')) {
                yield $driver => [$driver];
            }
        }
        if (getenv('SESSION_TEST_REDIS_HOST')) {
            yield 'redis' => ['redis'];
        }
    }

    public static function connection(string $backend, ?string $sqliteFile = null): PDO|\Redis
    {
        if ($backend === 'redis') {
            if (!extension_loaded('redis')) {
                throw new \RuntimeException('Configured Redis tests require ext-redis.');
            }
            $redis = new \Redis();
            $redis->connect((string) getenv('SESSION_TEST_REDIS_HOST'), (int) (getenv('SESSION_TEST_REDIS_PORT') ?: 6379), 2);
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, 2);
            return $redis;
        }
        if ($backend === 'sqlite') {
            $pdo = new PDO($sqliteFile === null ? 'sqlite::memory:' : 'sqlite:' . $sqliteFile);
        } else {
            $env = 'SESSION_TEST_' . strtoupper($backend);
            $pdo = new PDO((string) getenv($env . '_DSN'), (string) getenv($env . '_USER'), (string) getenv($env . '_PASSWORD'));
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $key = $backend . ':' . ($sqliteFile ?? 'memory');
        if (($backend === 'sqlite' && $sqliteFile === null) || !isset(self::$installed[$key])) {
            $schema = file_get_contents(dirname(__DIR__, 2) . '/schemas/' . $backend . '.sql');
            if ($schema === false) {
                throw new \RuntimeException('Test schema is missing.');
            }
            $schema = str_replace(['CREATE TABLE ', 'CREATE INDEX '], ['CREATE TABLE IF NOT EXISTS ', 'CREATE INDEX IF NOT EXISTS '], $schema);
            $pdo->exec($schema);
            self::$installed[$key] = true;
        }
        return $pdo;
    }

    public static function pdoBackends(): iterable
    {
        foreach (self::backends() as $name => $arguments) {
            if ($name !== 'redis') {
                yield $name => $arguments;
            }
        }
    }

    public static function store(PDO|\Redis $connection): SessionStore
    {
        return $connection instanceof PDO ? new PdoSessionStore($connection) : new RedisSessionStore($connection);
    }

    public static function cache(PDO|\Redis $connection, string $namespace, string $secret = 'test-only-authentication-key-32-bytes'): CacheInterface
    {
        return $connection instanceof PDO ? new PdoCache($connection, $namespace, $secret) : new RedisCache($connection, $namespace, $secret);
    }
}
