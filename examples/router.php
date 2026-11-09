<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$assets = [
    '/' => ['public/cookie.html', 'text/html'],
    '/cookie.html' => ['public/cookie.html', 'text/html'],
    '/widget.html' => ['public/widget.html', 'text/html'],
    '/assets/demo.js' => ['public/demo.js', 'text/javascript'],
    '/assets/style.css' => ['public/style.css', 'text/css'],
    '/assets/slim-session.js' => ['../browser/dist/slim-session.js', 'text/javascript'],
];
require __DIR__ . '/config.php';
try {
    $config = exampleConfig();
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self' "
        . $config['api'] . "; frame-src " . $config['widget'] . "; frame-ancestors 'self' " . $config['api'] . "; base-uri 'none'; form-action 'none'");
    if ($path === '/assets/config.js') {
        header('Content-Type: text/javascript');
        header('Cache-Control: no-store');
        print 'window.ExampleConfig = ' . json_encode(['apiOrigin' => $config['api'], 'widgetOrigin' => $config['widget']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) . ';';
    } elseif (isset($assets[$path])) {
        [$file, $type] = $assets[$path];
        if (!is_file(__DIR__ . '/' . $file)) {
            http_response_code(503);
            print 'Build the browser package first: npm run build in browser/.';
        } else {
            header('Content-Type: ' . $type);
            readfile(__DIR__ . '/' . $file);
        }
    } elseif (is_string($path) && str_starts_with($path, '/api/')) {
        // app.php loads config.php too; require_once avoids redeclaring helper functions.
        $app = require __DIR__ . '/app.php';
        $app->run();
    } else {
        http_response_code(404);
    }
} catch (Throwable $error) {
    http_response_code(503);
    header('Cache-Control: no-store');
    print 'Example unavailable. Check environment, isolated schema setup, and server logs.';
    error_log('Example startup failure: ' . $error::class);
}
