<?php
declare(strict_types=1);
namespace Semhoun\SlimSession\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
use Semhoun\SlimSession\Storage\SessionStoreMaintenance;

final class CleanupTest extends TestCase
{
    public function testDefaultsDisableCleanup(): void
    {
        $config = new SessionConfig('app');
        self::assertSame(0, $config->cleanupProbabilityBasisPoints);
        self::assertSame(100, $config->cleanupBatchSize);
    }

    public static function invalidConfig(): iterable
    {
        yield [-1, 100];
        yield [10001, 100];
        yield [0, 0];
        yield [0, 10001];
    }

    #[DataProvider('invalidConfig')]
    public function testConfigurationBounds(int $chance, int $batch): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SessionConfig('app', cleanupProbabilityBasisPoints: $chance, cleanupBatchSize: $batch);
    }

    public static function sampling(): iterable
    {
        yield 'disabled' => [0, [], false];
        yield 'always' => [10000, [], true];
        yield 'below threshold' => [100, [pack('n', 99)], true];
        yield 'at threshold' => [100, [pack('n', 100)], false];
        yield 'last complete bucket' => [100, [pack('n', 50099)], true];
        yield 'rejection then selected' => [100, [pack('n', 60000), pack('n', 0)], true];
        yield 'bounded rejection' => [100, array_fill(0, 8, "\xff\xff"), false];
        yield 'invalid length' => [100, ['x'], false];
        yield 'random runtime failure' => [100, [new \RuntimeException('Unavailable')], false];
        yield 'secure random failure' => [100, [new \Random\RandomException('Unavailable')], false];
    }

    #[DataProvider('sampling')]
    public function testSamplingAfterRelease(int $chance, array $samples, bool $selected): void
    {
        $clock = new CoreTestClock();
        $inner = new InMemorySessionStore($clock);
        $store = $this->createMockForIntersectionOfInterfaces([SessionStore::class, SessionStoreMaintenance::class]);
        $store->method('acquire')->willReturnCallback($inner->acquire(...));
        $store->method('create')->willReturnCallback($inner->create(...));
        $released = false;
        $store->expects(self::once())->method('release')->willReturnCallback(function (Lease $lease) use ($inner, &$released): void {
            $inner->release($lease);
            $released = true;
        });
        $store->expects($selected ? self::once() : self::never())->method('cleanup')->with('app', 17)
            ->willReturnCallback(function () use (&$released): int {
                self::assertTrue($released);
                return 0;
            });
        $random = new class($samples) implements RandomSource {
            public function __construct(public array $samples) {}
            public function bytes(int $length): string
            {
                if ($length !== 2) { return random_bytes($length); }
                if ($this->samples === []) { throw new \LogicException('Unexpected sampling'); }
                $sample = array_shift($this->samples);
                if ($sample instanceof \Throwable) { throw $sample; }
                return $sample;
            }
        };
        $manager = new SessionManager($store, new SessionConfig('app', cleanupProbabilityBasisPoints: $chance, cleanupBatchSize: 17), $clock, $random);
        $context = $manager->absent();
        $context->create(['user' => 1]);
        self::assertFalse($released);
        $context->discard();
        $context->discard();
        self::assertSame([], $random->samples);
        self::assertSame(['user' => 1], $inner->read('app', $context->record()->id)->data);
        self::assertTrue($context->pendingResponse()->issueToken);
    }

    public function testStorageFailureDoesNotFailCloseOrReplayCreation(): void
    {
        $clock = new CoreTestClock();
        $inner = new InMemorySessionStore($clock);
        $store = $this->createMockForIntersectionOfInterfaces([SessionStore::class, SessionStoreMaintenance::class]);
        foreach (['acquire', 'save', 'release', 'read', 'rotate'] as $method) {
            $store->method($method)->willReturnCallback($inner->$method(...));
        }
        $store->expects(self::once())->method('create')->willReturnCallback($inner->create(...));
        $store->expects(self::once())->method('cleanup')->willThrowException(new StorageUnavailableException('Unavailable'));
        $manager = new SessionManager($store, new SessionConfig('app', cleanupProbabilityBasisPoints: 10000), $clock);
        $context = $manager->create(['user' => 1]);
        $context->set('saved', true);
        $context->saveAndClose();
        self::assertSame(SessionState::Closed, $context->state());
        self::assertTrue($context->pendingResponse()->issueToken);
        $loaded = $manager->load($context->record()->id);
        self::assertSame(true, $loaded->get('saved'));
        $loaded->renew();
        $loaded->rotate();
        $loaded->saveAndClose();
        $context->discard();
    }

    public static function collisions(): iterable
    {
        yield 'eventual success' => [3, true];
        yield 'exhausted' => [5, false];
    }

    #[DataProvider('collisions')]
    public function testCollisionsOnlyScheduleOnSuccess(int $attempts, bool $success): void
    {
        $clock = new CoreTestClock();
        $inner = new InMemorySessionStore($clock);
        $store = $this->createMockForIntersectionOfInterfaces([SessionStore::class, SessionStoreMaintenance::class]);
        $store->method('acquire')->willReturnCallback($inner->acquire(...));
        $store->expects(self::exactly($attempts))->method('release')->willReturnCallback($inner->release(...));
        $calls = 0;
        $store->expects(self::exactly($attempts))->method('create')->willReturnCallback(
            function (string $namespace, SessionRecord $record, Lease $lease) use (&$calls, $attempts, $success, $inner): bool {
                return ++$calls === $attempts && $success && $inner->create($namespace, $record, $lease);
            }
        );
        $store->expects($success ? self::once() : self::never())->method('cleanup')->willReturn(0);
        $context = (new SessionManager($store, new SessionConfig('app', cleanupProbabilityBasisPoints: 10000), $clock))->absent();
        try {
            $context->create();
            self::assertTrue($success);
        } catch (WriteConflictException) {
            self::assertFalse($success);
        } finally {
            $context->discard();
        }
    }

    public function testUnsupportedStoreDoesNotSample(): void
    {
        $clock = new CoreTestClock();
        $random = new class implements RandomSource {
            public function bytes(int $length): string
            {
                if ($length === 2) { throw new \LogicException('Unsupported store sampled'); }
                return random_bytes($length);
            }
        };
        $manager = new SessionManager(new InMemorySessionStore($clock), new SessionConfig('app', cleanupProbabilityBasisPoints: 100), $clock, $random);
        $context = $manager->create();
        $context->saveAndClose();
        self::assertSame(SessionState::Closed, $context->state());
    }

    public function testReleaseFailureSkipsCleanupWithoutRetry(): void
    {
        $store = $this->createMockForIntersectionOfInterfaces([SessionStore::class, SessionStoreMaintenance::class]);
        $store->method('acquire')->willReturn(new Lease('app', 'unused', 'owner', 2000));
        $store->method('create')->willReturn(true);
        $store->expects(self::once())->method('release')->willThrowException(new StorageUnavailableException('Release failed'));
        $store->expects(self::never())->method('cleanup');
        $context = (new SessionManager($store, new SessionConfig('app', cleanupProbabilityBasisPoints: 10000), new CoreTestClock()))->create();
        try {
            $context->discard();
            self::fail('Release failure was swallowed');
        } catch (StorageUnavailableException) {
            self::assertSame(SessionState::Closed, $context->state());
        }
        $context->discard();
    }

    public static function closingPaths(): iterable
    {
        yield 'destroy' => ['destroy'];
        yield 'failed save' => ['saveAndClose'];
    }

    #[DataProvider('closingPaths')]
    public function testCleanupFailurePreservesClosingOutcome(string $operation): void
    {
        $clock = new CoreTestClock();
        $inner = new InMemorySessionStore($clock);
        $store = $this->createMockForIntersectionOfInterfaces([SessionStore::class, SessionStoreMaintenance::class]);
        foreach (['acquire', 'create', 'revoke'] as $method) {
            $store->method($method)->willReturnCallback($inner->$method(...));
        }
        $released = false;
        $store->expects(self::once())->method('release')->willReturnCallback(function (Lease $lease) use ($inner, &$released): void {
            $inner->release($lease);
            $released = true;
        });
        $store->method('save')->willThrowException(new WriteConflictException('Original failure'));
        $store->expects(self::once())->method('cleanup')->willReturnCallback(function () use (&$released): int {
            self::assertTrue($released);
            throw new StorageUnavailableException('Ancillary failure');
        });
        $context = (new SessionManager($store, new SessionConfig('app', cleanupProbabilityBasisPoints: 10000), $clock))->create();
        try {
            $context->$operation();
            self::assertSame('destroy', $operation);
            self::assertSame(SessionState::Destroyed, $context->state());
            self::assertTrue($context->pendingResponse()->destroyed);
        } catch (WriteConflictException $error) {
            self::assertSame('saveAndClose', $operation);
            self::assertSame('Original failure', $error->getMessage());
            self::assertSame(SessionState::Closed, $context->state());
        }
        $context->discard();
    }

    public function testProgrammingErrorsPropagateOnlyAfterRelease(): void
    {
        $clock = new CoreTestClock();
        $inner = new InMemorySessionStore($clock);
        $store = $this->createMockForIntersectionOfInterfaces([SessionStore::class, SessionStoreMaintenance::class]);
        foreach (['acquire', 'create', 'release'] as $method) {
            $store->method($method)->willReturnCallback($inner->$method(...));
        }
        $store->expects(self::once())->method('cleanup')->willThrowException(new \LogicException('Invalid custom maintenance'));
        $context = (new SessionManager($store, new SessionConfig('app', cleanupProbabilityBasisPoints: 10000), $clock))->create();
        try {
            $context->discard();
            self::fail('Programming error was swallowed');
        } catch (\LogicException $error) {
            self::assertSame('Invalid custom maintenance', $error->getMessage());
            self::assertSame(SessionState::Closed, $context->state());
        }
        $lease = $inner->acquire('app', $context->record()->id, 'next', 1000, 0);
        $inner->release($lease);
        $context->discard();
    }
}
