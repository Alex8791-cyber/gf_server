<?php

declare(strict_types=1);

namespace GfServer;

/**
 * IP-based rate limiting backed by web_login_attempt (gf_ls). Used to throttle
 * login, registration and password-reset requests.
 */
final class RateLimiter
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Record one attempt from $ip. */
    public function record(string $ip, bool $success): void
    {
        // $success is a typed bool, so the literal is injection-safe; a SQL
        // boolean literal avoids relying on PDO string-to-boolean coercion.
        $this->db->run(
            'gf_ls',
            'INSERT INTO web_login_attempt (ip, success) VALUES (:ip, '
            . ($success ? 'true' : 'false') . ')',
            [':ip' => $ip],
        );
    }

    /**
     * True if $ip has had at least $max failed attempts within the last
     * $windowSeconds. $windowSeconds is an internal integer.
     */
    public function tooManyFailures(string $ip, int $max, int $windowSeconds): bool
    {
        $count = (int) $this->db->run(
            'gf_ls',
            'SELECT count(*) FROM web_login_attempt '
            . 'WHERE ip = :ip AND success = false '
            . 'AND attempted_at > now() - make_interval(secs => ' . $windowSeconds . ')',
            [':ip' => $ip],
        )->fetchColumn();

        return $count >= $max;
    }
}
