<?php
declare(strict_types=1);
namespace Semhoun\SlimSession\Tests\Core;

use PHPUnit\Framework\TestCase;
use Semhoun\SlimSession\Exception\LockTimeoutException;
use Semhoun\SlimSession\Exception\WriteConflictException;
use Semhoun\SlimSession\Lease;
use Semhoun\SlimSession\SessionRecord;
use Semhoun\SlimSession\Storage\InMemorySessionStore;

final class InMemoryStoreTest extends TestCase
{
    private CoreTestClock $clock;
    private InMemorySessionStore $store;
    protected function setUp(): void { $this->clock = new CoreTestClock(); $this->store = new InMemorySessionStore($this->clock); }

    private function record(string $id = 'id', int $revision = 0, int $idle = 1100, int $absolute = 1200): SessionRecord
    {
        return new SessionRecord($id, $revision, [], [], 1000, 1000, $idle, $absolute, 'csrf');
    }

    public function testInsertOnlyRevisionAndNoUpsertAfterRevocation(): void
    {
        $lease = $this->store->acquire('app', 'id', 'owner', 30000, 0);
        self::assertTrue($this->store->create('app', $this->record(), $lease));
        self::assertFalse($this->store->create('app', $this->record(), $lease));
        $this->store->save('app', $this->record(revision: 1), 0, $lease);
        try { $this->store->save('app', $this->record(revision: 1), 0, $lease); self::fail('Revision accepted.'); }
        catch (WriteConflictException) { self::assertSame(1, $this->store->read('app', 'id')->revision); }
        $this->store->revoke('app', 'id', 1, $lease);
        $this->expectException(WriteConflictException::class);
        $this->store->save('app', $this->record(revision: 2), 1, $lease);
    }

    public function testLeaseExclusivityAndStaleRelease(): void
    {
        $old = $this->store->acquire('app', 'id', 'old', 100, 0);
        self::assertEqualsWithDelta(1000.1, $old->expiresAt, 0.000001);
        try { $this->store->acquire('app', 'id', 'new', 100, 0); self::fail('Concurrent lease accepted.'); }
        catch (LockTimeoutException) { self::assertTrue(true); }
        $this->clock->time = 1000.1;
        $new = $this->store->acquire('app', 'id', 'new', 100, 0);
        $this->store->release($old);
        $this->store->create('app', $this->record(), $new);
        self::assertNotNull($this->store->read('app', 'id'));
        $this->expectException(WriteConflictException::class);
        $this->store->renewLease($old, 100);
    }

    public function testNamespaceAndLeaseIdentityIsolation(): void
    {
        $lease = $this->store->acquire('one', 'id', 'owner', 1000, 0);
        $other = $this->store->acquire('two', 'id', 'owner', 1000, 0);
        $this->store->create('one', $this->record(), $lease);
        self::assertNull($this->store->read('two', 'id'));
        try { $this->store->create('two', $this->record(), $lease); self::fail('Wrong namespace accepted.'); }
        catch (WriteConflictException) { self::assertNull($this->store->read('two', 'id')); }
        $this->store->create('two', $this->record(), $other);
        $forged = new Lease('one', 'other', 'owner', 2000);
        $this->expectException(WriteConflictException::class);
        $this->store->save('one', $this->record(revision: 1), 0, $forged);
    }

    public function testRenewalUsesAuthoritativeStoreClock(): void
    {
        $old = $this->store->acquire('app', 'id', 'owner', 1000, 0);
        $this->clock->time = 1000.5;
        $renewed = $this->store->renewLease($old, 1000);
        self::assertSame(1001.5, $renewed->expiresAt);
        $this->clock->time = 1001.2;
        self::assertTrue($this->store->create('app', $this->record(), $old));
        $this->clock->time = 1001.5;
        $this->expectException(WriteConflictException::class);
        $this->store->save('app', $this->record(revision: 1), 0, $renewed);
    }

    public function testOriginalDeadlineCannotBeExtendedAfterExpiry(): void
    {
        $lease = $this->store->acquire('app', 'id', 'owner', 300000, 0);
        $this->store->create('app', $this->record(), $lease);
        $this->clock->time = 1100;
        self::assertNull($this->store->read('app', 'id'));
        $this->expectException(WriteConflictException::class);
        $this->store->save('app', $this->record(revision: 1, idle: 1190), 0, $lease);
    }

    public function testAbsoluteDeadlineImmutable(): void
    {
        $lease = $this->store->acquire('app', 'id', 'owner', 1000, 0);
        $this->store->create('app', $this->record(), $lease);
        $this->expectException(WriteConflictException::class);
        $this->store->save('app', $this->record(revision: 1, absolute: 1300), 0, $lease);
    }

    public function testRotationCollisionAndAtomicLeaseTransfer(): void
    {
        $lease = $this->store->acquire('app', 'id', 'owner', 1000, 0);
        $this->store->create('app', $this->record(), $lease);
        $blocked = $this->store->acquire('app', 'new', 'other', 1000, 0);
        try { $this->store->rotate('app', 'id', 0, $this->record('new'), $lease); self::fail('Lease overwritten.'); }
        catch (WriteConflictException) { self::assertNotNull($this->store->read('app', 'id')); }
        $this->store->release($blocked);
        $new = $this->store->rotate('app', 'id', 0, $this->record('new'), $lease);
        self::assertNull($this->store->read('app', 'id'));
        self::assertNotNull($this->store->read('app', 'new'));
        self::assertSame('owner', $new->owner);
        self::assertSame($lease->expiresAt, $new->expiresAt);
        $this->store->release($lease);
        $this->store->save('app', $this->record('new', 1), 0, $new);
        self::assertSame(1, $this->store->read('app', 'new')->revision);
    }
}
