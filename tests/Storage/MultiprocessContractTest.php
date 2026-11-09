<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Tests\Storage;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Semhoun\SlimSession\Exception\LockTimeoutException;
use Semhoun\SlimSession\Exception\WriteConflictException;

final class MultiprocessContractTest extends TestCase
{
    #[DataProviderExternal(BackendFactory::class, 'pdoBackends')]
    public function testPhysicalDatabaseLockCannotBlockAcquisitionIndefinitely(string $backend): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('Multiprocess contracts require pcntl.');
        }
        $file = tempnam(sys_get_temp_dir(), 'session-db-lock-');
        self::assertNotFalse($file);
        $ns = 'db_lock_' . bin2hex(random_bytes(8));
        $setup = BackendFactory::connection($backend, $file);
        self::assertInstanceOf(\PDO::class, $setup);
        $setup->prepare('INSERT INTO slim_session_leases VALUES (?,?,?,?)')->execute([$ns, 'record', 'expired', 0]);
        unset($setup);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        foreach ($sockets as $socket) {
            stream_set_timeout($socket, 8);
        }
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            try {
                if (fread($sockets[1], 1) !== 'G') {
                    throw new \RuntimeException('Parent synchronization failed.');
                }
                $store = BackendFactory::store(BackendFactory::connection($backend, $file));
                $start = hrtime(true);
                try {
                    $store->acquire($ns, 'record', 'child', 1000, 60);
                    throw new \RuntimeException('Acquired a physically locked lease.');
                } catch (LockTimeoutException) {
                    fwrite($sockets[1], json_encode(['seconds' => (hrtime(true) - $start) / 1_000_000_000], JSON_THROW_ON_ERROR));
                }
                fclose($sockets[1]);
                exit(0);
            } catch (\Throwable $error) {
                fwrite($sockets[1], json_encode(['error' => $error::class . ': ' . $error->getMessage()]));
                fclose($sockets[1]);
                exit(1);
            }
        }
        fclose($sockets[1]);
        $connection = BackendFactory::connection($backend, $file);
        self::assertInstanceOf(\PDO::class, $connection);
        if ($backend === 'sqlite') {
            $connection->exec('BEGIN IMMEDIATE');
        } else {
            $connection->beginTransaction();
        }
        try {
            $connection->prepare('UPDATE slim_session_leases SET expires_at=0 WHERE namespace=? AND id=?')->execute([$ns, 'record']);
            fwrite($sockets[0], 'G');
            $result = stream_get_contents($sockets[0]);
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status), (string) $result);
            $decoded = json_decode((string) $result, true, flags: JSON_THROW_ON_ERROR);
            self::assertLessThan(1.5, $decoded['seconds']);
        } finally {
            if ($backend === 'sqlite') {
                $connection->exec('ROLLBACK');
            } else {
                $connection->rollBack();
            }
            fclose($sockets[0]);
            unlink($file);
        }
    }

    #[DataProviderExternal(BackendFactory::class, 'backends')]
    public function testWaiterReloadsThenRotatesAndRevokesWithoutResurrection(string $backend): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('Multiprocess contracts require pcntl.');
        }
        $file = tempnam(sys_get_temp_dir(), 'session-contract-');
        self::assertNotFalse($file);
        $ns = 'process_' . bin2hex(random_bytes(8));
        $setup = BackendFactory::store(BackendFactory::connection($backend, $file));
        $initial = $setup->acquire($ns, 'record', 'setup', 5000, 0);
        $setup->create($ns, BackendContractTest::record('record'), $initial);
        $setup->release($initial);
        unset($setup);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        foreach ($sockets as $socket) {
            stream_set_timeout($socket, 8);
        }
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            try {
                if (fread($sockets[1], 1) !== 'G') {
                    throw new \RuntimeException('Parent synchronization failed.');
                }
                $store = BackendFactory::store(BackendFactory::connection($backend, $file));
                $before = $store->read($ns, 'record')?->revision;
                try {
                    $store->acquire($ns, 'record', 'child', 5000, 0);
                    throw new \RuntimeException('Lease was not exclusive across processes.');
                } catch (LockTimeoutException) {
                    fwrite($sockets[1], 'R');
                }
                $lease = $store->acquire($ns, 'record', 'child', 5000, 3000);
                $after = $store->read($ns, 'record')?->revision;
                $store->save($ns, BackendContractTest::record('record', 2, ['child' => true]), 1, $lease);
                $lease = $store->rotate($ns, 'record', 2, BackendContractTest::record('rotated'), $lease);
                $store->revoke($ns, 'rotated', 0, $lease);
                $store->release($lease);
                fwrite($sockets[1], json_encode(['before' => $before, 'after' => $after], JSON_THROW_ON_ERROR));
                fclose($sockets[1]);
                exit(0);
            } catch (\Throwable $error) {
                fwrite($sockets[1], json_encode(['error' => $error::class . ': ' . $error->getMessage()]));
                fclose($sockets[1]);
                exit(1);
            }
        }
        fclose($sockets[1]);
        $store = BackendFactory::store(BackendFactory::connection($backend, $file));
        $lease = $store->acquire($ns, 'record', 'parent', 5000, 0);
        try {
            fwrite($sockets[0], 'G');
            self::assertSame('R', fread($sockets[0], 1), 'Child must observe parent lease contention.');
            $store->save($ns, BackendContractTest::record('record', 1, ['parent' => true]), 0, $lease);
            $store->release($lease);
            $result = stream_get_contents($sockets[0]);
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status), (string) $result);
            self::assertSame(['before' => 0, 'after' => 1], json_decode((string) $result, true, flags: JSON_THROW_ON_ERROR));
            self::assertNull($store->read($ns, 'record'));
            self::assertNull($store->read($ns, 'rotated'));
            try {
                $store->save($ns, BackendContractTest::record('record', 2), 1, $lease);
                self::fail('Revocation allowed stale writer resurrection.');
            } catch (WriteConflictException) {
                self::assertTrue(true);
            }
        } finally {
            $store->release($lease);
            fclose($sockets[0]);
            unlink($file);
        }
    }

    #[DataProviderExternal(BackendFactory::class, 'backends')]
    public function testExpiredOwnerCannotReleaseOrWriteAfterAnotherProcessTakesOver(string $backend): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('Multiprocess contracts require pcntl.');
        }
        $file = tempnam(sys_get_temp_dir(), 'session-expiry-');
        self::assertNotFalse($file);
        $ns = 'takeover_' . bin2hex(random_bytes(8));
        $setup = BackendFactory::store(BackendFactory::connection($backend, $file));
        $initial = $setup->acquire($ns, 'record', 'setup', 5000, 0);
        $setup->create($ns, BackendContractTest::record('record'), $initial);
        $setup->release($initial);
        unset($setup);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        foreach ($sockets as $socket) {
            stream_set_timeout($socket, 8);
        }
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            try {
                if (fread($sockets[1], 1) !== 'G') {
                    throw new \RuntimeException('Parent synchronization failed.');
                }
                $store = BackendFactory::store(BackendFactory::connection($backend, $file));
                $current = $store->acquire($ns, 'record', 'child', 5000, 3000);
                fwrite($sockets[1], 'L');
                if (fread($sockets[1], 1) !== 'S') {
                    throw new \RuntimeException('Parent stale-write synchronization failed.');
                }
                $store->save($ns, BackendContractTest::record('record', 1, ['child' => true]), 0, $current);
                $store->revoke($ns, 'record', 1, $current);
                $store->release($current);
                fclose($sockets[1]);
                exit(0);
            } catch (\Throwable $error) {
                fwrite($sockets[1], $error::class . ': ' . $error->getMessage());
                fclose($sockets[1]);
                exit(1);
            }
        }
        fclose($sockets[1]);
        $store = BackendFactory::store(BackendFactory::connection($backend, $file));
        $stale = $store->acquire($ns, 'record', 'parent', 100, 0);
        try {
            fwrite($sockets[0], 'G');
            self::assertSame('L', fread($sockets[0], 1));
            $store->release($stale);
            try {
                $store->save($ns, BackendContractTest::record('record', 1), 0, $stale);
                self::fail('Stale process writer was accepted.');
            } catch (WriteConflictException) {
                self::assertTrue(true);
            }
            fwrite($sockets[0], 'S');
            $result = stream_get_contents($sockets[0]);
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status), (string) $result);
            self::assertNull($store->read($ns, 'record'));
        } finally {
            $store->release($stale);
            fclose($sockets[0]);
            unlink($file);
        }
    }
}
