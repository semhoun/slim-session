<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Storage;

use PDO;
use Semhoun\SlimSession\Exception\StorageUnavailableException;

/** Shared connection primitives; schema installation is deliberately external. */
final class PdoInfrastructure
{
    public readonly string $driver;

    public function __construct(public readonly PDO $connection, private readonly string $prefix = 'slim_session_')
    {
        if (!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,39}\z/D', $prefix)) {
            throw new \InvalidArgumentException('Invalid storage table prefix.');
        }
        $this->driver = (string) $connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver, ['sqlite', 'pgsql', 'mysql'], true)) {
            throw new \InvalidArgumentException('Unsupported PDO driver.');
        }
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($this->driver === 'sqlite') {
            $connection->exec('PRAGMA busy_timeout = 50');
        } elseif ($this->driver === 'mysql') {
            $connection->exec('SET SESSION innodb_lock_wait_timeout = 1');
        }
    }

    public function table(string $suffix): string
    {
        return $this->prefix . $suffix;
    }

    public function timeExpression(): string
    {
        return match ($this->driver) {
            'pgsql' => 'EXTRACT(EPOCH FROM clock_timestamp())',
            'mysql' => 'UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))',
            'sqlite' => "((julianday('now') - 2440587.5) * 86400.0)",
        };
    }

    public function now(): float
    {
        return (float) $this->connection->query('SELECT ' . $this->timeExpression())->fetchColumn();
    }

    /** @param array<array-key, mixed> $parameters */
    public function execute(string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    public function insertIgnore(string $table, string $columns, string $values): string
    {
        return match ($this->driver) {
            'mysql' => "INSERT IGNORE INTO $table ($columns) VALUES ($values)",
            default => "INSERT INTO $table ($columns) VALUES ($values) ON CONFLICT DO NOTHING",
        };
    }

    public function transaction(callable $operation): mixed
    {
        if ($this->connection->inTransaction()) {
            throw new StorageUnavailableException('Session storage requires its own short transaction.');
        }
        $started = false;
        try {
            if ($this->driver === 'sqlite') {
                $this->connection->exec('BEGIN IMMEDIATE');
            } else {
                $this->connection->beginTransaction();
            }
            $started = true;
            if ($this->driver === 'pgsql') {
                $this->connection->exec("SET LOCAL lock_timeout = '50ms'");
                $this->connection->exec("SET LOCAL statement_timeout = '1000ms'");
            }
            $result = $operation();
            if ($this->driver === 'sqlite') {
                $this->connection->exec('COMMIT');
            } else {
                $this->connection->commit();
            }
            return $result;
        } catch (\Throwable $error) {
            if ($started) {
                try {
                    if ($this->driver === 'sqlite') {
                        $this->connection->exec('ROLLBACK');
                    } else {
                        $this->connection->rollBack();
                    }
                } catch (\Throwable) {
                    // Preserve the original operation failure.
                }
            }
            if ($error instanceof \PDOException) {
                throw new StorageUnavailableException('Session storage operation failed.', 0, $error);
            }
            throw $error;
        }
    }

    public static function validateNamespace(string $namespace): void
    {
        if (!preg_match('/\A[a-zA-Z0-9_.-]{1,128}\z/D', $namespace)) {
            throw new \InvalidArgumentException('Invalid storage namespace.');
        }
    }
}
