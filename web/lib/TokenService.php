<?php

declare(strict_types=1);

namespace GfServer;

/**
 * Single-use, expiring tokens for email confirmation and password reset
 * (table web_token, gf_ls). Only the SHA-256 hash of a token is stored; the
 * raw token is what gets emailed to the user.
 */
final class TokenService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Create a token for an account and return the raw token to email out.
     * $ttlSeconds is an internal integer, so concatenating it is injection-safe.
     */
    public function create(int $accountId, string $purpose, int $ttlSeconds): string
    {
        $raw = bin2hex(random_bytes(32));

        $this->db->run(
            'gf_ls',
            'INSERT INTO web_token (token, account_id, purpose, expires_at) '
            . 'VALUES (:t, :a, :p, now() + make_interval(secs => ' . $ttlSeconds . '))',
            [':t' => hash('sha256', $raw), ':a' => $accountId, ':p' => $purpose],
        );

        return $raw;
    }

    /**
     * Validate and consume a token. Returns the account id on success (and
     * marks the token used), or null if it is unknown, the wrong purpose,
     * expired, or already used.
     */
    public function consume(string $rawToken, string $purpose): ?int
    {
        $hash = hash('sha256', $rawToken);

        $row = $this->db->run(
            'gf_ls',
            'SELECT account_id FROM web_token '
            . 'WHERE token = :t AND purpose = :p '
            . 'AND used_at IS NULL AND expires_at > now()',
            [':t' => $hash, ':p' => $purpose],
        )->fetch();

        if ($row === false) {
            return null;
        }

        $this->db->run(
            'gf_ls',
            'UPDATE web_token SET used_at = now() WHERE token = :t',
            [':t' => $hash],
        );

        return (int) $row['account_id'];
    }
}
