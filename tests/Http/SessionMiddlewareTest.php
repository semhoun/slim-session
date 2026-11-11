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
use Semhoun\SlimSession\Http\AdmissionMetadata;
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

    private function middleware(string $transport = 'header', ?\Closure $activity = null, bool $csrfRequired = true, bool $originRequired = true): SessionMiddleware
    {
        return new SessionMiddleware($this->manager, $this->jwt, new HttpConfig($transport, 'https://example.test', csrfRequired: $csrfRequired, originRequired: $originRequired), $this->clock, $this->factory, $activity);
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
        return [$record->id, $this->jwt->issue($record->id, $record->absoluteExpiresAt, $record->verificationSecret), $record->csrfToken];
    }

    private function request(string $token, string $method = 'GET'): ServerRequest
    {
        return new ServerRequest($method, 'https://example.test/session', ['Authorization' => 'Bearer ' . $token]);
    }

    public function testSignedWrongProofFailsBeforeBusinessAndReleasesLeaseInBothTransports(): void
    {
        foreach (['header', 'cookie'] as $transport) {
            [$id, $valid, $csrf] = $this->session();
            $record = $this->store->read('http-test', $id);
            foreach ([bin2hex(random_bytes(32)), $csrf] as $wrongProof) {
                $forged = $this->jwt->issue($id, $record->absoluteExpiresAt, $wrongProof);
                $request = $transport === 'header' ? $this->request($forged, 'POST')
                    : new ServerRequest('POST', '/', ['Cookie' => '__Host-slim-session=' . $forged,
                        'Origin' => 'https://example.test', 'X-CSRF-Token' => $csrf]);
                $response = $this->middleware($transport)->process($request, $this->handler(function () {
                    self::fail('A signed token with the wrong record proof must not reach business code.');
                }));
                self::assertSame(401, $response->getStatusCode());
                self::assertSame($record, $this->store->read('http-test', $id));
                self::assertFalse($response->hasHeader('X-Session-Token'));
                $loaded = $this->manager->load($id);
                $loaded->discard();
            }
            $request = $transport === 'header' ? $this->request($valid)
                : new ServerRequest('GET', '/', ['Cookie' => '__Host-slim-session=' . $valid]);
            self::assertSame(200, $this->middleware($transport)->process($request, new StatusHandler($this->factory))->getStatusCode());
        }
    }

    public function testRotationInvalidatesOldIdAndProofInBothTransports(): void
    {
        foreach (['header', 'cookie'] as $transport) {
            [$id, $oldToken] = $this->session();
            $old = $this->store->read('http-test', $id);
            $context = $this->manager->load($id);
            $context->rotate();
            $new = $context->record();
            $context->saveAndClose();
            self::assertNotSame($old->verificationSecret, $new->verificationSecret);
            self::assertNotSame($old->csrfToken, $new->csrfToken);
            self::assertNull($this->store->read('http-test', $id));
            foreach ([$oldToken, $this->jwt->issue($new->id, $new->absoluteExpiresAt, $old->verificationSecret)] as $invalid) {
                $request = $transport === 'header' ? $this->request($invalid)
                    : new ServerRequest('GET', '/', ['Cookie' => '__Host-slim-session=' . $invalid]);
                self::assertSame(401, $this->middleware($transport)->process($request, $this->handler(function () {
                    self::fail('Rotated credentials must not reach business code.');
                }))->getStatusCode());
            }
            $valid = $this->jwt->issue($new->id, $new->absoluteExpiresAt, $new->verificationSecret);
            $request = $transport === 'header' ? $this->request($valid)
                : new ServerRequest('GET', '/', ['Cookie' => '__Host-slim-session=' . $valid]);
            self::assertSame(200, $this->middleware($transport)->process($request, new StatusHandler($this->factory))->getStatusCode());
        }
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
        self::assertSame((string) $token->expiresAt, $response->getHeaderLine('X-Session-Token-Expires-At'));
        self::assertTrue($this->store->read('http-test', $token->sessionId)->data['saved']);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testAdmissionDescribesIncomingCredentialEvenWhenResponseRenewsIt(): void
    {
        foreach (['header', 'cookie'] as $transport) {
            [, $token] = $this->session();
            $expiry = $this->jwt->verify($token)->expiresAt;
            $this->clock->time = $expiry - 10;
            $request = $transport === 'header' ? $this->request($token)
                : new ServerRequest('GET', '/', ['Cookie' => '__Host-slim-session=' . $token]);
            $admission = null;
            $response = $this->middleware($transport)->process($request, $this->handler(function ($request) use ($transport, $expiry, &$admission) {
                $admission = SessionGuard::admission($request);
                self::assertInstanceOf(AdmissionMetadata::class, $admission);
                self::assertSame($transport, $admission->transport);
                self::assertSame($expiry, $admission->expiresAt);
                self::assertSame(['transport', 'expiresAt'], array_keys(get_object_vars($admission)));
                return $this->factory->createResponse();
            }));
            self::assertSame(200, $response->getStatusCode());
            self::assertGreaterThan($admission->expiresAt, (int) $response->getHeaderLine('X-Session-Token-Expires-At'));
            self::assertSame($expiry, $admission->expiresAt);
        }
    }

    public function testAbsentRequestOverwritesInjectedAdmissionAndCreationDoesNotInventOne(): void
    {
        $request = (new ServerRequest('POST', '/login'))
            ->withAttribute(SessionGuard::ADMISSION_ATTRIBUTE, new AdmissionMetadata('header', PHP_INT_MAX));
        $response = $this->middleware()->process($request, $this->handler(function ($request) {
            self::assertNull(SessionGuard::admission($request));
            SessionGuard::context($request)->create();
            self::assertNull(SessionGuard::admission($request));
            return $this->factory->createResponse();
        }));
        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('X-Session-Token'));
    }

    public function testInjectedAdmissionCannotBypassCredentialOrRecordValidation(): void
    {
        [$id, $token] = $this->session();
        $record = $this->store->read('http-test', $id);
        $foreignAudience = new JwtService($this->clock, 'http-test', 'foreign', ['current' => str_repeat('a', 32)], 'current');
        $foreignIssuer = new JwtService($this->clock, 'foreign', 'http-test', ['current' => str_repeat('a', 32)], 'current');
        $wrongKey = new JwtService($this->clock, 'http-test', 'http-test', ['current' => str_repeat('b', 32)], 'current');
        $credentials = ['invalid', $foreignAudience->issue($id, $record->absoluteExpiresAt, $record->verificationSecret),
            $foreignIssuer->issue($id, $record->absoluteExpiresAt, $record->verificationSecret),
            $wrongKey->issue($id, $record->absoluteExpiresAt, $record->verificationSecret),
            $this->jwt->issue($id, $record->absoluteExpiresAt, str_repeat('b', 64))];
        foreach ($credentials as $credential) {
            $request = $this->request($credential)->withAttribute(SessionGuard::ADMISSION_ATTRIBUTE, new AdmissionMetadata('header', PHP_INT_MAX));
            $response = $this->middleware()->process($request, $this->handler(function () {
                self::fail('Injected metadata cannot authenticate a request.');
            }));
            self::assertSame(401, $response->getStatusCode());
            self::assertFalse($response->hasHeader('X-Session-Token'));
        }
        $this->clock->time += 86400;
        $expiredRecordToken = $this->jwt->issue($id, $record->absoluteExpiresAt, $record->verificationSecret);
        foreach ([$token, $expiredRecordToken] as $credential) {
            self::assertSame(401, $this->middleware()->process($this->request($credential), $this->handler(function () {
                self::fail('Expired credentials or server sessions cannot supply admission metadata.');
            }))->getStatusCode());
        }
        $context = $this->manager->create();
        $record = $context->record();
        $revoked = $this->jwt->issue($record->id, $record->absoluteExpiresAt, $record->verificationSecret);
        $context->destroy();
        self::assertSame(401, $this->middleware()->process($this->request($revoked), $this->handler(function () {
            self::fail('Revoked sessions cannot supply admission metadata.');
        }))->getStatusCode());
    }

    public function testNewAuthenticationFinalizesSameContextAndRejectsOldCredential(): void
    {
        foreach ([['header', false], ['header', true], ['cookie', false], ['cookie', true]] as [$transport, $earlyClose]) {
            [$id, $token, $csrf] = $this->session();
            $old = $this->store->read('http-test', $id);
            $this->clock->time += 10;
            $request = $transport === 'header' ? $this->request($token, 'POST')
                : new ServerRequest('POST', '/', ['Cookie' => '__Host-slim-session=' . $token,
                    'Origin' => 'https://example.test', 'X-CSRF-Token' => $csrf]);
            $response = $this->middleware($transport)->process($request, $this->handler(function ($request) use ($earlyClose) {
                $context = SessionGuard::requireSession($request);
                $context->startNew(['identity' => 456], absoluteExpiresAt: $this->clock->time + 100);
                self::assertSame($context, SessionGuard::context($request));
                self::assertNotNull(SessionGuard::admission($request));
                if ($earlyClose) { $context->saveAndClose(); }
                return $this->factory->createResponse();
            }));
            self::assertSame(200, $response->getStatusCode());
            $encoded = $response->getHeaderLine('X-Session-Token');
            if ($transport === 'cookie') {
                self::assertSame('', $encoded);
                $encoded = explode(';', substr($response->getHeaderLine('Set-Cookie'), strlen('__Host-slim-session=')))[0];
            }
            $verified = $this->jwt->verify($encoded);
            self::assertNotSame($id, $verified->sessionId);
            self::assertSame($this->clock->time + 100, $verified->expiresAt);
            $new = $this->store->read('http-test', $verified->sessionId);
            self::assertSame(['identity' => 456], $new->data);
            self::assertNotSame($old->verificationSecret, $new->verificationSecret);
            self::assertNull($this->store->read('http-test', $id));
            self::assertSame(401, $this->middleware()->process($this->request($token), new StatusHandler($this->factory))->getStatusCode());
        }
    }

    public function testReplacementSaveFailureNeverEmitsNewCredential(): void
    {
        [$id, $token] = $this->session();
        $response = $this->middleware()->process($this->request($token), $this->handler(function ($request) {
            SessionGuard::requireSession($request)->startNew();
            $this->clock->time += 31;
            return $this->factory->createResponse();
        }));
        self::assertSame(409, $response->getStatusCode());
        self::assertNull($this->store->read('http-test', $id));
        self::assertFalse($response->hasHeader('X-Session-Token'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testCaughtReplacementCreationFailureStillFinalizesUnauthenticated(): void
    {
        [$id, $token] = $this->session();
        $store = $this->createMock(\Semhoun\SlimSession\Storage\SessionStore::class);
        $store->method('acquire')->willReturnCallback(fn (...$args) => $this->store->acquire(...$args));
        $store->method('read')->willReturnCallback(fn (...$args) => $this->store->read(...$args));
        $store->method('revoke')->willReturnCallback(fn (...$args) => $this->store->revoke(...$args));
        $store->method('release')->willReturnCallback(fn (...$args) => $this->store->release(...$args));
        $store->method('create')->willThrowException(new \Semhoun\SlimSession\Exception\StorageUnavailableException('Test creation failure.'));
        $manager = new SessionManager($store, new SessionConfig('http-test'), $this->clock);
        $middleware = new SessionMiddleware($manager, $this->jwt, new HttpConfig('header', 'https://example.test'), $this->clock, $this->factory);
        $response = $middleware->process($this->request($token), $this->handler(function ($request) {
            try {
                SessionGuard::requireSession($request)->startNew();
                self::fail('Creation must fail.');
            } catch (\Semhoun\SlimSession\Exception\StorageUnavailableException) {
                return (new StatusHandler($this->factory))->handle($request);
            }
        }));
        self::assertSame(['authenticated' => false, 'transport' => 'header'], json_decode((string) $response->getBody(), true));
        self::assertFalse($response->hasHeader('X-Session-Token'));
        self::assertNull($this->store->read('http-test', $id));
    }

    public function testCaughtReplacementLeaseLossNeverEmitsCredential(): void
    {
        [$id, $token] = $this->session();
        $response = $this->middleware()->process($this->request($token, 'POST'), $this->handler(function ($request) {
            $context = SessionGuard::requireSession($request);
            $context->startNew(['identity' => 456]);
            $context->set('unsaved', true);
            $this->clock->time += 31;
            try {
                $context->renewLease();
                self::fail('Lease renewal must fail.');
            } catch (\Semhoun\SlimSession\Exception\WriteConflictException) {
                return $this->factory->createResponse()->withHeader(SessionMiddleware::METADATA_RESPONSE, '1');
            }
        }));
        self::assertSame(['authenticated' => false, 'transport' => 'header'], json_decode((string) $response->getBody(), true));
        self::assertFalse($response->hasHeader('X-Session-Token'));
        self::assertNull($this->store->read('http-test', $id));
    }

    public function testFinalizationUsesIssuedExpiryWithoutReadingJwtClockAgain(): void
    {
        $clock = new class implements ClockInterface {
            public int $calls = 0;
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@' . (1700000000 + $this->calls++));
            }
        };
        $this->jwt = new JwtService($clock, 'http-test', 'http-test', ['current' => str_repeat('a', 32)], 'current', lifetime: 1);
        $response = $this->middleware()->process(new ServerRequest('POST', '/login'), $this->handler(function ($request) {
            SessionGuard::context($request)->create();
            return $this->factory->createResponse();
        }));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $clock->calls);
        self::assertNotSame('', $response->getHeaderLine('X-Session-Token'));
        self::assertSame('1700000001', $response->getHeaderLine('X-Session-Token-Expires-At'));
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
        $newRecord = $this->store->read('http-test', $newId);
        $newToken = $this->jwt->issue($newId, $newRecord->absoluteExpiresAt, $newRecord->verificationSecret);
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
        $token = $this->jwt->issue($record->id, $record->absoluteExpiresAt, $record->verificationSecret);
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

    public function testCookieProofChecksAreIndependentAndReleaseLeases(): void
    {
        [$id, $token, $csrf] = $this->session();
        foreach ([true, false] as $csrfRequired) {
            foreach ([true, false] as $originRequired) {
                $middleware = $this->middleware('cookie', csrfRequired: $csrfRequired, originRequired: $originRequired);
                foreach ([null, '', 'null', 'https://foreign.test', ['https://example.test', 'https://example.test'], 'https://example.test'] as $origin) {
                    foreach ([null, '', 'incorrect', [$csrf, $csrf], $csrf] as $proof) {
                        $request = new ServerRequest('POST', '/', ['Cookie' => '__Host-slim-session=' . $token]);
                        if ($origin !== null) {
                            $request = $request->withHeader('Origin', $origin);
                        }
                        if ($proof !== null) {
                            $request = $request->withHeader('X-CSRF-Token', $proof);
                        }
                        $accepted = (!$originRequired || $origin === 'https://example.test') && (!$csrfRequired || $proof === $csrf);
                        $called = false;
                        $response = $middleware->process($request, $this->handler(function ($request) use (&$called) {
                            $called = true;
                            return (new StatusHandler($this->factory))->handle($request->withMethod('GET'));
                        }));
                        self::assertSame($accepted ? 200 : 403, $response->getStatusCode());
                        self::assertSame($accepted, $called);
                        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
                        if ($accepted) {
                            self::assertSame($csrf, $response->getHeaderLine('X-CSRF-Token'));
                            self::assertSame($csrf, json_decode((string) $response->getBody(), true)['csrfToken']);
                        }
                        $loaded = $this->manager->load($id);
                        $loaded->discard();
                    }
                }
            }
        }
    }

    public function testProofOptionsPreserveSafeMethodsAndBearerTransport(): void
    {
        [, $token] = $this->session();
        foreach ([true, false] as $csrfRequired) {
            foreach ([true, false] as $originRequired) {
                foreach (['cookie', 'header'] as $transport) {
                    $middleware = $this->middleware($transport, csrfRequired: $csrfRequired, originRequired: $originRequired);
                    foreach ($transport === 'cookie' ? ['GET', 'HEAD', 'OPTIONS'] : ['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                        $request = $transport === 'cookie'
                            ? new ServerRequest($method, '/', ['Cookie' => '__Host-slim-session=' . $token])
                            : $this->request($token, $method);
                        $request = $request->withHeader('Origin', ['null', 'https://foreign.test'])->withHeader('X-CSRF-Token', ['invalid', 'invalid']);
                        self::assertSame(200, $middleware->process($request, $this->handler(fn () => $this->factory->createResponse()))->getStatusCode());
                    }
                }
            }
        }
    }

    public function testDisabledProofChecksStillRequireValidStoredSession(): void
    {
        $middleware = $this->middleware('cookie', csrfRequired: false, originRequired: false);
        [$id, $revoked] = $this->session();
        $context = $this->manager->load($id);
        $context->destroy();
        [$id, $expired] = $this->session();
        $record = $this->store->read('http-test', $id);
        $wrongProof = $this->jwt->issue($id, $record->absoluteExpiresAt, str_repeat('b', 64));
        foreach (['forged', $revoked, $wrongProof] as $credential) {
            self::assertSame(401, $middleware->process(new ServerRequest('POST', '/', ['Cookie' => '__Host-slim-session=' . $credential]), $this->handler(function () {
                self::fail('A valid JWT and its matching stored record are still required.');
            }))->getStatusCode());
        }
        $this->clock->time += 86401;
        // A fresh JWT cannot revive a session whose idle deadline has elapsed.
        $expiredRecord = $this->jwt->issue($id, $record->absoluteExpiresAt, $record->verificationSecret);
        foreach ([$expired, $expiredRecord] as $credential) {
            $response = $middleware->process(new ServerRequest('POST', '/', ['Cookie' => '__Host-slim-session=' . $credential]), $this->handler(function () {
                self::fail('Disabling request proofs must not bypass authentication.');
            }));
            self::assertSame(401, $response->getStatusCode());
        }
    }

    public function testDisabledProofChecksStillRequireStorageAndReleaseLeaseOnFailure(): void
    {
        [$id, $token] = $this->session();
        $lease = new \Semhoun\SlimSession\Lease('http-test', $id, 'test-owner', 1700000030);
        $store = $this->createMock(\Semhoun\SlimSession\Storage\SessionStore::class);
        $store->expects(self::once())->method('acquire')->willReturn($lease);
        $store->expects(self::once())->method('read')->with('http-test', $id)
            ->willThrowException(new \Semhoun\SlimSession\Exception\StorageUnavailableException('Unavailable test storage.'));
        $store->expects(self::once())->method('release')->with($lease);
        $manager = new SessionManager($store, new SessionConfig('http-test'), $this->clock);
        $middleware = new SessionMiddleware($manager, $this->jwt, new HttpConfig('cookie', 'https://example.test', csrfRequired: false, originRequired: false), $this->clock, $this->factory);
        $request = (new ServerRequest('POST', '/', ['Cookie' => '__Host-slim-session=' . $token]))
            ->withAttribute(SessionGuard::ADMISSION_ATTRIBUTE, new AdmissionMetadata('cookie', PHP_INT_MAX));
        $response = $middleware->process($request, $this->handler(function () {
            self::fail('A valid JWT must not bypass unavailable storage.');
        }));
        self::assertSame(503, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testProofOptionsPreserveExistingConfigurationValidation(): void
    {
        $legacy = new HttpConfig('cookie', 'https://example.test', [], '__Host-custom', 'Strict', 'Authorization', 'X-Token', 'X-Proof');
        self::assertTrue($legacy->csrfRequired);
        self::assertTrue($legacy->originRequired);
        foreach ([
            ['transport' => 'invalid'],
            ['canonicalOrigin' => 'https://example.test/path'],
            ['allowedOrigins' => ['null']],
            ['cookieName' => 'session'],
            ['sameSite' => 'invalid'],
            ['sameSite' => 'None'],
            ['credentialHeader' => 'Bad Header'],
            ['responseTokenHeader' => 'Bad Header'],
            ['csrfHeader' => 'Bad Header'],
        ] as $invalid) {
            try {
                new HttpConfig(...array_replace(['transport' => 'cookie', 'canonicalOrigin' => 'https://example.test', 'csrfRequired' => false, 'originRequired' => false], $invalid));
                self::fail('Disabling proofs must not bypass configuration validation.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
