<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config.php';

if (!is_dir(dirname(__DIR__) . '/var')) {
    mkdir(dirname(__DIR__) . '/var', 0700, true);
}
$config = exampleConfig();
$existing = is_file($config['database']) && filesize($config['database']) > 0;
$pdo = new PDO('sqlite:' . $config['database']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($existing) {
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    if (count($tables) !== 3 || array_diff(['slim_session_records', 'slim_session_leases', 'slim_session_cache'], $tables) !== []) {
        throw new RuntimeException('Refusing to alter an unrecognized database. Choose a fresh isolated SQLite path.');
    }
    chmod($config['database'], 0600);
    fwrite(STDOUT, "Isolated example schema already exists; no DDL executed.\n");
    exit(0);
}
$pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/sqlite.sql'));
chmod($config['database'], 0600);
fwrite(STDOUT, "Isolated SQLite schema installed. No request-time DDL is used.\n");
