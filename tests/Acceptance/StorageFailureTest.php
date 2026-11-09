<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Tests\Acceptance;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Semhoun\SlimSession\Http\HttpConfig;
use Semhoun\SlimSession\Http\SessionMiddleware;
use Semhoun\SlimSession\Jwt\JwtService;
use Semhoun\SlimSession\SessionConfig;
use Semhoun\SlimSession\SessionManager;
use Semhoun\SlimSession\Storage\PdoSessionStore;

final class StorageFailureTest extends TestCase
{
    private \PDO $connection;
    private ClockInterface $clock;
    private SessionManager $manager;
    private JwtService $jwt;
    private SessionMiddleware $middleware;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite is required for isolated acceptance tests.');
        }
        $this->connection = new \PDO('sqlite::memory:');
        $schema = file_get_contents(__DIR__ . '/../../schemas/sqlite.sql');
        self::assertIsString($schema);
        $this->connection->exec($schema);
        $this->clock = new class implements ClockInterface {
            public int $timestamp;
            public function __construct() { $this->timestamp = time(); }
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('@' . $this->timestamp); }
        };
        $this->manager = new SessionManager(new PdoSessionStore($this->connection), new SessionConfig('acceptance'), $this->clock);
        $this->jwt = new JwtService($this->clock, 'acceptance', 'acceptance-browser', ['test' => str_repeat('k', 32)], 'test', lifetime: 10, renewalWindow: 2);
        $this->middleware = new SessionMiddleware($this->manager, $this->jwt, new HttpConfig('header', 'https://acceptance.test'), $this->clock, new Psr17Factory());
    }

    public function testInfrastructureFailureIs503NotJwtOnlyAuthentication(): void
    {
        [$token] = $this->credential();
        $this->connection->exec('DROP TABLE slim_session_records');
        $handler = $this->handler();
        $response = $this->middleware->process($this->request($token), $handler);

        self::assertSame(503, $response->getStatusCode());
        self::assertFalse($handler->called);
        self::assertFalse($response->hasHeader('X-Session-Token'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringNotContainsString('slim_session_records', (string) $response->getBody());
    }

    public function testExpiredJwtCannotRestoreStillLiveServerRecord(): void
    {
        [$token, $id] = $this->credential();
        $this->clock->timestamp += 11;
        $handler = $this->handler();
        $response = $this->middleware->process($this->request($token), $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($handler->called);
        self::assertNotNull((new PdoSessionStore($this->connection))->read('acceptance', $id));
        self::assertFalse($response->hasHeader('X-Session-Token'));
    }

    public function testValidJwtCannotRestoreExpiredStorageRecord(): void
    {
        [$token, $id] = $this->credential();
        $statement = $this->connection->prepare('UPDATE slim_session_records SET expires_at = ? WHERE namespace = ? AND id = ?');
        $statement->execute([time() - 1, 'acceptance', $id]);
        $handler = $this->handler();
        $response = $this->middleware->process($this->request($token), $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($handler->called);
        self::assertFalse($response->hasHeader('X-Session-Token'));
    }

    /** @return array{string, string} */
    private function credential(): array
    {
        $context = $this->manager->create(['private' => 'never-in-the-jwt']);
        $record = $context->record();
        self::assertNotNull($record);
        $context->saveAndClose();
        return [$this->jwt->issue($record->id, $record->absoluteExpiresAt, $record->verificationSecret), $record->id];
    }

    private function request(string $token): ServerRequestInterface
    {
        return (new ServerRequest('GET', 'https://acceptance.test/protected'))->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public bool $called = false;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;
                return (new Psr17Factory())->createResponse();
            }
        };
    }
}
