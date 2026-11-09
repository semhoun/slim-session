<?php
declare(strict_types=1);
namespace Semhoun\SlimSession\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Semhoun\SlimSession\Exception\InvalidSessionDataException;
use Semhoun\SlimSession\Exception\InvalidSessionStateException;
use Semhoun\SlimSession\Exception\SessionNotFoundException;
use Semhoun\SlimSession\Exception\WriteConflictException;
use Semhoun\SlimSession\SessionConfig;
use Semhoun\SlimSession\SessionContext;
use Semhoun\SlimSession\SessionManager;
use Semhoun\SlimSession\SessionState;
use Semhoun\SlimSession\Random\RandomSource;
use Semhoun\SlimSession\Storage\InMemorySessionStore;

final class LifecycleTest extends TestCase
{
    private CoreTestClock $clock;
    private InMemorySessionStore $store;
    private SessionManager $manager;

    protected function setUp(): void
    {
        $this->clock = new CoreTestClock();
        $this->store = new InMemorySessionStore($this->clock);
        $this->manager = new SessionManager($this->store, new SessionConfig('app', idleLifetime: 100, absoluteLifetime: 250, acquisitionTimeoutMilliseconds: 0), $this->clock);
    }

    public function testExplicitCreateClearAndClose(): void
    {
        $context = $this->manager->absent();
        self::assertSame(SessionState::Absent, $context->state());
        self::assertNull($context->record());
        self::assertNull($context->pendingResponse());
        $context->create(['nullable' => null, 'nested' => ['a' => [true, 1, 1.0]]]);
        $id = $context->record()->id;
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $id);
        self::assertTrue($context->has('nullable'));
        self::assertNull($context->get('nullable', 'default'));
        self::assertSame('default', $context->get('missing', 'default'));
        self::assertSame(1.0, $context->get('nested')['a'][2]);
        self::assertSame(1000, $context->record()->createdAt);
        self::assertSame(1100, $context->record()->idleExpiresAt);
        self::assertSame(1250, $context->record()->absoluteExpiresAt);
        $context->set('x', 'one');
        $context->assign(['x' => 'two', 'y' => 3]);
        $context->delete('y');
        self::assertFalse($context->has('y'));
        self::assertSame('two', $context->get('x'));
        $context->flash('message', 'notice');
        $context->clear();
        $context->saveAndClose();
        self::assertSame(SessionState::Closed, $context->state());
        self::assertTrue($context->pendingResponse()->issueToken);
        self::assertSame($id, $context->pendingResponse()->record->id);
        self::assertSame([], $this->store->read('app', $id)->data);
        self::assertSame([], $this->store->read('app', $id)->flash);
        self::assertNotNull($this->store->read('app', $id));
        $context->saveAndClose();
        $this->expectException(InvalidSessionStateException::class);
        $context->set('x', 1);
    }

    public function testAbsentDoesNotImplicitlyCreate(): void
    {
        $context = $this->manager->absent();
        $this->expectException(InvalidSessionStateException::class);
        $context->set('user', 1);
    }

    public function testFlashConsumptionPersistsButDiscardDoesNot(): void
    {
        $context = $this->manager->create();
        $id = $context->record()->id;
        $context->flash('notice', ['hello']);
        $context->saveAndClose();
        $first = $this->manager->load($id, false);
        self::assertSame(['hello'], $first->consumeFlash('notice'));
        $first->set('discarded', 1);
        $first->discard();
        $second = $this->manager->load($id, false);
        self::assertSame(['hello'], $second->consumeFlash('notice'));
        self::assertSame('fallback', $second->consumeFlash('notice', 'fallback'));
        self::assertFalse($second->has('discarded'));
        $second->saveAndClose();
        $third = $this->manager->load($id, false);
        self::assertNull($third->consumeFlash('notice'));
        $third->discard();
    }

    public function testActivityAndRenewalRespectAbsoluteDeadline(): void
    {
        $c = $this->manager->create();
        $id = $c->record()->id;
        $c->saveAndClose();
        $this->clock->time = 1050;
        $refresh = $this->manager->load($id, false);
        $refresh->renew();
        self::assertNull($refresh->pendingResponse());
        $refresh->saveAndClose();
        self::assertTrue($refresh->pendingResponse()->issueToken);
        self::assertSame(1100, $refresh->record()->idleExpiresAt);
        $activity = $this->manager->load($id);
        $activity->saveAndClose();
        self::assertSame(1150, $activity->record()->idleExpiresAt);
        self::assertFalse($activity->pendingResponse()->issueToken);
        $this->clock->time = 1149;
        $activity = $this->manager->load($id);
        $activity->saveAndClose();
        self::assertSame(1249, $activity->record()->idleExpiresAt);
        $this->clock->time = 1248;
        $activity = $this->manager->load($id);
        $activity->saveAndClose();
        self::assertSame(1250, $activity->record()->idleExpiresAt);
        $this->clock->time = 1250;
        $this->expectException(SessionNotFoundException::class);
        $this->manager->load($id);
    }

    public function testHandlerCanExcludeActivityAfterRouterDispatch(): void
    {
        $c = $this->manager->create();
        $id = $c->record()->id;
        $c->saveAndClose();
        $this->clock->time = 1050;
        $loaded = $this->manager->load($id);
        $loaded->excludeActivity();
        $loaded->saveAndClose();
        self::assertSame(1000, $loaded->record()->lastActivityAt);
        self::assertSame(1100, $loaded->record()->idleExpiresAt);
    }

    public function testIdleExpiryCheckedAtSaveEvenIfActivityWouldExtendIt(): void
    {
        $c = $this->manager->create();
        $id = $c->record()->id;
        $c->saveAndClose();
        $this->clock->time = 1099;
        $loaded = $this->manager->load($id);
        $loaded->set('late', true);
        $this->clock->time = 1100;
        $loaded->renewLease();
        try { $loaded->save(); self::fail('Expired session must reject save.'); }
        catch (WriteConflictException) { self::assertSame(SessionState::Closed, $loaded->state()); }
        self::assertNull($this->store->read('app', $id));
        self::assertNull($loaded->pendingResponse());
    }

    public function testRotationCommitsPreservesDeadlinesAndRegeneratesCsrf(): void
    {
        $c = $this->manager->create(['identity' => 1]);
        $old = $c->record();
        $c->set('unsaved', true);
        $c->flash('notice', 'one');
        $c->rotate();
        $new = $c->record();
        self::assertNotSame($old->id, $new->id);
        self::assertNotSame($old->csrfToken, $new->csrfToken);
        self::assertSame($old->createdAt, $new->createdAt);
        self::assertSame($old->absoluteExpiresAt, $new->absoluteExpiresAt);
        self::assertSame(['identity' => 1, 'unsaved' => true], $new->data);
        self::assertNull($this->store->read('app', $old->id));
        $c->set('discarded', true);
        $c->discard();
        self::assertSame($new, $c->pendingResponse()->record);
        $loaded = $this->manager->load($new->id);
        self::assertFalse($loaded->has('discarded'));
        self::assertSame('one', $loaded->consumeFlash('notice'));
        $loaded->rotate(false);
        self::assertSame([], $loaded->record()->data);
        self::assertSame([], $loaded->record()->flash);
        $loaded->saveAndClose();
    }

    public function testDestroyIsIrreversibleAndRetainsResponseDeletion(): void
    {
        $c = $this->manager->create();
        $id = $c->record()->id;
        $c->destroy();
        self::assertSame(SessionState::Destroyed, $c->state());
        self::assertTrue($c->pendingResponse()->destroyed);
        self::assertNull($c->pendingResponse()->record);
        self::assertNull($this->store->read('app', $id));
        $c->saveAndClose();
        $c->discard();
        self::assertTrue($c->pendingResponse()->destroyed);
        $this->expectException(InvalidSessionStateException::class);
        $c->create();
    }

    public function testLeaseLossClosesContextAndNeverIssuesRenewal(): void
    {
        $c = $this->manager->create();
        $id = $c->record()->id;
        $c->saveAndClose();
        $old = $this->manager->load($id, false);
        $old->renew();
        $old->set('stale', true);
        $this->clock->time += 31;
        $new = $this->manager->load($id, false);
        $new->set('winner', true);
        try { $old->save(); self::fail('Stale lease must fail.'); }
        catch (WriteConflictException) { self::assertSame(SessionState::Closed, $old->state()); }
        self::assertNull($old->pendingResponse());
        $new->saveAndClose();
        self::assertSame(['winner' => true], $this->store->read('app', $id)->data);
    }

    public function testValidJsonValuesAreDetachedFromReferences(): void
    {
        $c = $this->manager->create();
        $value = 'original';
        $values = ['ref' => &$value];
        $c->set('value', $values);
        $value = 'mutated';
        self::assertSame(['ref' => 'original'], $c->get('value'));
        $c->saveAndClose();
    }

    public static function invalidValues(): iterable
    {
        yield 'object' => [new \stdClass()];
        yield 'json serializable object' => [new \DateTimeImmutable()];
        yield 'nan' => [NAN];
        yield 'infinite' => [INF];
        yield 'invalid utf8' => ["\xff"];
        yield 'nested object' => [['a' => new \stdClass()]];
    }

    #[DataProvider('invalidValues')]
    public function testStrictJsonRejectsUnsupportedValues(mixed $value): void
    {
        $c = $this->manager->create(['preserved' => true]);
        try { $c->set('invalid', $value); self::fail('Invalid JSON accepted.'); }
        catch (InvalidSessionDataException) { self::assertFalse($c->has('invalid')); }
        $c->saveAndClose();
        self::assertSame(['preserved' => true], $c->record()->data);
    }

    public function testLimitsApplyToCombinedFlashAndDataAndCycles(): void
    {
        $manager = new SessionManager($this->store, new SessionConfig('small', maxDataBytes: 60, maxDataDepth: 3), $this->clock);
        $c = $manager->create(['ok' => 'small']);
        foreach ([str_repeat('x', 100), [[[['deep']]]]] as $invalid) {
            try { $c->flash('bad', $invalid); self::fail('Limit was not enforced.'); }
            catch (InvalidSessionDataException) { self::assertNull($c->consumeFlash('bad')); }
        }
        $cycle = [];
        $cycle['self'] = &$cycle;
        try { $c->set('cycle', $cycle); self::fail('Cycle accepted.'); }
        catch (InvalidSessionDataException) { self::assertFalse($c->has('cycle')); }
        $c->saveAndClose();
    }

    public function testMissingLoadReleasesLease(): void
    {
        $id = str_repeat('a', 64);
        for ($i = 0; $i < 2; ++$i) {
            try { $this->manager->load($id); self::fail('Missing session accepted.'); }
            catch (SessionNotFoundException) { self::assertTrue(true); }
        }
    }

    public function testRandomFailureDuringCreationReleasesAllocatedLease(): void
    {
        $random = new class implements RandomSource {
            private int $calls = 0;
            public function bytes(int $length): string
            {
                if (++$this->calls === 3) { throw new \RuntimeException('Random source failed.'); }
                return str_repeat('a', $length);
            }
        };
        $manager = new SessionManager($this->store, new SessionConfig('app', acquisitionTimeoutMilliseconds: 0), $this->clock, $random);
        try { $manager->create(); self::fail('Random failure not propagated.'); }
        catch (\RuntimeException $e) { self::assertSame('Random source failed.', $e->getMessage()); }
        $lease = $this->store->acquire('app', bin2hex(str_repeat('a', 32)), 'new', 100, 0);
        self::assertSame('new', $lease->owner);
        $this->store->release($lease);
    }

    public static function forbiddenClosedOperations(): iterable
    {
        foreach (['create', 'save', 'renew', 'renewLease', 'rotate', 'destroy', 'clear', 'excludeActivity'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('forbiddenClosedOperations')]
    public function testClosedCannotReopenOrMutate(string $operation): void
    {
        $c = $this->manager->create();
        $c->saveAndClose();
        $this->expectException(InvalidSessionStateException::class);
        $c->$operation();
    }

    public function testResourceIsRejectedAndStateUnchanged(): void
    {
        $c = $this->manager->create();
        $stream = fopen('php://memory', 'r+');
        try {
            try { $c->flash('bad', $stream); self::fail('Resource accepted.'); }
            catch (InvalidSessionDataException) { self::assertNull($c->consumeFlash('bad')); }
        } finally { fclose($stream); $c->discard(); }
    }

    public function testFailedLeaseRenewalClosesContext(): void
    {
        $c = $this->manager->create();
        $this->clock->time += 30;
        try { $c->renewLease(); self::fail('Expired lease renewed.'); }
        catch (WriteConflictException) { self::assertSame(SessionState::Closed, $c->state()); }
        $this->expectException(InvalidSessionStateException::class);
        $c->set('late', true);
    }
}
