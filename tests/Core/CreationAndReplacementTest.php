<?php
declare(strict_types=1);
namespace Semhoun\SlimSession\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Semhoun\SlimSession\Exception\InvalidSessionDataException;
use Semhoun\SlimSession\Exception\InvalidSessionStateException;
use Semhoun\SlimSession\Exception\LockTimeoutException;
use Semhoun\SlimSession\Exception\SessionNotFoundException;
use Semhoun\SlimSession\Exception\StorageUnavailableException;
use Semhoun\SlimSession\Exception\WriteConflictException;
use Semhoun\SlimSession\Lease;
use Semhoun\SlimSession\Random\RandomSource;
use Semhoun\SlimSession\SessionConfig;
use Semhoun\SlimSession\SessionManager;
use Semhoun\SlimSession\SessionRecord;
use Semhoun\SlimSession\SessionState;
use Semhoun\SlimSession\Storage\InMemorySessionStore;
use Semhoun\SlimSession\Storage\SessionStore;

final class CreationAndReplacementTest extends TestCase
{
    private CoreTestClock $clock;
    private CreationTestStore $store;
    private SessionManager $manager;

    protected function setUp(): void
    {
        $this->clock = new CoreTestClock();
        $this->store = new CreationTestStore(new InMemorySessionStore($this->clock));
        $this->manager = new SessionManager($this->store, new SessionConfig('app', idleLifetime: 100, absoluteLifetime: 250, acquisitionTimeoutMilliseconds: 0), $this->clock);
    }

    public static function creationBounds(): iterable
    {
        yield 'default' => [null, 1250, 1100];
        yield 'shorter than idle' => [1050, 1050, 1050];
        yield 'longer than idle' => [1200, 1200, 1100];
        yield 'global cap' => [2000, 1250, 1100];
    }

    #[DataProvider('creationBounds')]
    public function testBothCreationApisApplyBound(?int $bound, int $absolute, int $idle): void
    {
        foreach ([false, true] as $throughManager) {
            $context = $throughManager ? $this->manager->create(['user.id' => 2], absoluteExpiresAt: $bound) : $this->manager->absent();
            if (!$throughManager) { $context->create(['user.id' => 2], absoluteExpiresAt: $bound); }
            self::assertSame(1000, $context->record()->createdAt);
            self::assertSame($absolute, $context->record()->absoluteExpiresAt);
            self::assertSame($idle, $context->record()->idleExpiresAt);
            self::assertSame(2, $context->get('user.id'));
            $context->saveAndClose();
            self::assertSame($absolute, $context->pendingResponse()->record->absoluteExpiresAt);
        }
    }

    public function testClockIsReadAfterAcquisitionWithoutExtendingRequestedBound(): void
    {
        $this->store->beforeAcquire = function (): void { $this->clock->time = 1040; };
        $context = $this->manager->create(absoluteExpiresAt: 1050);
        self::assertSame(1040, $context->record()->createdAt);
        self::assertSame(1040, $context->record()->lastActivityAt);
        self::assertSame(1050, $context->record()->idleExpiresAt);
        self::assertSame(1050, $context->record()->absoluteExpiresAt);
        $context->saveAndClose();
    }

    public static function expiredBounds(): iterable
    {
        yield 'past before wait' => [999, 1000];
        yield 'now before wait' => [1000, 1000];
        yield 'expires during wait' => [1020, 1020];
        yield 'past during wait' => [1020, 1030];
    }

    #[DataProvider('expiredBounds')]
    public function testExpiredBoundReleasesLeaseWithoutCreatingRecord(int $bound, int $afterWait): void
    {
        $this->store->beforeAcquire = function () use ($afterWait): void { $this->clock->time = $afterWait; };
        $context = $this->manager->absent();
        try {
            $context->create(absoluteExpiresAt: $bound);
            self::fail('Expired creation accepted.');
        } catch (InvalidSessionStateException) {
            self::assertSame(SessionState::Absent, $context->state());
            self::assertNull($context->record());
            self::assertNull($context->pendingResponse());
            self::assertSame(0, $this->store->creates);
            self::assertCount(1, $this->store->released);
            $this->assertLeasesReleased();
        }
    }

