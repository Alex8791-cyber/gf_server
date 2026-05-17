<?php

declare(strict_types=1);

namespace GfServer;

/**
 * PDO wrapper holding lazy connections to the three game databases.
 * All access goes through parameterised queries; no SQL is built by string
 * concatenation of user input.
 */
final class Database
{
    private const DATABASES = ['gf_gs', 'gf_ls', 'gf_ms'];

    /** @var array<string, \PDO> */
    private array $connections = [];

    public function __construct(private readonly Config $config)
    {
    }

    /** Return the (lazily opened) PDO connection for one game database. */
    public function pdo(string $database): \PDO
    {
        if (!in_array($database, self::DATABASES, true)) {
            throw new \InvalidArgumentException("Unknown database: {$database}");
        }
        if (!isset($this->connections[$database])) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $this->config->host,
                $this->config->port,
                $database,
            );
            $this->connections[$database] = new \PDO(
                $dsn,
                $this->config->user,
                $this->config->password,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ],
            );
        }

        return $this->connections[$database];
    }

    /**
     * Run a parameterised statement against one database and return it.
     *
     * @param array<string, mixed> $params
     */
    public function run(string $database, string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo($database)->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * Run $fn inside a transaction on one database. The PDO is passed to $fn.
     * Commits on success, rolls back and re-throws on any exception.
     *
     * @template T
     * @param callable(\PDO): T $fn
     * @return T
     */
    public function transaction(string $database, callable $fn): mixed
    {
        $pdo = $this->pdo($database);
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
