<?php

declare(strict_types=1);

namespace GfServer;

/**
 * Database connection settings, read from environment variables.
 *
 * The three game database NAMES (gf_gs, gf_ls, gf_ms) are fixed and not
 * configurable; only the host, port and the gf_web credentials are.
 */
final class Config
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $user,
        public readonly string $password,
    ) {
    }

    /** Build a Config from GF_DB_* environment variables. */
    public static function fromEnv(): self
    {
        $host = getenv('GF_DB_HOST');
        $port = getenv('GF_DB_PORT');
        $user = getenv('GF_DB_USER');
        $password = getenv('GF_DB_PASSWORD');

        if ($password === false || $password === '') {
            throw new \RuntimeException('GF_DB_PASSWORD is not set.');
        }

        return new self(
            $host !== false && $host !== '' ? $host : '127.0.0.1',
            $port !== false && $port !== '' ? (int) $port : 5432,
            $user !== false && $user !== '' ? $user : 'gf_web',
            $password,
        );
    }
}