    public function testCollisionRetryRechecksDeadlineAfterEachAcquisition(): void
    {
        $this->store->collisions = 1;
        $this->store->beforeAcquire = function (): void { $this->clock->time += 10; };
        try { $this->manager->create(absoluteExpiresAt: 1020); self::fail('Expired retry accepted.'); }
        catch (InvalidSessionStateException) {
            self::assertSame(1, $this->store->creates);
            self::assertCount(2, $this->store->released);
            $this->assertLeasesReleased();
        }
    }

    public function testRandomFailureAfterAcquisitionReleasesLease(): void
    {
        foreach ([3, 4] as $failAt) {
            $random = new class($failAt) implements RandomSource {
                private int $calls = 0;
                public function __construct(private readonly int $failAt) {}
                public function bytes(int $length): string
                {
                    if (++$this->calls === $this->failAt) { throw new \RuntimeException('Secret generation failed.'); }
                    return random_bytes($length);
                }
            };
            $manager = new SessionManager($this->store, new SessionConfig('app'), $this->clock, $random);
            $context = $manager->absent();
            try { $context->create(absoluteExpiresAt: 1050); self::fail('Random failure ignored.'); }
            catch (\RuntimeException $error) {
                self::assertSame('Secret generation failed.', $error->getMessage());
                self::assertSame(SessionState::Absent, $context->state());
                self::assertNull($context->pendingResponse());
                self::assertNull($context->record());
                self::assertCount($failAt - 2, $this->store->released);
                $this->assertLeasesReleased();
            }
        }
    }

    public function testActivityRenewalAndRotationCannotExtendRequestedDeadline(): void
    {
        $context = $this->manager->create(['profile' => true], 1080);
        $id = $context->record()->id;
        $context->saveAndClose();
        $this->clock->time = 1020;
        $context = $this->manager->load($id);
        $context->renew();
        $context->rotate();
        self::assertSame(1000, $context->record()->createdAt);
        self::assertSame(1080, $context->record()->absoluteExpiresAt);
        self::assertSame(1080, $context->record()->idleExpiresAt);
        self::assertTrue($context->get('profile'));
        $id = $context->record()->id;
        $context->saveAndClose();
        $this->clock->time = 1080;
        $this->expectException(SessionNotFoundException::class);
        $this->manager->load($id);
    }

    public function testStartNewFromAbsentCreatesOnSameContext(): void
    {
        $context = $this->manager->absent();
        $same = $context;
        $context->startNew(['user.id' => 3], absoluteExpiresAt: 1040);
        self::assertSame($same, $context);
        self::assertSame(3, $context->get('user.id'));
        self::assertSame(1040, $context->record()->absoluteExpiresAt);
        $context->saveAndClose();
        self::assertTrue($context->pendingResponse()->issueToken);
    }

    public function testStartNewRevokesAndResetsAllIdentityState(): void
    {
        $context = $this->manager->create(['old' => 'profile'], 1050);
        $same = $context;
        $old = $context->record();
        $context->flash('notice', 'private');
        $context->set('unsaved', true);
        $context->excludeActivity();
        $context->renew();
        $this->clock->time = 1010;
        $context->startNew(['new.id' => 4]);
        $new = $context->record();
        self::assertSame($same, $context);
        self::assertNotSame($old->id, $new->id);
        self::assertNotSame($old->csrfToken, $new->csrfToken);
        self::assertNotSame($old->verificationSecret, $new->verificationSecret);
        self::assertSame(0, $new->revision);
        self::assertSame(1010, $new->createdAt);
        self::assertSame(1010, $new->lastActivityAt);
        self::assertSame(1110, $new->idleExpiresAt);
        self::assertSame(1260, $new->absoluteExpiresAt);
        self::assertSame(['new' => ['id' => 4]], $new->data);
        self::assertSame([], $new->flash);
        self::assertNull($context->consumeFlash('notice'));
        self::assertFalse($context->has('old'));
        self::assertFalse($context->has('unsaved'));
        self::assertNull($this->store->read('app', $old->id));
        self::assertSame($new, $context->pendingResponse()->record);
        self::assertFalse($context->pendingResponse()->destroyed);
        $context->saveAndClose();
        self::assertSame($new->id, $context->pendingResponse()->record->id);
        self::assertTrue($context->pendingResponse()->issueToken);
        $this->assertLeasesReleased();
        $this->expectException(SessionNotFoundException::class);
        $this->manager->load($old->id);
    }

