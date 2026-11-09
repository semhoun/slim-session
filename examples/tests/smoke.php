<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

$database = tempnam(sys_get_temp_dir(), 'slim-example-smoke-');
putenv('EXAMPLE_SECRET_BASE64=' . base64_encode(random_bytes(32)));
putenv('EXAMPLE_SQLITE_PATH=' . $database);
putenv('EXAMPLE_API_ORIGIN=http://localhost:8080');
putenv('EXAMPLE_WIDGET_ORIGIN=http://localhost:8081');
putenv('EXAMPLE_ALLOW_INSECURE_LOCALHOST=1');
$pdo = new PDO('sqlite:' . $database);
$pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/sqlite.sql'));
$app = require dirname(__DIR__) . '/app.php';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
};
$request = static function (string $method, string $path, array $headers = [], ?array $body = null) use ($app) {
    $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost:8080' . $path);
    foreach ($headers as $name => $value) { $request = $request->withHeader($name, $value); }
    if ($body !== null) {
        $request = $request->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
    }
    if (isset($headers['Cookie'])) {
        $cookies = [];
        foreach (explode(';', $headers['Cookie']) as $cookie) {
            [$key, $value] = explode('=', trim($cookie), 2);
            $cookies[$key] = $value;
        }
        $request = $request->withCookieParams($cookies);
    }
    return $app->handle($request);
};
$cookieFrom = static function ($response, string $name): string {
    foreach ($response->getHeader('Set-Cookie') as $cookie) {
        if (str_starts_with($cookie, $name . '=')) { return explode(';', $cookie, 2)[0]; }
    }
    throw new RuntimeException('Expected cookie not issued.');
};
try {
    foreach (['cookie', 'header'] as $mode) {
        $base = '/api/' . $mode . '/';
        $origin = $mode === 'cookie' ? 'http://localhost:8080' : 'http://localhost:8081';
        $headers = ['Origin' => $origin];
        $assert($request('GET', $base . 'session/status')->getStatusCode() === 200, 'Missing credentials should have unauthenticated status.');
        $assert($request('GET', $base . 'data')->getStatusCode() === 401, 'Missing credentials must not create session.');
        $assert($request('POST', $base . 'login', $headers, ['username' => 'demo'])->getStatusCode() === 403, 'Login without proof accepted.');
        $assert($request('POST', $base . 'login-challenge', ['X-Login-Bootstrap' => '1'])->getStatusCode() === 403, 'Missing login origin accepted.');
        $challenge = $request('POST', $base . 'login-challenge', $headers + ['X-Login-Bootstrap' => '1']);
        $assert($challenge->getStatusCode() === 200, 'Login challenge failed.');
        $proof = json_decode((string) $challenge->getBody(), true, flags: JSON_THROW_ON_ERROR)['proof'];
        $loginHeaders = $headers + ['X-Login-CSRF' => $proof];
        if ($mode === 'cookie') { $loginHeaders['Cookie'] = $cookieFrom($challenge, 'example_login_proof'); }
        $badLoginHeaders = $loginHeaders;
        $badLoginHeaders['X-Login-CSRF'] = str_repeat('0', strlen($proof));
        $assert($request('POST', $base . 'login', $badLoginHeaders, ['username' => 'demo'])->getStatusCode() === 403, 'Forged login proof accepted.');
        $login = $request('POST', $base . 'login', $loginHeaders, ['username' => 'demo']);
        $assert($login->getStatusCode() === 201, 'Explicit session login failed for ' . $mode . ': ' . $login->getStatusCode() . ' ' . (string) $login->getBody());
        $assert($login->getHeaderLine('Access-Control-Expose-Headers') === ($mode === 'cookie' ? 'X-CSRF-Token' : 'X-Session-Token')
            . ', X-Session-Token-Expires-At, X-Session-Idle-Expires-At, X-Session-Absolute-Expires-At', 'Exposed headers do not match transport.');
        if ($mode === 'cookie') {
            $headers['Cookie'] = $cookieFrom($login, '__Host-example-session');
            $assert(!$login->hasHeader('X-Session-Token'), 'Cookie JWT leaked into response token header.');
            $assert(str_contains($login->getHeaderLine('Set-Cookie'), 'HttpOnly') && str_contains($login->getHeaderLine('Set-Cookie'), 'Secure'), 'Cookie security missing.');
        } else {
            $headers['Authorization'] = 'Bearer ' . $login->getHeaderLine('X-Session-Token');
            $assert(!$login->hasHeader('Set-Cookie'), 'Header authentication emitted cookies.');
        }
        $status = $request('GET', $base . 'session/status', $headers);
        $encoded = $mode === 'cookie' ? explode('=', $headers['Cookie'], 2)[1] : substr($headers['Authorization'], 7);
        $claims = json_decode(base64_decode(strtr(explode('.', $encoded)[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        $assert(\Semhoun\SlimSession\SessionId::valid($claims['sub']), 'Session ID is not canonical UUIDv4.');
        $assert($claims['sv'] === 2 && preg_match('/^[a-f0-9]{64}$/D', $claims['svp']) === 1, 'Missing versioned verification proof.');
        $metadata = json_decode((string) $status->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $assert(!array_key_exists('svp', $metadata) && !array_key_exists('verificationSecret', $metadata), 'Verification secret leaked in metadata.');
        $assert($metadata['authenticated'] === true && $metadata['transport'] === $mode, 'Status protocol mismatch.');
        if ($mode === 'cookie') {
            $assert($request('POST', $base . 'data', $headers)->getStatusCode() === 403, 'Cookie mutation without CSRF accepted.');
            $headers['X-CSRF-Token'] = $metadata['csrfToken'];
            $assert($request('POST', $base . 'data', array_replace($headers, ['Origin' => 'http://evil.invalid']))->getStatusCode() === 403, 'Foreign mutation origin accepted.');
        }
        $assert($request('POST', $base . 'data', $headers)->getStatusCode() === 200, 'Data mutation failed.');
        $data = json_decode((string) $request('GET', $base . 'data', $headers)->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $assert($data['visits'] === 1 && is_string($data['notice']), 'Data and flash not saved.');
        $data = json_decode((string) $request('GET', $base . 'data', $headers)->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $assert($data['notice'] === null, 'Flash consumption not persisted.');
        $assert($request('POST', $base . 'session/refresh', $headers)->getStatusCode() === 200, 'Refresh failed.');
        $oldHeaders = $headers;
        $rotation = $request('POST', $base . 'rotate', $headers);
        $assert($rotation->getStatusCode() === 200, 'Rotation failed.');
        if ($mode === 'cookie') {
            $headers['Cookie'] = $cookieFrom($rotation, '__Host-example-session');
            $headers['X-CSRF-Token'] = $rotation->getHeaderLine('X-CSRF-Token');
        } else {
            $headers['Authorization'] = 'Bearer ' . $rotation->getHeaderLine('X-Session-Token');
        }
        $assert($request('GET', $base . 'data', $oldHeaders)->getStatusCode() === 401, 'Old rotation credential remains valid.');
        $stream = $request('GET', $base . 'stream', $headers);
        $assert($stream->getStatusCode() === 200, 'Stream setup failed.');
        $assert(str_contains($stream->getBody()->read(8192), '"valid":true'), 'Stream did not validate.');
        $assert($request('POST', $base . 'data', $headers)->getStatusCode() === 200, 'Stream kept a lease.');
        $assert($request('POST', $base . 'session/logout', $headers)->getStatusCode() < 300, 'Logout failed.');
        $assert(str_contains($stream->getBody()->read(8192), 'revoked-or-expired'), 'Stream did not notice revocation.');
        $assert($request('GET', $base . 'data', $headers)->getStatusCode() === 401, 'Revoked session accepted.');
    }
    $preflight = $request('OPTIONS', '/api/header/data', ['Origin' => 'http://localhost:8081',
        'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'authorization, content-type']);
    $assert($preflight->getStatusCode() === 204 && $preflight->getHeaderLine('Access-Control-Allow-Origin') === 'http://localhost:8081', 'Widget preflight failed.');
    $assert(!$preflight->hasHeader('Access-Control-Allow-Credentials'), 'Header preflight allowed ambient credentials.');
    $assert($request('OPTIONS', '/api/header/data', ['Origin' => 'http://localhost:8081', 'Access-Control-Request-Method' => 'DELETE'])->getStatusCode() === 403, 'Unexpected preflight method accepted.');
    $assert($request('OPTIONS', '/api/header/data', ['Origin' => 'http://localhost:8081', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'x-untrusted'])->getStatusCode() === 403, 'Unexpected preflight header accepted.');
    $assert($request('OPTIONS', '/api/header/data', ['Origin' => 'http://evil.invalid', 'Access-Control-Request-Method' => 'POST'])->getStatusCode() === 403, 'Foreign origin preflight accepted.');
    $assert($request('POST', '/api/cookie/login-challenge', ['Origin' => 'null', 'X-Login-Bootstrap' => '1'])->getStatusCode() === 403, 'Null origin accepted.');
    fwrite(STDOUT, "Passed $checks isolated example smoke checks.\n");
} finally {
    unset($pdo, $app);
    unlink($database);
}
