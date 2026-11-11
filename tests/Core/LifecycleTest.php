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
        self::assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $id);
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

    public function testUuidGenerationAndIndependentSecretsSurvivePersistence(): void
    {
        $ids = [];
        for ($i = 0; $i < 32; ++$i) {
            $context = $this->manager->create();
            $record = $context->record();
            self::assertTrue(\Semhoun\SlimSession\SessionId::valid($record->id));
            self::assertNotContains($record->id, $ids);
            $ids[] = $record->id;
            self::assertSame(2, $record->schemaVersion);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $record->verificationSecret);
            self::assertNotSame($record->verificationSecret, $record->csrfToken);
            $context->saveAndClose();
            $loaded = $this->manager->load($record->id);
            $loaded->renew();
            $loaded->saveAndClose();
            self::assertSame($record->verificationSecret, $loaded->record()->verificationSecret);
            $encoded = \Semhoun\SlimSession\Storage\RecordCodec::encode($loaded->record());
            self::assertEquals($loaded->record(), \Semhoun\SlimSession\Storage\RecordCodec::decode($encoded, $record->id));
        }
    }

    public function testLegacyRecordSchemaIsRejected(): void
    {
        $context = $this->manager->create();
        $fields = get_object_vars($context->record());
        $context->discard();
        $fields['schemaVersion'] = 1;
        unset($fields['verificationSecret']);
        $this->expectException(\Semhoun\SlimSession\Exception\StorageUnavailableException::class);
        \Semhoun\SlimSession\Storage\RecordCodec::decode(json_encode($fields, JSON_THROW_ON_ERROR));
    }

    public function testCoreLoadRejectsNoncanonicalIdentifiers(): void
    {
        foreach ([str_repeat('a', 64), 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE',
            'aaaaaaaa-bbbb-5ccc-8ddd-eeeeeeeeeeee', 'aaaaaaaa-bbbb-4ccc-7ddd-eeeeeeeeeeee'] as $id) {
            $context = $this->manager->create();
            $fields = get_object_vars($context->record());
            $context->discard();
            $fields['id'] = $id;
            $lease = $this->store->acquire('app', $id, 'fixture', 30000, 0);
            $this->store->create('app', new \Semhoun\SlimSession\SessionRecord(...$fields), $lease);
            $this->store->release($lease);
            try { $this->manager->load($id); self::fail('Noncanonical identifier loaded.'); }
            catch (SessionNotFoundException) { self::assertTrue(true); }
        }
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

    public function testNestedPathsPreserveSiblingsAndPersistActualArrays(): void
    {
        $c = $this->manager->create(['unrelated' => true]);
        $c->set('user.firstname', 'Ada');
        $c->set('user.lastname', 'Lovelace');
        $c->set('user.preferences.theme', 'dark');
        $c->set('users.0.firstname', 'Grace');
        $c->set('users.2.firstname', 'Katherine');
        $c->set('users.01.firstname', 'Leading zero');
        self::assertSame(['firstname' => 'Ada', 'lastname' => 'Lovelace', 'preferences' => ['theme' => 'dark']], $c->get('user'));
        self::assertSame('Ada', $c->get('user.firstname'));
        self::assertTrue($c->has('user.preferences.theme'));
        self::assertSame([0, 2, '01'], array_keys($c->get('users')));
        $id = $c->record()->id;
        $c->saveAndClose();
        self::assertArrayNotHasKey('user.firstname', $this->store->read('app', $id)->data);
        $loaded = $this->manager->load($id);
        self::assertSame('Lovelace', $loaded->get('user.lastname'));
        self::assertSame(['theme' => 'dark'], $loaded->get('user.preferences'));
        self::assertSame('Grace', $loaded->get('users.0.firstname'));
        self::assertTrue($loaded->get('unrelated'));
        $loaded->clear();
        $loaded->saveAndClose();
        self::assertSame([], $this->store->read('app', $id)->data);
    }

    public function testNestedReadsAndDeletesDistinguishNullAndBlockedPaths(): void
    {
        $c = $this->manager->create(['user' => ['firstname' => 'Ada', 'nullable' => null], 'scalar' => 'private', 'null' => null]);
        self::assertTrue($c->has('user.nullable'));
        self::assertNull($c->get('user.nullable', 'fallback'));
        foreach (['missing.child', 'user.missing', 'scalar.child', 'null.child'] as $path) {
            self::assertFalse($c->has($path));
            self::assertSame('fallback', $c->get($path, 'fallback'));
            $c->delete($path);
        }
        self::assertSame('private', $c->get('scalar'));
        self::assertTrue($c->has('null'));
        $c->delete('user.firstname');
        self::assertSame(['nullable' => null], $c->get('user'));
        $c->delete('user.nullable');
        self::assertSame([], $c->get('user'));
        self::assertTrue($c->has('user'));
        $c->delete('user');
        self::assertFalse($c->has('user'));
        $c->delete('scalar');
        $c->set('scalar.child', 'reset');
        self::assertSame(['child' => 'reset'], $c->get('scalar'));
        $c->set('rows', [0 => 'first', 1 => 'second', 2 => 'third']);
        $c->delete('rows.1');
        self::assertSame([0 => 'first', 2 => 'third'], $c->get('rows'));
        $c->discard();
    }

    public function testNumericAndSpecialSegmentsUseOrdinaryPhpKeysWithoutWildcardOrAppendSyntax(): void
    {
        $c = $this->manager->create(['rows.0.name' => 'zero', 'rows.-1.name' => 'negative']);
        self::assertSame('fallback', $c->get('rows.*.name', 'fallback'));
        $c->delete('rows.*.name');
        self::assertSame('zero', $c->get('rows.0.name'));
        $c->set('rows.*.name', 'literal star');
        $c->set('rows.[].name', 'literal brackets');
        self::assertSame([0 => ['name' => 'zero'], -1 => ['name' => 'negative'], '*' => ['name' => 'literal star'], '[]' => ['name' => 'literal brackets']], $c->get('rows'));
        $c->discard();
    }

    public function testBulkPathsUseIterationOrderAndReplaceRatherThanMerge(): void
    {
        $values = ['user.first' => 'discarded', 'user' => ['last' => 'Lovelace', 'literal.dot' => 'literal'], 'user.first.name' => 'Ada'];
        foreach ([false, true] as $throughManager) {
            $c = $throughManager ? $this->manager->create($values) : $this->manager->absent();
            if (!$throughManager) { $c->create($values); }
            self::assertSame(['last' => 'Lovelace', 'literal.dot' => 'literal', 'first' => ['name' => 'Ada']], $c->get('user'));
            self::assertSame('fallback', $c->get('user.literal.dot', 'fallback'));
            $c->assign(['user.first.name' => 'replaced', 'user.first' => ['only' => true], 'other.child' => 1]);
            self::assertSame(['only' => true], $c->get('user.first'));
            self::assertSame('Lovelace', $c->get('user.last'));
            $c->assign(['other.child' => 2, 'other' => 'scalar wins']);
            self::assertSame('scalar wins', $c->get('other'));
            $c->discard();
        }
    }

    public static function invalidNestedWrites(): iterable
    {
        yield 'scalar collision' => [['changed' => true, 'scalar.child' => 1]];
        yield 'null collision' => [['changed' => true, 'nullable.child' => 1]];
        yield 'nested collision' => [['changed' => true, 'user.name.child' => 1]];
        yield 'new scalar collision' => [['changed' => true, 'new' => false, 'new.child' => 1]];
        yield 'malformed late path' => [['changed' => true, 'bad..path' => 1]];
        yield 'invalid late value' => [['changed' => true, 'user.bad' => new \stdClass()]];
        yield 'nonfinite late value' => [['changed' => true, 'user.bad' => INF]];
        yield 'invalid utf8 value' => [['changed' => true, 'user.bad' => "\xff"]];
    }

    #[DataProvider('invalidNestedWrites')]
    public function testFailedNestedBulkWriteIsAtomic(array $values): void
    {
        $initial = ['scalar' => 'secret', 'nullable' => null, 'user' => ['name' => 'Ada'], 'changed' => false];
        $c = $this->manager->create($initial);
        try { $c->assign($values); self::fail('Invalid nested write accepted.'); }
        catch (InvalidSessionDataException) {
            foreach ($initial as $key => $value) { self::assertSame($value, $c->get($key)); }
            self::assertFalse($c->has('new'));
        }
        $c->saveAndClose();
        self::assertSame($initial, $c->record()->data);
    }

    public function testOverlappingBulkWritesDetachCallerReferencesBeforeTraversal(): void
    {
        foreach ([false, true] as $creation) {
            $name = 'original';
            $parent = ['name' => &$name];
            $values = ['user' => &$parent, 'user.name' => 'updated', 'user.extra' => true];
            $c = $this->manager->absent();
            if ($creation) { $c->create($values); }
            else { $c->create(); $c->assign($values); }
            self::assertSame('original', $name);
            self::assertSame(['name' => 'original'], $parent);
            self::assertSame(['name' => 'updated', 'extra' => true], $c->get('user'));
            $name = 'caller mutation';
            $parent['later'] = true;
            self::assertSame('updated', $c->get('user.name'));
            self::assertFalse($c->has('user.later'));
            $c->discard();
        }
        $c = $this->manager->create(['preserved' => true]);
        $name = 'original';
        $parent = ['name' => &$name];
        try {
            $c->assign(['user' => &$parent, 'user.name' => 'must not leak', 'user.name.child' => 1]);
            self::fail('Collision accepted.');
        } catch (InvalidSessionDataException) {
            self::assertSame('original', $name);
            self::assertSame(['name' => 'original'], $parent);
            self::assertFalse($c->has('user'));
            self::assertTrue($c->get('preserved'));
        }
        $c->discard();
    }

    public static function malformedPaths(): iterable
    {
        foreach (['', '.', '.user', 'user.', 'user..name', 'user...name'] as $path) {
            foreach (['get', 'has', 'set', 'delete', 'assign', 'create', 'managerCreate'] as $operation) {
                yield $operation . ':' . $path => [$operation, $path];
            }
        }
    }

    #[DataProvider('malformedPaths')]
    public function testMalformedPathsAreRejected(string $operation, string $path): void
    {
        $creating = in_array($operation, ['create', 'managerCreate'], true);
        $c = $creating ? $this->manager->absent() : $this->manager->create(['preserved' => true]);
        try {
            try {
                match ($operation) {
                    'set' => $c->set($path, 1),
                    'assign' => $c->assign(['early.valid' => 1, $path => 2]),
                    'create' => $c->create(['early.valid' => 1, $path => 2]),
                    'managerCreate' => $this->manager->create(['early.valid' => 1, $path => 2]),
                    default => $c->$operation($path),
                };
                self::fail('Malformed path accepted.');
            } catch (InvalidSessionDataException) {
                if ($creating) {
                    self::assertSame(SessionState::Absent, $c->state());
                    self::assertNull($c->record());
                } else {
                    self::assertTrue($c->get('preserved'));
                    self::assertFalse($c->has('early'));
                }
            }
        } finally { $c->discard(); }
    }

    public function testNestedDepthAndCombinedByteLimitsAreAtomic(): void
    {
        $manager = new SessionManager($this->store, new SessionConfig('small-paths', maxDataBytes: 100, maxDataDepth: 3), $this->clock);
        $c = $manager->create(['a.b.c' => 'leaf']);
        self::assertSame('leaf', $c->get('a.b.c'));
        $c->flash('notice', str_repeat('f', 30));
        foreach ([['a.b.c.d' => 1], ['a.b.c' => []], ['early' => true, 'a.b' => str_repeat('x', 50)], [str_repeat('x.', 1000) . 'leaf' => 1]] as $values) {
            try { $c->assign($values); self::fail('Nested limit accepted.'); }
            catch (InvalidSessionDataException) {
                self::assertSame(['b' => ['c' => 'leaf']], $c->get('a'));
                self::assertFalse($c->has('early'));
            }
        }
        self::assertSame(str_repeat('f', 30), $c->consumeFlash('notice'));
        $c->saveAndClose();
        self::assertSame(['a' => ['b' => ['c' => 'leaf']]], $c->record()->data);
    }

    public function testMinimumDepthAllowsScalarLeavesButNotNestedArrays(): void
    {
        $manager = new SessionManager($this->store, new SessionConfig('depth-one', maxDataDepth: 1), $this->clock);
        $c = $manager->create(['leaf' => null]);
        $c->set('leaf', 'scalar');
        foreach (['child.value', 'child'] as $path) {
            try { $c->set($path, []); self::fail('Array exceeded minimum depth.'); }
            catch (InvalidSessionDataException) {
                self::assertSame('scalar', $c->get('leaf'));
                self::assertFalse($c->has('child'));
            }
        }
        $c->discard();
    }

    public function testByteLimitAppliesToNormalizedNumericPathsRatherThanInputKeys(): void
    {
        $manager = new SessionManager($this->store, new SessionConfig('compact-paths', maxDataBytes: 10), $this->clock);
        foreach (['create', 'assign', 'set'] as $operation) {
            $c = $operation === 'create' ? $manager->create(['0.0' => 0]) : $manager->create();
            if ($operation === 'assign') { $c->assign(['0.0' => 0]); }
            if ($operation === 'set') { $c->set('0.0', 0); }
            self::assertSame(0, $c->get('0.0'));
            $c->saveAndClose();
            self::assertSame([[0]], $c->record()->data);
        }
    }

    public static function invalidNestedCreation(): iterable
    {
        yield 'collision' => [['user' => null, 'user.name' => 'Ada']];
        yield 'malformed' => [['valid.path' => true, 'bad.' => 1]];
        yield 'json' => [['valid.path' => true, 'user.name' => new \stdClass()]];
        yield 'depth' => [['a.b.c.d' => 1]];
        yield 'nested value depth' => [['a.b.c' => []]];
        yield 'bytes' => [['user.name' => str_repeat('x', 100)]];
    }

    #[DataProvider('invalidNestedCreation')]
    public function testInvalidCreationFailsBeforeRandomOrStoreAllocation(array $values): void
    {
        $random = new class implements RandomSource {
            public int $calls = 0;
            public function bytes(int $length): string
            {
                ++$this->calls;
                throw new \LogicException('Invalid creation reached allocation.');
            }
        };
        $manager = new SessionManager($this->store, new SessionConfig('invalid-paths', maxDataBytes: 60, maxDataDepth: 3), $this->clock, $random);
        $c = $manager->absent();
        foreach ([false, true] as $throughManager) {
            try {
                if ($throughManager) { $manager->create($values); }
                else { $c->create($values); }
                self::fail('Invalid creation accepted.');
            } catch (InvalidSessionDataException) {
                self::assertSame(0, $random->calls);
                self::assertSame(SessionState::Absent, $c->state());
                self::assertNull($c->record());
                self::assertNull($c->pendingResponse());
            }
        }
        $c->discard();
    }

    public function testLifecycleChecksPrecedeMalformedPathValidation(): void
    {
        $absent = $this->manager->absent();
        $closed = $this->manager->create();
        $closed->saveAndClose();
        $destroyed = $this->manager->create();
        $destroyed->destroy();
        foreach ([$absent, $closed, $destroyed] as $c) {
            foreach (['get', 'has', 'set', 'delete', 'assign'] as $operation) {
                try {
                    match ($operation) {
                        'set' => $c->set('', new \stdClass()),
                        'assign' => $c->assign(['' => new \stdClass()]),
                        default => $c->$operation(''),
                    };
                    self::fail('Non-open operation accepted.');
                } catch (InvalidSessionStateException) { self::assertTrue(true); }
            }
        }
        $open = $this->manager->create();
        foreach ([$open, $closed, $destroyed] as $c) {
            try { $c->create(['' => new \stdClass()]); self::fail('Non-absent creation accepted.'); }
            catch (InvalidSessionStateException) { self::assertTrue(true); }
        }
        $open->discard();
    }

    public function testFlashKeysRemainLiteralIncludingMalformedPaths(): void
    {
        $c = $this->manager->create();
        foreach (['notice.text', '', '.notice', 'notice..text'] as $key) { $c->flash($key, ['literal.dot' => $key]); }
        $id = $c->record()->id;
        $c->saveAndClose();
        $loaded = $this->manager->load($id);
        self::assertSame('fallback', $loaded->consumeFlash('notice', 'fallback'));
        foreach (['notice.text', '', '.notice', 'notice..text'] as $key) {
            self::assertSame(['literal.dot' => $key], $loaded->consumeFlash($key));
            self::assertSame('fallback', $loaded->consumeFlash($key, 'fallback'));
        }
        $loaded->saveAndClose();
        self::assertSame([], $loaded->record()->flash);
    }

    public function testStoredLiteralDottedKeysAreNotReinterpretedOrUsedAsFallback(): void
    {
        $c = $this->manager->create();
        $fields = get_object_vars($c->record());
        $id = $c->record()->id;
        $c->discard();
        $fields['data'] = ['user.name' => 'historical', 'parent' => ['literal.dot' => 'nested literal'], 'back\\' => ['child' => 'backslash is literal']];
        ++$fields['revision'];
        $lease = $this->store->acquire('app', $id, 'fixture', 30000, 0);
        $this->store->save('app', new \Semhoun\SlimSession\SessionRecord(...$fields), $fields['revision'] - 1, $lease);
        $this->store->release($lease);
        $loaded = $this->manager->load($id);
        self::assertFalse($loaded->has('user.name'));
        self::assertSame('fallback', $loaded->get('user.name', 'fallback'));
        self::assertFalse($loaded->has('parent.literal.dot'));
        self::assertSame(['literal.dot' => 'nested literal'], $loaded->get('parent'));
        self::assertSame('backslash is literal', $loaded->get('back\\.child'));
        $loaded->delete('user.name');
        $loaded->set('user.name', 'new nested');
        self::assertSame('new nested', $loaded->get('user.name'));
        $loaded->saveAndClose();
        self::assertSame('historical', $loaded->record()->data['user.name']);
        self::assertSame(['name' => 'new nested'], $loaded->record()->data['user']);
        self::assertSame(['literal.dot' => 'nested literal'], $loaded->record()->data['parent']);
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
        foreach (['create', 'startNew', 'save', 'renew', 'renewLease', 'rotate', 'destroy', 'clear', 'excludeActivity'] as $method) {
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
