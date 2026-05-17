<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\Config;
use GfServer\Database;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for integration tests that need the game databases.
 *
 * $this->db connects as the gf_web role (the role the code under test uses).
 * adminPdo() connects as a superuser for test setup/teardown that needs
 * privileges gf_web deliberately lacks (e.g. deleting accounts rows).
 */
abstract class DbTestCase extends TestCase
{
    protected Database $db;

    protected function setUp(): void
    {
        $this->db = new Database(Config::fromEnv());
    }

    /** Superuser PDO for a given game database, from GF_ADMIN_* env vars. */
    protected function adminPdo(string $database): \PDO
    {
        $host = getenv('GF_ADMIN_HOST') ?: '127.0.0.1';
        $port = getenv('GF_ADMIN_PORT') ?: '5432';
        $user = getenv('GF_ADMIN_USER') ?: 'postgres';
        $password = getenv('GF_ADMIN_PASSWORD') ?: '';
        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        return new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /** Remove an account created by a test, in both databases. */
    protected function purgeAccount(string $username): void
    {
        $this->adminPdo('gf_ms')
            ->prepare('DELETE FROM tb_user WHERE mid = :m')
            ->execute([':m' => $username]);
        $this->adminPdo('gf_ls')
            ->prepare('DELETE FROM accounts WHERE username = :u')
            ->execute([':u' => $username]);
    }
}
