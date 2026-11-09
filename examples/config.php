<?php
declare(strict_types=1);

function exampleConfig(): array
{
    $secret = base64_decode((string) getenv('EXAMPLE_SECRET_BASE64'), true);
    if ($secret === false || strlen($secret) < 32) {
        throw new RuntimeException('Set EXAMPLE_SECRET_BASE64 to at least 32 random bytes encoded as base64.');
    }
    $api = rtrim(getenv('EXAMPLE_API_ORIGIN') ?: 'https://localhost:8443', '/');
    $widget = rtrim(getenv('EXAMPLE_WIDGET_ORIGIN') ?: 'https://localhost:8444', '/');
    $insecure = getenv('EXAMPLE_ALLOW_INSECURE_LOCALHOST') === '1';
    foreach ([$api, $widget] as $origin) {
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')
            || ($parts['scheme'] !== 'https' && !($insecure && $parts['scheme'] === 'http'
                && in_array($parts['host'], ['localhost', '127.0.0.1'], true)))) {
            throw new RuntimeException('Example origins must be explicit HTTPS origins (or opted-in loopback HTTP).');
        }
    }
    $database = getenv('EXAMPLE_SQLITE_PATH') ?: __DIR__ . '/var/session.sqlite';
    if (!str_starts_with($database, '/')) {
        throw new RuntimeException('EXAMPLE_SQLITE_PATH must be absolute.');
    }
    $allowedRoots = [__DIR__ . '/var/', rtrim(sys_get_temp_dir(), '/') . '/'];
    $parent = realpath(dirname($database));
    $isolated = false;
    foreach ($allowedRoots as $root) {
        if ($parent !== false && str_starts_with($parent . '/', $root)) {
            $isolated = true;
        }
    }
    if (!$isolated || is_link($database) || str_contains(basename($database), "\0")) {
        throw new RuntimeException('SQLite must live in examples/var or the system temporary directory. Create its parent first.');
    }
    return compact('secret', 'api', 'widget', 'insecure', 'database');
}

function examplePdo(array $config): PDO
{
    if (!is_file($config['database'])) {
        throw new RuntimeException('Run php bin/setup.php before starting the example server.');
    }
    return new PDO('sqlite:' . $config['database']);
}
