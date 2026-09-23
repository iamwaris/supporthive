<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * PDO singleton. Every query in this application goes through here with bound
 * parameters. Emulated prepares are OFF, so placeholders are real server-side
 * parameters and string interpolation into SQL is never necessary.
 */
final class Database
{
    private static ?self $instance = null;
    private PDO $pdo;

    /** Nesting depth, so only the outermost transaction() begins and commits. */
    private int $transactionDepth = 0;

    /** Set when any depth throws, so an outer commit cannot persist half the work. */
    private bool $transactionAborted = false;

    private function __construct()
    {
        /** @var array<string,mixed> $c */
        $c = Config::get('database', []);
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'] ?? '127.0.0.1',
            (int) ($c['port'] ?? 3306),
            $c['name'] ?? '',
            $c['charset'] ?? 'utf8mb4'
        );

        try {
            $this->pdo = new PDO($dsn, (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::ATTR_PERSISTENT         => false,
            ]);
        } catch (PDOException $e) {
            // The DSN and credentials must never reach the browser.
            Logger::error('Database connection failed', ['code' => $e->getCode()]);
            throw new RuntimeException('Database connection failed.', 0, $e);
        }
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<string|int,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        /** @var array<string,mixed>|false $row */
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll();
        return $rows;
    }

    /** @param array<string|int,mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::identifier($table),
            implode(', ', array_map([self::class, 'identifier'], $columns)),
            implode(', ', $placeholders)
        );
        $this->run($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $whereParams
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = self::identifier($column) . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', self::identifier($table), implode(', ', $set), $where);
        return $this->run($sql, $params + $whereParams)->rowCount();
    }

    /** @param array<string,mixed> $params */
    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf('DELETE FROM %s WHERE %s', self::identifier($table), $where);
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Run a callback inside a database transaction.
     *
     * Nesting is supported by counting depth: only the outermost call begins
     * and commits. PDO throws on a second beginTransaction(), and the
     * alternative - forbidding nesting - means a service can never call
     * another service that also needs atomicity. Posting a transfer does
     * exactly that: two ledger rows, each posted through the same method that
     * guarantees its own atomicity.
     *
     * If anything throws at any depth the whole transaction is marked
     * aborted, so an outer commit cannot quietly persist half the work even
     * if some intermediate layer swallowed the exception.
     */
    public function transaction(callable $callback): mixed
    {
        if ($this->transactionDepth === 0) {
            $this->pdo->beginTransaction();
            $this->transactionAborted = false;
        }

        $this->transactionDepth++;

        try {
            $result = $callback($this);
        } catch (Throwable $e) {
            $this->transactionAborted = true;
            $this->transactionDepth--;

            if ($this->transactionDepth === 0 && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        $this->transactionDepth--;

        if ($this->transactionDepth === 0) {
            if ($this->transactionAborted) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw new RuntimeException('Transaction was aborted by a nested failure; nothing was written.');
            }

            $this->pdo->commit();
        }

        return $result;
    }

    /**
     * Whitelist-quote a table or column name. Identifiers can never be bound as
     * parameters, so anything user-influenced must be validated, not interpolated.
     */
    public static function identifier(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new RuntimeException('Invalid SQL identifier.');
        }
        return '`' . $name . '`';
    }
}
