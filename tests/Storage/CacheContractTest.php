<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Tests\Storage;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\InvalidArgumentException;
use Semhoun\SlimSession\Exception\StorageUnavailableException;

final class CacheContractTest extends TestCase
{
    #[DataProviderExternal(BackendFactory::class, 'backends')]
    public function testTypesTtlsBulkAndNamespaceIsolation(string $backend): void
    {
        $connection = BackendFactory::connection($backend);
        $namespace = 'cache_' . bin2hex(random_bytes(8));
        $cache = BackendFactory::cache($connection, $namespace);
        $other = BackendFactory::cache($connection, $namespace . '_other');
        $values = ['null' => null, 'false' => false, 'integer' => 42, 'float' => 1.25, 'string' => "a\0b", 'array' => ['nested' => [false, null]], 'object' => (object) ['x' => 1], 'date' => new \DateTimeImmutable('2026-01-01T00:00:00Z')];
        try {
            self::assertTrue($cache->setMultiple($values));
            foreach ($values as $key => $value) {
                self::assertTrue($cache->has($key));
                self::assertEquals($value, $cache->get($key, 'missing'));
                self::assertSame(get_debug_type($value), get_debug_type($cache->get($key)));
            }
            self::assertSame(['missing' => 'default', 'null' => null], $cache->getMultiple(['missing', 'null'], 'default'));
            self::assertTrue($cache->set('zero', 1, 0));
            self::assertFalse($cache->has('zero'));
            self::assertTrue($cache->set('negative', 1, -1));
            self::assertFalse($cache->has('negative'));
            self::assertTrue($cache->set('interval', 1, new \DateInterval('PT1S')));
            self::assertTrue($cache->set('integer-ttl', 1, 1));
            $negative = new \DateInterval('PT1S');
            $negative->invert = 1;
            self::assertTrue($cache->set('negative-interval', 1, $negative));
            self::assertFalse($cache->has('negative-interval'));
            self::assertTrue($cache->set(str_repeat('a', 64), 'supported'));
            self::assertTrue($other->set('integer', 'other'));
            $store = BackendFactory::store($connection);
            $lease = $store->acquire($namespace, 'integer', 'owner', 5000, 0);
            self::assertTrue($store->create($namespace, BackendContractTest::record('integer'), $lease));
            self::assertTrue($cache->clear());
            self::assertFalse($cache->has('integer'));
            self::assertSame('other', $other->get('integer'));
            self::assertNotNull($store->read($namespace, 'integer'));
            $store->save($namespace, BackendContractTest::record('integer', 1), 0, $lease);
            $store->revoke($namespace, 'integer', 1, $lease);
            $store->release($lease);
            self::assertTrue($cache->set('interval', 1, new \DateInterval('PT1S')));
            self::assertTrue($cache->set('permanent', 1, null));
            usleep(1_100_000);
            self::assertFalse($cache->has('interval'));
            self::assertSame(1, $cache->get('permanent'));
            self::assertTrue($cache->deleteMultiple(['permanent', 'absent']));
        } finally {
            $cache->clear();
            $other->clear();
        }
    }

    #[DataProviderExternal(BackendFactory::class, 'backends')]
    public function testReservedKeysAreRejectedBeforeBulkWrites(string $backend): void
    {
        $cache = BackendFactory::cache(BackendFactory::connection($backend), 'invalid_' . bin2hex(random_bytes(8)));
        foreach (['', 'a{b', 'a}b', 'a(b', 'a)b', 'a/b', 'a\\b', 'a@b', 'a:b'] as $key) {
            try {
                $cache->setMultiple(['valid' => true, $key => 'invalid']);
                self::fail('Invalid key accepted.');
            } catch (InvalidArgumentException) {
                self::assertFalse($cache->has('valid'));
            }
        }
    }

    #[DataProviderExternal(BackendFactory::class, 'backends')]
    public function testUnauthenticatedPayloadNeverDeserializes(string $backend): void
    {
        $connection = BackendFactory::connection($backend);
        $ns = 'auth_' . bin2hex(random_bytes(8));
        $cache = BackendFactory::cache($connection, $ns);
        $differentKey = BackendFactory::cache($connection, $ns, str_repeat('different-key', 3));
        $cache->set('object', (object) ['x' => 1]);
        try {
            $differentKey->get('object');
            self::fail('Unauthenticated serialized payload was accepted.');
        } catch (StorageUnavailableException) {
            self::assertTrue(true);
        } finally {
            $cache->clear();
        }
    }
}