    public function testRepeatedReplacementDoesNotCarryEarlierDeadlineOrData(): void
    {
        $context = $this->manager->create(['old' => true]);
        $context->startNew(['intermediate' => true], 1030);
        $intermediate = $context->record();
        $context->startNew();
        self::assertSame([], $context->record()->data);
        self::assertSame(1250, $context->record()->absoluteExpiresAt);
        self::assertNull($this->store->read('app', $intermediate->id));
        $context->saveAndClose();
    }

    public static function replacementFailures(): iterable
    {
        foreach (['revoke', 'acquire', 'create', 'collision', 'data', 'deadline', 'random', 'release'] as $failure) {
            yield $failure => [$failure];
        }
    }

    #[DataProvider('replacementFailures')]
    public function testReplacementFailureIsTerminalAndCannotIssueOldOrNewCredential(string $failure): void
    {
        $random = new class implements RandomSource {
            public bool $fail = false;
            public function bytes(int $length): string
            {
                if ($this->fail) { throw new \RuntimeException('Random failure.'); }
                return random_bytes($length);
            }
        };
        $manager = new SessionManager($this->store, new SessionConfig('app', acquisitionTimeoutMilliseconds: 0), $this->clock, $random);
        $context = $manager->create(['old' => 'private']);
        $old = $context->record();
        $context->flash('old', 'private');
        $error = new StorageUnavailableException('Injected failure.');
        if ($failure === 'revoke') { $this->store->beforeRevoke = static function () use ($error): never { throw $error; }; }
        if ($failure === 'acquire') { $this->store->beforeAcquire = static function (): never { throw new LockTimeoutException('Injected timeout.'); }; }
        if ($failure === 'create') { $this->store->beforeCreate = static function () use ($error): never { throw $error; }; }
        if ($failure === 'collision') { $this->store->collisions = 5; }
        if ($failure === 'release') { $this->store->failRelease = true; }
        if ($failure === 'random') { $random->fail = true; }
        try {
            $context->startNew($failure === 'data' ? ['bad' => new \stdClass()] : [], $failure === 'deadline' ? 1000 : null);
            self::fail('Replacement failure ignored.');
        } catch (StorageUnavailableException|LockTimeoutException|WriteConflictException|InvalidSessionDataException|InvalidSessionStateException|\RuntimeException) {
            self::assertSame(SessionState::Destroyed, $context->state());
            self::assertNull($context->record());
            self::assertTrue($context->pendingResponse()->destroyed);
            self::assertFalse($context->pendingResponse()->issueToken);
            self::assertNull($context->pendingResponse()->record);
            if ($failure === 'revoke') { self::assertNotNull($this->store->read('app', $old->id)); }
            else { self::assertNull($this->store->read('app', $old->id)); }
            $this->assertLeasesReleased();
            $context->saveAndClose();
            try { $context->startNew(); self::fail('Failed replacement reopened.'); }
            catch (InvalidSessionStateException) { self::assertTrue(true); }
            try { $context->create(); self::fail('Failed replacement recreated.'); }
            catch (InvalidSessionStateException) { self::assertTrue(true); }
        }
    }

    public function testReplacementBoundExpiringDuringAcquisitionLeavesOldIdentityRevoked(): void
    {
        $context = $this->manager->create();
        $old = $context->record();
        $this->store->beforeAcquire = function (): void { $this->clock->time = 1020; };
        try { $context->startNew(absoluteExpiresAt: 1020); self::fail('Expired replacement accepted.'); }
        catch (InvalidSessionStateException) {
            self::assertSame(SessionState::Destroyed, $context->state());
            self::assertNull($this->store->read('app', $old->id));
            self::assertNull($context->record());
            self::assertTrue($context->pendingResponse()->destroyed);
            self::assertCount(2, $this->store->released);
            $this->assertLeasesReleased();
        }
    }

