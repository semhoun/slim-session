<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Semhoun\SlimSession\Http\HttpConfig;
use Semhoun\SlimSession\Http\LogoutHandler;
use Semhoun\SlimSession\Http\RefreshHandler;
use Semhoun\SlimSession\Http\SessionGuard;
use Semhoun\SlimSession\Http\SessionMiddleware;
use Semhoun\SlimSession\Http\StatusHandler;
use Semhoun\SlimSession\Jwt\JwtService;
use Semhoun\SlimSession\SessionConfig;
use Semhoun\SlimSession\SessionManager;
use Semhoun\SlimSession\Storage\InMemorySessionStore;

final class SessionMiddlewareTest extends TestCase
{
    private ClockInterface $clock;
    private InMemorySessionStore $store;
    private SessionManager $manager;
    private JwtService $jwt;
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->clock = new class implements ClockInterface {
            public int $time = 1700000000;
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('@' . $this->time); }
        };
        $this->store = new InMemorySessionStore($this->clock);
        $this->manager = new SessionManager($this->store, new SessionConfig('http-test'), $this->clock);
        $this->jwt = new JwtService($this->clock, 'http-test', 'http-test', ['current' => str_repeat('a', 32)], 'current');
        $this->factory = new Psr17Factory();
    }

    private function middleware(string $transport = 'header', ?\Closure $activity = null): SessionMiddleware
    {
        return new SessionMiddleware($this->manager, $this->jwt, new HttpConfig($transport, 'https://example.test'), $this->clock, $this->factory, $activity);
    }

    private function handler(\Closure $callback): RequestHandlerInterface
    {
        return new class ($callback) implements RequestHandlerInterface {
            public function __construct(private \Closure $callback) {}
            public function handle(ServerRequestInterface $request): ResponseInterface { return ($this->callback)($request); }
        };
    }

    /** @return array{string,string,string} */
    private function session(): array
    {
        $context = $this->manager->create(['count' => 0]);
        $record = $context->record();
        $context->saveAndClose();
        return [$record->id, $this->jwt->issue($record->id, $record->absoluteExpiresAt), $record->csrfToken];
    }

    private function request(string $token, string $method = 'GET'): ServerRequest
    {
        return new ServerRequest($method, 'https://example.test/session', ['Authorization' => 'Bearer ' . $token]);
    }

    public function testMissingCredentialsStayAbsentAndGuardRejectsMissingMiddleware(): void
    {
        $response = $this->middleware()->process(new ServerRequest('GET', '/'), new StatusHandler($this->factory));
        self::assertSame(['authenticated' => false, 'transport' => 'header'], json_decode((string) $response->getBody(), true));
        self::assertFalse($response->hasHeader('X-Session-Token'));
        $this->expectException(\LogicException::class);
        SessionGuard::requireSession(new ServerRequest('GET', '/'));
    }

    public function testCreationAndEarlyCloseFinalizeCredentialAfterPersistence(): void
    {
        $response = $this->middleware()->process(new ServerRequest('POST', '/login'), $this->handler(function ($request) {
            $session = SessionGuard::context($request);
            $session->create(['identity' => 123]);
            $session->set('saved', true);
            $session->saveAndClose();
            return $this->factory->createResponse();
        }));
        $token = $this->jwt->verify($response->getHeaderLine('X-Session-Token'));
        self::assertTrue($this->store->read('http-test', $token->sessionId)->data['saved']);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testNormalFourHundredPersistsAndExceptionDiscardsAndReleases(): void
    {
        [$id, $token] = $this->session();
        $response = $this->middleware()->process($this->request($token), $this->handler(function ($request) {
            SessionGuard::requireSession($request)->set('count', 1);
            return $this->factory->createResponse(422);
        }));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(1, $this->store->read('http-test', $id)->data['count']);
        try {
            $this->middleware()->process($this->request($token), $this->handler(function ($request) {
                SessionGuard::requireSession($request)->set('count', 2);
                throw new \RuntimeException('business failure');
            }));
            self::fail('Expected business exception.');
        } catch (\RuntimeException $error) {
            self::assertSame('business failure', $error->getMessage());
        }
        $loaded = $this->manager->load($id);
        self::assertSame(1, $loaded->get('count'));
        $loaded->discard();
    }

    public function testLeaseLossIs409AndNeverIssuesPendingCredential(): void
    {
        [, $token] = $this->session();
        $response = $this->middleware()->process($this->request($token), $this->handler(function ($request) {
            SessionGuard::requireSession($request)->renew();
            $this->clock->time += 31;
            return $this->factory->createResponse();
        }));
        self::assertSame(409, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-Session-Token'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testTransportIsolationAndDuplicateCredentials(): void
    {
        [, $token] = $this->session();
        $cookieOnly = new ServerRequest('GET', '/', ['Cookie' => '__Host-slim-session=' . $token]);
        $response = $this->middleware()->process($cookieOnly, new StatusHandler($this->factory));
        self::assertFalse(json_decode((string) $response->getBody(), true)['authenticated']);
        $response = $this->middleware('cookie')->process($this->request($token), new StatusHandler($this->factory));
        self::assertFalse(json_decode((string) $response->getBody(), true)['authenticated']);
        $request = $this->request($token)->withAddedHeader('Authorization', 'Bearer ' . $token);
        self::assertSame(401, $this->middleware()->process($request, new StatusHandler($this->factory))->getStatusCode());
        $request = $cookieOnly->withHeader('Cookie', '__Host-slim-session=' . $token . '; __Host-slim-session=' . $token);
        self::assertSame(401, $this->middleware('cookie')->process($request, new StatusHandler($this->factory))->getStatusCode());
    }

    public function testCookieBootstrapOriginCsrfAndPersistentLifetime(): void
    {
        [, $token, $csrf] = $this->session();
        $cookie = ['Cookie' => '__Host-slim-session=' . $token, 'Authorization' => 'unrelated application auth'];
        $response = $this->middleware('cookie')->process(new ServerRequest('GET', '/', $cookie), new StatusHandler($this->factory));
        $metadata = json_decode((string) $response->getBody(), true);
        self::assertSame($csrf, $metadata['csrfToken']);
        self::assertFalse($response->hasHeader('X-Session-Token'));
        foreach ([[], ['Origin' => 'null'], ['Origin' => 'https://foreign.test'], ['Origin' => 'https://example.test']] as $proof) {
            $request = new ServerRequest('POST', '/', $cookie + $proof);
            self::assertSame(403, $this->middleware('cookie')->process($request, new RefreshHandler($this->factory))->getStatusCode());
        }
        $request = new ServerRequest('POST', '/', $cookie + ['Origin' => 'https://example.test', 'X-CSRF-Token' => $csrf]);
        $response = $this->middleware('cookie')->process($request, new RefreshHandler($this->factory));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('; Max-Age=86400; Secure; HttpOnly; SameSite=Lax', $response->getHeaderLine('Set-Cookie'));
        self::assertFalse($response->hasHeader('X-Session-Token'));
        self::assertArrayNotHasKey('token', json_decode((string) $response->getBody(), true));
    }

    public function testStatusRefreshAndExcludedRequestsNeverExtendIdleDeadline(): void
    {
        [$id, $token] = $this->session();
        $initial = $this->store->read('http-test', $id)->idleExpiresAt;
        $this->clock->time += 10;
        $this->middleware()->process($this->request($token), new StatusHandler($this->factory));
        $this->middleware()->process($this->request($token), $this->handler(fn ($request) => (new StatusHandler($this->factory))->handle($request)));
        $this->middleware()->process($this->request($token, 'POST'), new RefreshHandler($this->factory));
        $this->middleware()->process($this->request($token, 'POST'), $this->handler(fn ($request) => (new RefreshHandler($this->factory))->handle($request)));
        $this->middleware(activity: static fn ($request) => false)->process($this->request($token), $this->handler(fn ($request) => $this->factory->createResponse()));
        self::assertSame($initial, $this->store->read('http-test', $id)->idleExpiresAt);
        $this->middleware()->process($this->request($token), $this->handler(fn ($request) => $this->factory->createResponse()));
        self::assertSame($initial + 10, $this->store->read('http-test', $id)->idleExpiresAt);
    }

    public function testRotationAndDestroyAreIrreversibleAfterBusinessException(): void
    {
        [$id, $token] = $this->session();
        $newId = null;
        try {
            $this->middleware()->process($this->request($token), $this->handler(function ($request) use (&$newId) {
                $context = SessionGuard::requireSession($request);
                $context->rotate();
                $newId = $context->record()->id;
                throw new \RuntimeException('after rotation');
            }));
        } catch (\RuntimeException $error) {
            self::assertSame('after rotation', $error->getMessage());
        }
        self::assertNull($this->store->read('http-test', $id));
        self::assertNotNull($this->store->read('http-test', $newId));
        $newToken = $this->jwt->issue($newId, $this->store->read('http-test', $newId)->absoluteExpiresAt);
        try {
            $this->middleware()->process($this->request($newToken), $this->handler(function ($request) {
                SessionGuard::requireSession($request)->destroy();
                throw new \RuntimeException('after revocation');
            }));
        } catch (\RuntimeException $error) {
            self::assertSame('after revocation', $error->getMessage());
        }
        self::assertNull($this->store->read('http-test', $newId));
        self::assertSame(401, $this->middleware()->process($this->request($newToken), new StatusHandler($this->factory))->getStatusCode());
    }

    public function testLogoutDeletesMatchingCookieAndRevokesReplay(): void
    {
        [$id, $token, $csrf] = $this->session();
        $request = new ServerRequest('POST', '/', ['Cookie' => '__Host-slim-session=' . $token, 'Origin' => 'https://example.test', 'X-CSRF-Token' => $csrf]);
        $response = $this->middleware('cookie')->process($request, new LogoutHandler($this->factory));
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('__Host-slim-session=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax', $response->getHeaderLine('Set-Cookie'));
        self::assertNull($this->store->read('http-test', $id));
        self::assertSame(401, $this->middleware()->process($this->request($token), new StatusHandler($this->factory))->getStatusCode());
    }

    public function testEarlyCloseSurvivesLaterExceptionAndRejectsMutation(): void
    {
        [$id, $token] = $this->session();
        try {
            $this->middleware()->process($this->request($token), $this->handler(function ($request) {
                $context = SessionGuard::requireSession($request);
                $context->set('count', 10);
                $context->saveAndClose();
                $context->set('count', 11);
                return $this->factory->createResponse();
            }));
            self::fail('Closed mutation must throw.');
        } catch (\Semhoun\SlimSession\Exception\InvalidSessionStateException) {
            self::assertSame(10, $this->store->read('http-test', $id)->data['count']);
        }
        $context = $this->manager->load($id);
        self::assertSame(10, $context->get('count'));
        $context->discard();
    }

    public function testRenewalIsBoundedByAbsoluteDeadline(): void
    {
        $manager = new SessionManager($this->store, new SessionConfig('http-test', absoluteLifetime: 100), $this->clock);
        $context = $manager->create();
        $record = $context->record();
        $context->saveAndClose();
        $token = $this->jwt->issue($record->id, $record->absoluteExpiresAt);
        $this->clock->time += 10;
        $middleware = new SessionMiddleware($manager, $this->jwt, new HttpConfig('header', 'https://example.test'), $this->clock, $this->factory);
        $response = $middleware->process($this->request($token, 'POST'), new RefreshHandler($this->factory));
        self::assertSame($record->absoluteExpiresAt, $this->jwt->verify($response->getHeaderLine('X-Session-Token'))->expiresAt);
        self::assertSame($record->absoluteExpiresAt, json_decode((string) $response->getBody(), true)['tokenExpiresAt']);
    }

    public function testInvalidCookieIsDeletedAndMiddlewareHasNoCrossRequestState(): void
    {
        $middleware = $this->middleware('cookie');
        [, $token] = $this->session();
        $response = $middleware->process(new ServerRequest('GET', '/', ['Cookie' => '__Host-slim-session=' . $token]), new StatusHandler($this->factory));
        self::assertTrue(json_decode((string) $response->getBody(), true)['authenticated']);
        $response = $middleware->process(new ServerRequest('GET', '/'), new StatusHandler($this->factory));
        self::assertFalse(json_decode((string) $response->getBody(), true)['authenticated']);
        self::assertFalse($response->hasHeader('X-CSRF-Token'));
        $response = $middleware->process(new ServerRequest('GET', '/', ['Cookie' => '__Host-slim-session=forged']), new StatusHandler($this->factory));
        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
    }

    public function testDuplicatedOriginOrCsrfFailsClosed(): void
    {
        [, $token, $csrf] = $this->session();
        $request = new ServerRequest('POST', '/', ['Cookie' => '__Host-slim-session=' . $token, 'Origin' => 'https://example.test', 'X-CSRF-Token' => $csrf]);
        $handler = new RefreshHandler($this->factory);
        self::assertSame(403, $this->middleware('cookie')->process($request->withAddedHeader('Origin', 'https://example.test'), $handler)->getStatusCode());
        self::assertSame(403, $this->middleware('cookie')->process($request->withAddedHeader('X-CSRF-Token', $csrf), $handler)->getStatusCode());
    }
}
