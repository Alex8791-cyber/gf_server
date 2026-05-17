<?php

declare(strict_types=1);

namespace GfServer;

/** Live server status: TCP port reachability and population counts. */
final class ServerStatus
{
    public function __construct(private readonly Database $db)
    {
    }

    /** True if a TCP connection to host:port succeeds within $timeout seconds. */
    public function isPortOpen(string $host, int $port, float $timeout = 1.0): bool
    {
        $conn = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($conn === false) {
            return false;
        }
        fclose($conn);

        return true;
    }

    /** Number of registered accounts. */
    public function accountCount(): int
    {
        return (int) $this->db
            ->run('gf_ls', 'SELECT count(*) FROM accounts')
            ->fetchColumn();
    }

    /** Number of created characters. */
    public function characterCount(): int
    {
        return (int) $this->db
            ->run('gf_gs', 'SELECT count(*) FROM player_characters')
            ->fetchColumn();
    }
}