    public function testLostOldLeasePreventsReplacementWithoutCreatingIdentity(): void
    {
        $context = $this->manager->create();
        $old = $context->record();
        $this->clock->time = 1031;
        try { $context->startNew(); self::fail('Lost lease replaced identity.'); }
        catch (WriteConflictException) {
            self::assertSame(1, $this->store->creates);
            self::assertNotNull($this->store->read('app', $old->id));
            self::assertSame(SessionState::Destroyed, $context->state());
            self::assertFalse($context->pendingResponse()->issueToken);
            self::assertNull($context->record());
        }
    }

    public static function savesBeforeFailure(): iterable
    {
        yield 'first save' => [false];
        yield 'later save' => [true];
    }

    #[DataProvider('savesBeforeFailure')]
    public function testFailedSaveAfterReplacementSuppressesCredentialEvenWhenCaught(bool $saveFirst): void
    {
        $context = $this->manager->create();
        $old = $context->record();
        $context->startNew(['new' => true]);
        $new = $context->record();
        if ($saveFirst) { $context->save(); }
        $this->store->beforeSave = static function (): never { throw new StorageUnavailableException('Save failed.'); };
        try { $context->saveAndClose(); self::fail('Save failure ignored.'); }
        catch (StorageUnavailableException) {
            self::assertSame(SessionState::Closed, $context->state());
            self::assertNull($context->record());
            self::assertNull($context->pendingResponse()->record);
            self::assertFalse($context->pendingResponse()->issueToken);
            self::assertTrue($context->pendingResponse()->destroyed);
            self::assertNull($this->store->read('app', $old->id));
            // Creation was committed separately; no rollback is promised.
            self::assertNotNull($this->store->read('app', $new->id));
            $this->assertLeasesReleased();
        }
    }

    public function testClosedAndDestroyedContextsCannotStartNew(): void
    {
        foreach (['saveAndClose', 'discard', 'destroy'] as $close) {
            $context = $this->manager->create();
            $context->$close();
            $pending = $context->pendingResponse();
            try { $context->startNew(['bad' => new \stdClass()]); self::fail('Terminal context reopened.'); }
            catch (InvalidSessionStateException) { self::assertSame($pending, $context->pendingResponse()); }
        }
    }

    public static function failedCreationOperations(): iterable
    {
        foreach (['renewLease', 'rotate'] as $operation) {
            foreach ([false, true] as $replacement) {
                yield $operation . ($replacement ? ' replacement' : ' fresh') => [$operation, $replacement];
            }
        }
    }

    #[DataProvider('failedCreationOperations')]
    public function testLeaseLossCannotEmitFreshCredentialAfterCaughtFailure(string $operation, bool $replacement): void
    {
        $context = $this->manager->create();
        if ($replacement) { $context->startNew(); }
        $new = $context->record();
        $context->set('unsaved', true);
        $this->clock->time += 31;
        try { $context->$operation(); self::fail('Lost lease accepted.'); }
        catch (WriteConflictException) {
            self::assertSame(SessionState::Closed, $context->state());
            self::assertNull($context->record());
            self::assertNull($context->pendingResponse()->record);
            self::assertFalse($context->pendingResponse()->issueToken);
            self::assertTrue($context->pendingResponse()->destroyed);
            self::assertSame([], $this->store->read('app', $new->id)->data);
            $this->assertLeasesReleased();
        }
    }

    public function testRotationPreparationFailureSuppressesReplacementCredential(): void
    {
        $random = new class implements RandomSource {
            public bool $fail = false;
            public function bytes(int $length): string
            {
                if ($this->fail) { throw new \RuntimeException('Rotation random failure.'); }
                return random_bytes($length);
            }
        };
        $manager = new SessionManager($this->store, new SessionConfig('app'), $this->clock, $random);
        $context = $manager->create();
        $context->startNew();
        $random->fail = true;
        try { $context->rotate(); self::fail('Random failure ignored.'); }
        catch (\RuntimeException $error) {
            self::assertSame('Rotation random failure.', $error->getMessage());
            self::assertSame(SessionState::Closed, $context->state());
            self::assertNull($context->record());
            self::assertFalse($context->pendingResponse()->issueToken);
            self::assertTrue($context->pendingResponse()->destroyed);
            $this->assertLeasesReleased();
        }
    }

