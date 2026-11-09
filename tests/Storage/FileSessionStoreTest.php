<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Semhoun\SlimSession\Exception\LockTimeoutException;
use Semhoun\SlimSession\Exception\StorageUnavailableException;
use Semhoun\SlimSession\Exception\WriteConflictException;
use Semhoun\SlimSession\Lease;
use Semhoun\SlimSession\Storage\FileSessionStore;
use Semhoun\SlimSession\Storage\RecordCodec;

final class FileSessionStoreTest extends TestCase
{
    private string $directory;
    private string $namespaceDirectory;
    private FileSessionStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/file-store-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->namespaceDirectory = $this->directory . '/' . hash('sha256', 'test');
        $this->store = new FileSessionStore($this->directory, lockTimeoutMilliseconds: 40);
        $this->store->read('test', 'record');
    }

    protected function tearDown(): void
    {
        chmod($this->directory, 0700);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); }
            else { unlink($file->getPathname()); }
        }
        rmdir($this->directory);
    }

    private function fixture(string $name, string $payload): void
    {
        file_put_contents($this->namespaceDirectory . '/' . $name, $payload);
        chmod($this->namespaceDirectory . '/' . $name, 0600);
    }

    public function testPrivateJsonRecordsAndIndependentAbsentLease(): void
    {
        $lease = $this->store->acquire('test', 'record', 'owner', 5000, 0);
        self::assertNull($this->store->read('test', 'record'));
        $this->store->create('test', BackendContractTest::record('record'), $lease);
        self::assertSame(0600, fileperms($this->namespaceDirectory . '/record.json') & 0777);
        self::assertSame(0700, fileperms($this->namespaceDirectory) & 0777);
        self::assertSame('record', RecordCodec::decode(file_get_contents($this->namespaceDirectory . '/record.json'))->id);
        $inode = fileinode($this->namespaceDirectory . '/lock');
        $this->store->release($lease);
        $this->store->read('test', 'record');
        self::assertSame($inode, fileinode($this->namespaceDirectory . '/lock'));
    }

    public function testUnsafePathsAndIdentitiesAreRejected(): void
    {
        foreach (['../escape', 'a/b', '', "a\0b", str_repeat('x', 129)] as $id) {
            try { $this->store->read('test', $id); self::fail('Unsafe ID accepted.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        try { $this->store->read('../escape', 'record'); self::fail('Unsafe namespace accepted.'); }
        catch (\InvalidArgumentException) { self::assertTrue(true); }
        $this->expectException(StorageUnavailableException::class);
        new FileSessionStore($this->directory . '/../' . basename($this->directory));
    }

    public function testUnwritableDirectoryFailsEvenUnderRoot(): void
    {
        chmod($this->directory, 0500);
        $this->expectException(StorageUnavailableException::class);
        $this->store->read('test', 'record');
    }

    public function testCorruptRecordAndLeaseFailClosed(): void
    {
        foreach (['record.json', 'record.lease', 'rotation'] as $name) {
            $this->fixture($name, '{broken');
            try {
                if ($name === 'record.lease') { $this->store->acquire('test', 'record', 'owner', 1000, 0); }
                else { $this->store->read('test', 'record'); }
                self::fail('Corruption accepted.');
            } catch (StorageUnavailableException) { self::assertTrue(true); }
            unlink($this->namespaceDirectory . '/' . $name);
        }
    }

    public function testSymlinksAreNeverReadOverwrittenOrDeleted(): void
    {
        $target = $this->directory . '/untouched';
        file_put_contents($target, 'sentinel');
        chmod($target, 0600);
        foreach (['record.json', 'record.lease', 'rotation', 'lock'] as $name) {
            $path = $this->namespaceDirectory . '/' . $name;
            if (file_exists($path)) { unlink($path); }
            symlink($target, $path);
            try {
                if ($name === 'record.lease') { $this->store->acquire('test', 'record', 'owner', 1000, 0); }
                else { $this->store->read('test', 'record'); }
                self::fail('Symlink accepted.');
            } catch (StorageUnavailableException) { self::assertTrue(true); }
            self::assertTrue(is_link($path));
            self::assertSame('sentinel', file_get_contents($target));
            unlink($path);
        }
        $other = $this->directory . '/' . hash('sha256', 'other');
        symlink($this->namespaceDirectory, $other);
        $this->expectException(StorageUnavailableException::class);
        $this->store->read('other', 'record');
    }

    public function testRotationRecoveryAtEveryInterruptedStep(): void
    {
        for ($step = 0; $step <= 4; ++$step) {
            $old = $this->store->acquire('test', 'old', 'owner', 5000, 0);
            $this->store->create('test', BackendContractTest::record('old'), $old);
            $record = RecordCodec::encode(BackendContractTest::record('new'));
            $lease = json_encode(get_object_vars(new Lease('test', 'new', 'owner', $old->expiresAt)), JSON_THROW_ON_ERROR);
            $this->fixture('rotation', json_encode(['old' => 'old', 'record' => $record, 'lease' => $lease], JSON_THROW_ON_ERROR));
            if ($step >= 1) { unlink($this->namespaceDirectory . '/old.json'); }
            if ($step >= 2) { unlink($this->namespaceDirectory . '/old.lease'); }
            if ($step >= 3) { $this->fixture('new.json', $record); }
            if ($step >= 4) { $this->fixture('new.lease', $lease); }
            $reopened = new FileSessionStore($this->directory);
            self::assertNull($reopened->read('test', 'old'));
            self::assertNotNull($reopened->read('test', 'new'));
            self::assertFileDoesNotExist($this->namespaceDirectory . '/rotation');
            $current = $reopened->renewLease(new Lease('test', 'new', 'owner', 0), 5000);
            $reopened->revoke('test', 'new', 0, $current);
            $reopened->release($current);
        }
    }

    public function testPartialRotationFailureBlocksAllAccessUntilRecovery(): void
    {
        $old = $this->store->acquire('test', 'old', 'owner', 5000, 0);
        $this->store->create('test', BackendContractTest::record('old'), $old);
        $record = RecordCodec::encode(BackendContractTest::record('new'));
        $lease = json_encode(get_object_vars(new Lease('test', 'new', 'owner', $old->expiresAt)), JSON_THROW_ON_ERROR);
        $this->fixture('rotation', json_encode(['old' => 'old', 'record' => $record, 'lease' => $lease], JSON_THROW_ON_ERROR));
        mkdir($this->namespaceDirectory . '/new.lease', 0700);
        foreach (['old', 'new', 'unrelated'] as $id) {
            try { $this->store->read('test', $id); self::fail('Incomplete recovery exposed storage.'); }
            catch (StorageUnavailableException) { self::assertTrue(true); }
        }
        self::assertFileExists($this->namespaceDirectory . '/rotation');
        rmdir($this->namespaceDirectory . '/new.lease');
        self::assertNull($this->store->read('test', 'old'));
        self::assertNotNull($this->store->read('test', 'new'));
        $this->expectException(WriteConflictException::class);
        $this->store->save('test', BackendContractTest::record('old', 1), 0, $old);
    }

    public function testPhysicalLockWaitIsBoundedAcrossProcesses(): void
    {
        if (!function_exists('pcntl_fork')) { self::markTestSkipped('Requires pcntl.'); }
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            $handle = fopen($this->namespaceDirectory . '/lock', 'r+b');
            flock($handle, LOCK_EX);
            fwrite($sockets[1], 'L');
            stream_set_timeout($sockets[1], 5);
            fread($sockets[1], 1);
            fclose($handle);
            fclose($sockets[1]);
            exit(0);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 5);
        try {
            self::assertSame('L', fread($sockets[0], 1));
            foreach ([fn () => $this->store->acquire('test', 'record', 'owner', 1000, 30), fn () => $this->store->read('test', 'record')] as $operation) {
                $start = hrtime(true);
                try { $operation(); self::fail('Locked namespace accepted.'); }
                catch (LockTimeoutException) { self::assertLessThan(0.5, (hrtime(true) - $start) / 1e9); }
            }
        } finally {
            fwrite($sockets[0], 'R');
            fclose($sockets[0]);
            pcntl_waitpid($pid, $status);
        }
        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertNull($this->store->read('test', 'record'));
    }

    public function testCleanupIsBoundedIsolatedAndPreservesLiveRecordsAndLeases(): void
    {
        $clock = new \Semhoun\SlimSession\Tests\Core\CoreTestClock((float) time());
        $store = new FileSessionStore($this->directory, $clock);
        foreach (['expired1', 'expired2', 'leased', 'live'] as $id) {
            $lease = $store->acquire('test', $id, 'owner', $id === 'leased' ? 5000 : 1000, 0);
            $store->create('test', BackendContractTest::record($id, idle: (int) $clock->time + ($id === 'live' ? 60 : 1)), $lease);
        }
        $other = $store->acquire('other', 'expired', 'owner', 1000, 0);
        $store->create('other', BackendContractTest::record('expired', idle: (int) $clock->time + 1), $other);
        $clock->time += 1;
        self::assertLessThanOrEqual(2, $store->cleanup('test', 1));
        $store->cleanup('test', 100);
        self::assertNotNull($store->read('test', 'live'));
        self::assertFileExists($this->namespaceDirectory . '/leased.json');
        self::assertFileExists($this->namespaceDirectory . '/leased.lease');
        self::assertFileDoesNotExist($this->namespaceDirectory . '/expired1.json');
        self::assertFileDoesNotExist($this->namespaceDirectory . '/expired2.json');
        self::assertFileExists($this->directory . '/' . hash('sha256', 'other') . '/expired.json');
        $clock->time += 4;
        self::assertSame(2, $store->cleanup('test'));
        self::assertFileExists($this->namespaceDirectory . '/lock');
        $this->expectException(\InvalidArgumentException::class);
        $store->cleanup('test', 0);
    }

    public function testExistingProbabilisticCleanupConfigurationWorks(): void
    {
        $clock = new \Semhoun\SlimSession\Tests\Core\CoreTestClock((float) time());
        $store = new FileSessionStore($this->directory, $clock);
        $lease = $store->acquire('test', 'expired', 'owner', 1000, 0);
        $store->create('test', BackendContractTest::record('expired', idle: (int) $clock->time + 1), $lease);
        $clock->time += 2;
        $manager = new \Semhoun\SlimSession\SessionManager($store, new \Semhoun\SlimSession\SessionConfig('test', cleanupProbabilityBasisPoints: 10000), $clock);
        $session = $manager->create(['user' => 1]);
        self::assertFileExists($this->namespaceDirectory . '/expired.json');
        $session->saveAndClose();
        self::assertFileDoesNotExist($this->namespaceDirectory . '/expired.json');
        self::assertFileDoesNotExist($this->namespaceDirectory . '/expired.lease');
    }

    public function testAuthoritativeLeaseDeadlineRejectsForgedFutureDeadline(): void
    {
        $clock = new \Semhoun\SlimSession\Tests\Core\CoreTestClock((float) time());
        $store = new FileSessionStore($this->directory, $clock);
        $lease = $store->acquire('test', 'record', 'owner', 1000, 0);
        $store->create('test', BackendContractTest::record('record'), $lease);
        $clock->time += 1;
        $this->expectException(WriteConflictException::class);
        $store->save('test', BackendContractTest::record('record', 1), 0, new Lease('test', 'record', 'owner', $clock->time + 9999));
    }

    public function testInvalidReplacementCannotPublishUnrecoverableJournal(): void
    {
        $lease = $this->store->acquire('test', 'old', 'owner', 5000, 0);
        $this->store->create('test', BackendContractTest::record('old'), $lease);
        $fields = get_object_vars(BackendContractTest::record('new'));
        $fields['verificationSecret'] = 'invalid';
        try {
            $this->store->rotate('test', 'old', 0, new \Semhoun\SlimSession\SessionRecord(...$fields), $lease);
            self::fail('Invalid record accepted.');
        } catch (StorageUnavailableException) { self::assertTrue(true); }
        self::assertFileDoesNotExist($this->namespaceDirectory . '/rotation');
        self::assertNotNull($this->store->read('test', 'old'));
        self::assertNull($this->store->read('test', 'new'));
    }

    public function testPublicPermissionsAndHardLinksFailClosed(): void
    {
        $this->fixture('record.json', RecordCodec::encode(BackendContractTest::record('record')));
        chmod($this->namespaceDirectory . '/record.json', 0644);
        try { $this->store->read('test', 'record'); self::fail('Public record accepted.'); }
        catch (StorageUnavailableException) { self::assertTrue(true); }
        chmod($this->namespaceDirectory . '/record.json', 0600);
        link($this->namespaceDirectory . '/record.json', $this->directory . '/hardlink');
        $this->expectException(StorageUnavailableException::class);
        $this->store->read('test', 'record');
    }
}
