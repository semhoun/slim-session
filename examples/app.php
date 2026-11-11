<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Semhoun\SlimSession\Clock\SystemClock;
use Semhoun\SlimSession\Http\{HttpConfig, SessionMiddleware, SessionGuard, StatusHandler, RefreshHandler, LogoutHandler};
use Semhoun\SlimSession\Jwt\JwtService;
use Semhoun\SlimSession\{SessionConfig, SessionManager, SessionState};
use Semhoun\SlimSession\Storage\PdoSessionStore;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
require __DIR__ . '/security.php';
require __DIR__ . '/RevalidatingStream.php';

$config = exampleConfig();
$clock = new SystemClock();
$responses = new ResponseFactory();
$store = new PdoSessionStore(examplePdo($config));
$app = AppFactory::create($responses);

foreach (['cookie', 'header'] as $mode) {
    $manager = new SessionManager($store, new SessionConfig('example-' . $mode), $clock);
    // Distinct issuers/audiences and namespaces prevent transport cross-authentication.
    $jwt = new JwtService($clock, $config['api'] . '/' . $mode, 'example-' . $mode, ['local' => $config['secret']], 'local');
    $http = new HttpConfig($mode, $config['api'], cookieName: '__Host-example-session');
    $middleware = new SessionMiddleware($manager, $jwt, $http, $clock, $responses,
        static fn (Request $request): bool => !preg_match('~/session/(status|refresh)$~D', $request->getUri()->getPath()));

    $app->group('/api/' . $mode, function ($group) use ($mode, $config, $responses, $manager): void {
        $group->post('/login-challenge', function (Request $request, Response $response) use ($mode, $config): Response {
            $origin = exampleAllowedOrigin($request, $mode, $config);
            if ($origin === null || $request->getHeaderLine('X-Login-Bootstrap') !== '1') {
                return exampleJson($response, ['error' => 'Origin and explicit bootstrap header required.'], 403);
            }
            $proof = exampleLoginProof($origin, $mode, $config['secret']);
            if ($mode === 'cookie') {
                $response = $response->withAddedHeader('Set-Cookie', 'example_login_proof=' . $proof
                    . '; Path=/api/cookie; Max-Age=300; HttpOnly; SameSite=Strict' . ($config['insecure'] ? '' : '; Secure'));
            }
            return exampleJson($response, ['proof' => $proof]);
        });
        $group->post('/login', function (Request $request, Response $response) use ($mode, $config): Response {
            if (!exampleValidLoginProof($request, $mode, $config)) {
                return exampleJson($response, ['error' => 'Invalid login proof or origin.'], 403);
            }
            $context = SessionGuard::context($request);
            if ($context->state() !== SessionState::Absent) {
                return exampleJson($response, ['error' => 'Log out before creating a new demo session.'], 409);
            }
            $body = json_decode((string) $request->getBody(), true);
            // Deliberately simulated authentication, not a password or identity-provider example.
            if (!is_array($body) || ($body['username'] ?? null) !== 'demo') {
                return exampleJson($response, ['error' => 'The simulated username is demo.'], 401);
            }
            $context->create(['displayName' => 'Demo visitor', 'visits' => 0]);
            $context->flash('notice', 'Session explicitly created after simulated authentication.');
            return exampleJson($response, ['created' => true], 201);
        });
        $group->get('/session/status', fn (Request $request): Response => (new StatusHandler($responses))->handle($request));
        $group->post('/session/refresh', fn (Request $request): Response => (new RefreshHandler($responses))->handle($request));
        $group->post('/session/logout', fn (Request $request): Response => (new LogoutHandler($responses))->handle($request));
        $group->get('/data', function (Request $request, Response $response): Response {
            $session = SessionGuard::requireSession($request);
            return exampleJson($response, ['displayName' => $session->get('displayName'), 'visits' => $session->get('visits'),
                'notice' => $session->consumeFlash('notice')]);
        });
        $group->post('/data', function (Request $request, Response $response): Response {
            $session = SessionGuard::requireSession($request);
            $session->set('visits', $session->get('visits', 0) + 1);
            $session->flash('notice', 'A mutation was saved; read data to consume this flash.');
            return exampleJson($response, ['visits' => $session->get('visits')]);
        });
        $group->post('/flash', function (Request $request, Response $response): Response {
            SessionGuard::requireSession($request)->flash('notice', 'One-time demo flash.');
            return exampleJson($response, ['queued' => true]);
        });
        $group->post('/rotate', function (Request $request, Response $response): Response {
            SessionGuard::requireSession($request)->rotate(true);
            return exampleJson($response, ['rotated' => true]);
        });
        $group->get('/stream', function (Request $request, Response $response) use ($manager): Response {
            $session = SessionGuard::requireSession($request);
            $id = $session->record()->id;
            $session->saveAndClose();
            // Later writes on $session now throw. Every stream tick uses a fresh short lease.
            return $response->withHeader('Content-Type', 'text/event-stream')->withHeader('X-Accel-Buffering', 'no')
                ->withBody(new RevalidatingStream($manager, $id));
        });
    })->add($middleware);
}

$app->addRoutingMiddleware();
// Errors must wrap session middleware, so uncaught application exceptions discard mutations.
$app->addErrorMiddleware(false, false, false);
$app->add(function (Request $request, RequestHandlerInterface $handler) use ($config, $responses): Response {
    $path = $request->getUri()->getPath();
    if (!preg_match('~^/api/(cookie|header)/~', $path, $match)) {
        return $handler->handle($request);
    }
    $origin = $request->getHeaderLine('Origin');
    $allowed = exampleAllowedOrigin($request, $match[1], $config);
    if ($origin !== '' && $allowed === null) {
        return $responses->createResponse(403)->withHeader('Vary', 'Origin')->withHeader('Cache-Control', 'no-store');
    }
    if ($request->getMethod() === 'OPTIONS') {
        $requested = array_filter(array_map('trim', explode(',', strtolower($request->getHeaderLine('Access-Control-Request-Headers')))));
        if ($allowed === null || !in_array($request->getHeaderLine('Access-Control-Request-Method'), ['GET', 'POST'], true)
            || array_diff($requested, ['authorization', 'content-type', 'x-csrf-token', 'x-login-csrf', 'x-login-bootstrap']) !== []) {
            return $responses->createResponse(403)->withHeader('Vary', 'Origin');
        }
        $response = $responses->createResponse(204)->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-CSRF-Token, X-Login-CSRF, X-Login-Bootstrap');
    } else {
        $response = $handler->handle($request);
    }
    $response = $response->withHeader('Vary', 'Origin')->withHeader('Cache-Control', 'no-store');
    if ($allowed !== null) {
        $response = $response->withHeader('Access-Control-Allow-Origin', $allowed)
            ->withHeader('Access-Control-Expose-Headers', ($match[1] === 'cookie' ? 'X-CSRF-Token' : 'X-Session-Token')
                . ', X-Session-Token-Expires-At, X-Session-Token-Refresh-At, X-Session-Idle-Expires-At, X-Session-Absolute-Expires-At');
        if ($match[1] === 'cookie') { $response = $response->withHeader('Access-Control-Allow-Credentials', 'true'); }
    }
    return $response;
});

return $app;