    public static function operationsAfterCommittedRotation(): iterable
    {
        foreach (['save', 'renewLease', 'rotate'] as $operation) { yield $operation => [$operation]; }
    }

    #[DataProvider('operationsAfterCommittedRotation')]
    public function testLaterFailurePreservesPreviouslyCommittedRotation(string $operation): void
    {
        $context = $this->manager->create(['original' => true], 1080);
        $context->startNew(['replacement' => true], 1090);
        $context->flash('notice', 'committed');
        $context->rotate();
        $committed = $context->record();
        $pending = $context->pendingResponse();
        $context->set('unsaved', true);
        $this->clock->time += 31;
        try { $context->$operation(); self::fail('Lost lease accepted.'); }
        catch (WriteConflictException) {
            self::assertSame(SessionState::Closed, $context->state());
            self::assertSame($committed, $context->record());
            self::assertSame($pending, $context->pendingResponse());
            self::assertTrue($context->pendingResponse()->issueToken);
            self::assertFalse($context->pendingResponse()->destroyed);
            self::assertSame(['replacement' => true], $this->store->read('app', $committed->id)->data);
            self::assertSame(['notice' => 'committed'], $this->store->read('app', $committed->id)->flash);
            self::assertSame(1090, $context->record()->absoluteExpiresAt);
        }
    }

    private function assertLeasesReleased(): void
    {
        foreach ($this->store->released as $released) {
            $lease = $this->store->inner->acquire('app', $released->id, 'probe', 100, 0);
            $this->store->inner->release($lease);
        }
    }
}

final class CreationTestStore implements SessionStore
{
    public ?\Closure $beforeAcquire = null;
    public ?\Closure $beforeCreate = null;
    public ?\Closure $beforeRevoke = null;
    public ?\Closure $beforeSave = null;
    public bool $failRelease = false;
    public int $collisions = 0;
    public int $creates = 0;
    /** @var list<Lease> */
    public array $released = [];

    public function __construct(public readonly InMemorySessionStore $inner) {}

    public function acquire(string $namespace, string $id, string $owner, int $ttlMilliseconds, int $waitMilliseconds): Lease
    {
        if ($this->beforeAcquire !== null) { ($this->beforeAcquire)(); }
        return $this->inner->acquire($namespace, $id, $owner, $ttlMilliseconds, $waitMilliseconds);
    }
    public function read(string $namespace, string $id): ?SessionRecord { return $this->inner->read($namespace, $id); }
    public function create(string $namespace, SessionRecord $record, Lease $lease): bool
    {
        ++$this->creates;
        if ($this->beforeCreate !== null) { ($this->beforeCreate)(); }
        if ($this->collisions > 0) { --$this->collisions; return false; }
        return $this->inner->create($namespace, $record, $lease);
    }
    public function save(string $namespace, SessionRecord $record, int $expectedRevision, Lease $lease): void
    {
        if ($this->beforeSave !== null) { ($this->beforeSave)(); }
        $this->inner->save($namespace, $record, $expectedRevision, $lease);
    }
    public function renewLease(Lease $lease, int $ttlMilliseconds): Lease { return $this->inner->renewLease($lease, $ttlMilliseconds); }
    public function release(Lease $lease): void
    {
        $this->released[] = $lease;
        $this->inner->release($lease);
        if ($this->failRelease) { throw new StorageUnavailableException('Release failed.'); }
    }
    public function revoke(string $namespace, string $id, int $expectedRevision, Lease $lease): void
    {
        if ($this->beforeRevoke !== null) { ($this->beforeRevoke)(); }
        $this->inner->revoke($namespace, $id, $expectedRevision, $lease);
    }
    public function rotate(string $namespace, string $oldId, int $expectedRevision, SessionRecord $newRecord, Lease $lease): Lease
    {
        return $this->inner->rotate($namespace, $oldId, $expectedRevision, $newRecord, $lease);
    }
}
