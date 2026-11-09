<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Tests\Storage;

use PDO;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Semhoun\SlimSession\Exception\InvalidSessionDataException;
use Semhoun\SlimSession\Exception\StorageUnavailableException;
use Semhoun\SlimSession\Storage\PdoCache;
use Semhoun\SlimSession\Storage\PdoSessionStore;
use Semhoun\SlimSession\Storage\RecordCodec;
use Semhoun\SlimSession\Clock\SystemClock;
use Semhoun\SlimSession\SessionConfig;
use Semhoun\SlimSession\SessionManager;

final class StorageSafetyTest extends TestCase
{
    public function testUnknownSchemaAndMismatchedIdentityFailClosed(): void
    {
        $record = BackendContractTest::record('record');
        $fields = get_object_vars($record);
        $fields['schemaVersion'] = 999;
        foreach ([
            fn () => RecordCodec::decode(json_encode($fields, JSON_THROW_ON_ERROR)),
            fn () => RecordCodec::decode(RecordCodec::encode($record), 'different'),
            fn () => RecordCodec::decode('{}'),
        ] as $operation) {
            try {
                $operation();
                self::fail('Corrupt stored record was accepted.');
            } catch (StorageUnavailableException) {
                self::assertTrue(true);
            }
        }
    }

    public function testObjectsCannotEnterSessionSerialization(): void
    {
        $record = BackendContractTest::record('record', data: ['object' => new \stdClass()]);
        $this->expectException(InvalidSessionDataException::class);
        RecordCodec::encode($record);
    }

    #[DataProviderExternal(BackendFactory::class, 'pdoBackends')]
    public function testCleanupIsBoundedAndNamespaceScoped(string $backend): void
    {
        $connection = BackendFactory::connection($backend);
        self::assertInstanceOf(PDO::class, $connection);
        $namespace = 'cleanup_' . bin2hex(random_bytes(8));
        $store = new PdoSessionStore($connection);
        $cache = new PdoCache($connection, $namespace, str_repeat('k', 32));
        $payload = RecordCodec::encode(BackendContractTest::record('record'));
        for ($i = 0; $i < 3; ++$i) {
            foreach ([$namespace, $namespace . '_other'] as $application) {
                $connection->prepare('INSERT INTO slim_session_records VALUES (?,?,?,?,?)')->execute([$application, 'record' . $i, 0, $payload, 0]);
                $connection->prepare('INSERT INTO slim_session_leases VALUES (?,?,?,?)')->execute([$application, 'record' . $i, 'expired', 0]);
                $connection->prepare('INSERT INTO slim_session_cache VALUES (?,?,?,?)')->execute([$application, 'key' . $i, 'expired', 0]);
            }
        }
        self::assertSame(2, $store->cleanup($namespace, 1));
        self::assertSame(1, $cache->cleanup(1));
        foreach (['records', 'leases', 'cache'] as $table) {
            $statement = $connection->prepare("SELECT COUNT(*) FROM slim_session_$table WHERE namespace=?");
            $statement->execute([$namespace]);
            self::assertSame(2, (int) $statement->fetchColumn());
            $statement->execute([$namespace . '_other']);
            self::assertSame(3, (int) $statement->fetchColumn());
        }
    }

    public function testBusinessTransactionIsNeverCommittedOrRolledBackByStore(): void
    {
        $connection = BackendFactory::connection('sqlite');
        self::assertInstanceOf(PDO::class, $connection);
        $store = new PdoSessionStore($connection);
        $connection->beginTransaction();
        try {
            try {
                $store->acquire('application', 'record', 'owner', 1000, 0);
                self::fail('Storage accepted an application transaction.');
            } catch (StorageUnavailableException) {
                self::assertTrue($connection->inTransaction());
            }
        } finally {
            $connection->rollBack();
        }
    }

    #[DataProviderExternal(BackendFactory::class, 'pdoBackends')]
    public function testCreationCleanupPreservesLiveRowsAndOtherNamespaces(string $backend): void
    {
        $connection = BackendFactory::connection($backend);
        $namespace = 'automatic_' . bin2hex(random_bytes(8));
        $store = new PdoSessionStore($connection);
        $payload = RecordCodec::encode(BackendContractTest::record('expired'));
        foreach ([$namespace, $namespace . '_other'] as $application) {
            for ($i = 0; $i < 3; ++$i) {
                $connection->prepare('INSERT INTO slim_session_records VALUES (?,?,?,?,?)')->execute([$application, 'expired' . $i, 0, $payload, 0]);
                $connection->prepare('INSERT INTO slim_session_leases VALUES (?,?,?,?)')->execute([$application, 'expired' . $i, 'expired', 0]);
            }
        }
        $manager = new SessionManager($store, new SessionConfig($namespace, cleanupProbabilityBasisPoints: 10000, cleanupBatchSize: 1), new SystemClock());
        $live = $manager->create(['live' => true]);
        $created = $manager->create(['user' => 1]);
        $created->saveAndClose();
        foreach (['records', 'leases'] as $table) {
            $query = $connection->prepare("SELECT COUNT(*) FROM slim_session_$table WHERE namespace=? AND expires_at=0");
            $query->execute([$namespace]);
            self::assertSame(2, (int) $query->fetchColumn());
            $query->execute([$namespace . '_other']);
            self::assertSame(3, (int) $query->fetchColumn());
        }
        self::assertSame(['user' => 1], $store->read($namespace, $created->record()->id)->data);
        $live->set('still_leased', true);
        $live->saveAndClose();
        self::assertTrue($store->read($namespace, $live->record()->id)->data['still_leased']);
    }

    public function testTablePrefixCannotInjectSqlAndNoSchemaIsInstalledAutomatically(): void
    {
        $connection = new PDO('sqlite::memory:');
        try {
            new PdoSessionStore($connection, 'records; DROP TABLE records;');
            self::fail('Unsafe SQL table prefix was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        $store = new PdoSessionStore($connection);
        self::assertSame(0, (int) $connection->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn());
        $this->expectException(StorageUnavailableException::class);
        $store->read('application', 'record');
    }
}
