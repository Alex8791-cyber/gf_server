<?php

declare(strict_types=1);

namespace GfServer\App;

use GfServer\Database;

/**
 * Portal authentication state. Login identity lives in the session; admin
 * status is determined by membership in the web_admin table (gf_ls).
 */
final class Auth
{
    public const SESSION_ACCOUNT_ID = 'auth.account_id';
    public const SESSION_USERNAME = 'auth.username';

    public function __construct(
        private readonly Session $session,
        private readonly Database $db,
    ) {
    }

    public function login(int $accountId, string $username): void
    {
        $this->session->regenerate();
        $this->session->set(self::SESSION_ACCOUNT_ID, $accountId);
        $this->session->set(self::SESSION_USERNAME, $username);
    }

    public function logout(): void
    {
        $this->session->remove(self::SESSION_ACCOUNT_ID);
        $this->session->remove(self::SESSION_USERNAME);
    }

    public function isLoggedIn(): bool
    {
        return $this->session->get(self::SESSION_ACCOUNT_ID) !== null;
    }

    public function accountId(): ?int
    {
        $id = $this->session->get(self::SESSION_ACCOUNT_ID);

        return $id === null ? null : (int) $id;
    }

    public function username(): ?string
    {
        $name = $this->session->get(self::SESSION_USERNAME);

        return $name === null ? null : (string) $name;
    }

    /** True if the logged-in account is listed in web_admin. */
    public function isAdmin(): bool
    {
        $id = $this->accountId();
        if ($id === null) {
            return false;
        }

        $row = $this->db->run(
            'gf_ls',
            'SELECT 1 FROM web_admin WHERE account_id = :id',
            [':id' => $id],
        )->fetchColumn();

        return $row !== false;
    }
}
