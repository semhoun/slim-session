<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Tests\Storage;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Semhoun\SlimSession\Exception\LockTimeoutException;
use Semhoun\SlimSession\Exception\WriteConflictException;
use Semhoun\SlimSession\Lease;
use Semhoun\SlimSession\SessionRecord;
use Semhoun\SlimSession\Storage\PdoSessionStore;

final class BackendContractTest extends TestCase
{
    public static function record(string $id, int $revision = 0, array $data = [], ?int $idle = null): SessionRecord
    {
        static $now = null;
        $now ??= time();
        return new SessionRecord($id, $revision, $data, ['notice' => 'hello'], $now, $now, $idle ?? $now + 60, $now + 300, 'csrf-test');
    }

    #[DataProviderExternal(BackendFactory::class, 'backends')]
    public function testCreateSaveRotationRevokeAndNamespaces(string $backend): void
    {
        $store = BackendFactory::store(BackendFactory::connection($backend));
        $ns = 'contract_' . bin2hex(random_bytes(8));
        $lease = $store->acquire($ns, 'original', 'owner', 5000, 0);
        try {
            self::assertTrue($store->create($ns, self::record('original'), $lease));
            self::assertFalse($store->create($ns, self::record('original'), $lease));
            self::assertNull($store->read($ns . '_other', 'original'));
            $store->save($ns, self::record('original', 1, ['counter' => 1]), 0, $lease);
            self::assertSame(['counter' => 1], $store->read($ns, 'original')?->data);
            $lease = $store->rotate($ns, 'original', 1, self::record('rotated', 0, ['counter' => 1]), $lease);
            self::assertNull($store->read($ns, 'original'));
            self::assertSame(['counter' => 1], $store->read($ns, 'rotated')?->data);
            $store->revoke($ns, 'rotated', 0, $lease);
            self::assertNull($store->read($ns, 'rotated'));
        } finally {
            $store->release($lease);
        }
    }

    #[DataProviderExternal(BackendFactory::class, 'backends')]
    public function testStaleOwnerCannotSaveRenewOrReleaseReplacement(string $backend): void
    {
        $store = BackendFactory::store(BackendFactory::connection($backend));
        $ns = 'stale_' . bin2hex(random_bytes(8));
        $stale = $store->acquire($ns, 'record', 'first', 100, 0);
        self::assertTrue($store->create($ns, self::record('record'), $stale));
        usleep(150_000);
        $current = $store->acquire($ns, 'record', 'second', 5000, 500);
        try {
            $store->release($stale);
            foreach ([
                fn () => $store->save($ns, self::record('record', 1), 0, $stale),
                fn () => $store->renewLease($stale, 1000),
                fn () => $store->revoke($ns, 'record', 0, $stale),
                fn () => $store->rotate($ns, 'record', 0, self::record('other'), $stale),
            ] as $operation) {
                $this->conflict($operation);
            }
            $store->save($ns, self::record('record', 1, ['live' => true]), 0, $current);
            $current = $store->renewLease($current, 5000);
            self::assertSame(['live' => true], $store->read($ns, 'record')?->data);
            $store->revoke($ns, 'record', 1, $current);
            $this->conflict(fn () => $store->save($ns, self::record('record', 2), 1, $current));
        } finally {
            $store->release($current);
        }
    }

    #[DataProviderExternal(BackendFactory::class, 'backends')]
    public function testRevisionLeaseIdentityAndCollisionChecks(string $backend): void
    {
        $store = BackendFactory::store(BackendFactory::connection($backend));
        $ns = 'checks_' . bin2hex(random_bytes(8));
        $lease = $store->acquire($ns, 'record', 'first', 5000, 0);
        $collision = $store->acquire($ns, 'collision', 'second', 5000, 0);
        try {
            self::assertTrue($store->create($ns, self::record('record'), $lease));
            self::assertTrue($store->create($ns, self::record('collision'), $collision));
            $this->conflict(fn () => $store->save($ns, self::record('record', 2), 1, $lease));
            $this->conflict(fn () => $store->save($ns . '_other', self::record('record', 1), 0, $lease));
            $this->conflict(fn () => $store->rotate($ns, 'record', 0, self::record('collision'), $lease));
            self::assertNotNull($store->read($ns, 'record'));
            self::assertNotNull($store->read($ns, 'collision'));
            $store->save($ns, self::record('collision', 1), 0, $collision);
            try {
                $store->acquire($ns, 'record', 'second', 1000, 30);
                self::fail('Lease should be exclusive.');
            } catch (LockTimeoutException) {
                self::assertTrue(true);
            }
        } finally {
            $store->release($lease);
            $store->release($collision);
        }
    }

    #[DataProviderExternal(BackendFactory::class, 'backends')]
    public function testStorageClockRejectsExpiredOriginalDespiteExtendedReplacement(string $backend): void
    {
        $connection = BackendFactory::connection($backend);
        $store = BackendFactory::store($connection);
        $ns = 'expiry_' . bin2hex(random_bytes(8));
        $lease = $store->acquire($ns, 'record', 'owner', 5000, 0);
        try {
            self::assertTrue($store->create($ns, self::record('record', idle: time() + 1), $lease));
            usleep(1_100_000);
            self::assertNull($store->read($ns, 'record'));
            $this->conflict(fn () => $store->save($ns, self::record('record', 1, idle: time() + 60), 0, $lease));
            $this->conflict(fn () => $store->rotate($ns, 'record', 0, self::record('new'), $lease));
            self::assertNull($store->read($ns, 'new'));
            // A future caller-provided lease deadline never extends the authoritative backend lease.
            $forged = new Lease($ns, 'record', 'owner', microtime(true) + 1_000_000);
            $store->release($lease);
            $this->conflict(fn () => $store->create($ns, self::record('record'), $forged));
            if ($store instanceof PdoSessionStore) {
                self::assertGreaterThanOrEqual(0, $store->cleanup($ns, 1));
            }
        } finally {
            $store->release($lease);
        }
    }

    #[DataProviderExternal(BackendFactory::class, 'backends')]
    public function testImmutableLifetimeAndLeaseOnlyRotationCollision(string $backend): void
    {
        $store = BackendFactory::store(BackendFactory::connection($backend));
        $ns = 'immutable_' . bin2hex(random_bytes(8));
        $lease = $store->acquire($ns, 'record', 'owner', 5000, 0);
        $other = $store->acquire($ns, 'reserved', 'other', 5000, 0);
        try {
            $this->conflict(fn () => $store->create($ns, self::record('record', 1), $lease));
            self::assertTrue($store->create($ns, self::record('record', data: ['float' => 1.0]), $lease));
            self::assertSame(1.0, $store->read($ns, 'record')?->data['float']);
            foreach (['createdAt' => -1, 'absoluteExpiresAt' => 100] as $field => $delta) {
                $fields = get_object_vars(self::record('record', 1));
                $fields[$field] += $delta;
                $replacement = new SessionRecord(...$fields);
                $this->conflict(fn () => $store->save($ns, $replacement, 0, $lease));
                $fields['id'] = 'new';
                $fields['revision'] = 0;
                $rotated = new SessionRecord(...$fields);
                $this->conflict(fn () => $store->rotate($ns, 'record', 0, $rotated, $lease));
                self::assertNull($store->read($ns, 'new'));
            }
            $this->conflict(fn () => $store->rotate($ns, 'record', 0, self::record('reserved'), $lease));
            self::assertNull($store->read($ns, 'reserved'));
            $other = $store->renewLease($other, 5000);
            self::assertNotNull($store->read($ns, 'record'));
            $store->revoke($ns, 'record', 0, $lease);
        } finally {
            $store->release($lease);
            $store->release($other);
        }
    }

    #[DataProviderExternal(BackendFactory::class, 'backends')]
    public function testMissingRecordCannotBeSavedEvenWithCurrentLease(string $backend): void
    {
        $store = BackendFactory::store(BackendFactory::connection($backend));
        $ns = 'missing_' . bin2hex(random_bytes(8));
        $lease = $store->acquire($ns, 'record', 'owner', 5000, 0);
        try {
            $this->conflict(fn () => $store->save($ns, self::record('record', 1), 0, $lease));
            $this->conflict(fn () => $store->rotate($ns, 'record', 0, self::record('new'), $lease));
            self::assertNull($store->read($ns, 'new'));
            self::assertNull($store->read($ns, 'record'));
        } finally {
            $store->release($lease);
        }
    }

    private function conflict(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected conditional storage rejection.');
        } catch (WriteConflictException) {
            self::assertTrue(true);
        }
    }
}
