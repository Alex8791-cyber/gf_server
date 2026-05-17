<?php

declare(strict_types=1);

namespace GfServer;

/**
 * Account registration and password management.
 *
 * Usernames are stored lowercase: account_login() looks them up via
 * lower(input), so a non-lowercase mid would never authenticate.
 *
 * Registration spans two databases (gf_ms.tb_user and gf_ls.accounts) and
 * therefore cannot be a single transaction. tb_user is written first (its
 * primary key on `mid` enforces username uniqueness); if the accounts insert
 * then fails, the tb_user row is removed so no orphan remains.
 */
final class AccountService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Register a new account. Returns the new accounts.id.
     *
     * When $email is given the account is created locked
     * (tb_user.byauthority = 255) and a web_account row is stored — the
     * email-confirmation flow unlocks it. When $email is null the account is
     * created unlocked (the original Block 2 behaviour).
     */
    public function register(string $username, string $password, ?string $email = null): int
    {
        $username = strtolower(trim($username));
        Validation::username($username);
        Validation::password($password);
        if ($email !== null) {
            Validation::email($email);
        }

        $hash = $this->hashPassword($password);

        $taken = $this->db->run(
            'gf_ms',
            'SELECT 1 FROM tb_user WHERE mid = :m',
            [':m' => $username],
        )->fetchColumn();
        if ($taken !== false) {
            throw new ConflictException("Username '{$username}' is already taken.");
        }

        if ($email !== null) {
            $emailTaken = $this->db->run(
                'gf_ls',
                'SELECT 1 FROM web_account WHERE email = :e',
                [':e' => $email],
            )->fetchColumn();
            if ($emailTaken !== false) {
                throw new ConflictException('That email address is already registered.');
            }
        }

        // 1) gf_ms.tb_user — the PK on mid makes this the uniqueness gate.
        //    byauthority 255 = locked (pending email confirmation).
        try {
            $this->db->run(
                'gf_ms',
                'INSERT INTO tb_user (mid, password, pwd, pvalues, byauthority) '
                . 'VALUES (:m, :pw, :pw, 99999, :auth)',
                [':m' => $username, ':pw' => $hash, ':auth' => $email !== null ? 255 : 0],
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new ConflictException("Username '{$username}' is already taken.");
            }
            throw new DatabaseException('Failed to create account.', 0, $e);
        }

        // 2) gf_ls — allocate the id under an exclusive lock, write accounts
        //    and (when registering with email) web_account, atomically.
        try {
            return $this->db->transaction('gf_ls', function (\PDO $pdo) use ($username, $email): int {
                $pdo->exec('LOCK TABLE accounts IN EXCLUSIVE MODE');
                $nextId = (int) $pdo
                    ->query('SELECT COALESCE(MAX(id), 0) + 1 FROM accounts')
                    ->fetchColumn();
                $stmt = $pdo->prepare(
                    'INSERT INTO accounts (id, username, password, realname, worldserver) '
                    . "VALUES (:id, :u, '', :u, 0)"
                );
                $stmt->execute([':id' => $nextId, ':u' => $username]);

                if ($email !== null) {
                    $web = $pdo->prepare(
                        'INSERT INTO web_account (account_id, email) VALUES (:id, :e)'
                    );
                    $web->execute([':id' => $nextId, ':e' => $email]);
                }

                return $nextId;
            });
        } catch (\Throwable $e) {
            // Compensating action: drop the orphaned tb_user row.
            try {
                $this->db->run('gf_ms', 'DELETE FROM tb_user WHERE mid = :m', [':m' => $username]);
            } catch (\Throwable) {
                // best effort — surface the original failure regardless
            }
            throw new DatabaseException('Failed to create account.', 0, $e);
        }
    }

    /** Set a new password for an existing account. */
    public function changePassword(string $username, string $newPassword): void
    {
        $username = strtolower(trim($username));
        Validation::password($newPassword);

        $hash = $this->hashPassword($newPassword);

        $stmt = $this->db->run(
            'gf_ms',
            'UPDATE tb_user SET pwd = :pw, password = :pw WHERE mid = :m',
            [':pw' => $hash, ':m' => $username],
        );
        if ($stmt->rowCount() === 0) {
            throw new ConflictException("Account '{$username}' not found.");
        }
    }

    /**
     * Verify a username/password pair against the stored bcrypt hash.
     * Returns the accounts.id on success, or null on any failure.
     */
    public function authenticate(string $username, string $password): ?int
    {
        $username = strtolower(trim($username));

        $ok = $this->db->run(
            'gf_ms',
            'SELECT 1 FROM tb_user WHERE mid = :m AND pwd = crypt(:pw, pwd)',
            [':m' => $username, ':pw' => $password],
        )->fetchColumn();
        if ($ok === false) {
            return null;
        }

        $id = $this->db->run(
            'gf_ls',
            'SELECT id FROM accounts WHERE username = :u',
            [':u' => $username],
        )->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Lock or unlock an account for game login by setting tb_user.byauthority
     * (255 = locked, 0 = open). Used by the email-confirmation lifecycle.
     */
    public function setAccountLocked(string $username, bool $locked): void
    {
        $username = strtolower(trim($username));

        $stmt = $this->db->run(
            'gf_ms',
            'UPDATE tb_user SET byauthority = :a WHERE mid = :m',
            [':a' => $locked ? 255 : 0, ':m' => $username],
        );
        if ($stmt->rowCount() === 0) {
            throw new ConflictException("Account '{$username}' not found.");
        }
    }

    /** Return the accounts.id for a username, or null if unknown. */
    public function findByUsername(string $username): ?int
    {
        $id = $this->db->run(
            'gf_ls',
            'SELECT id FROM accounts WHERE username = :u',
            [':u' => strtolower(trim($username))],
        )->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** Return the accounts.id for an email address, or null if unknown. */
    public function findByEmail(string $email): ?int
    {
        $id = $this->db->run(
            'gf_ls',
            'SELECT account_id FROM web_account WHERE email = :e',
            [':e' => $email],
        )->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** Mark an account's email verified and unlock it for game login. */
    public function confirmEmail(int $accountId): void
    {
        $this->db->run(
            'gf_ls',
            'UPDATE web_account SET email_verified = true WHERE account_id = :id',
            [':id' => $accountId],
        );

        $username = $this->db->run(
            'gf_ls',
            'SELECT username FROM accounts WHERE id = :id',
            [':id' => $accountId],
        )->fetchColumn();
        if ($username !== false) {
            $this->db->run(
                'gf_ms',
                'UPDATE tb_user SET byauthority = 0 WHERE mid = :m',
                [':m' => strtolower((string) $username)],
            );
        }
    }

    /** Set a new password for an account identified by id. */
    public function changePasswordForAccount(int $accountId, string $newPassword): void
    {
        $username = $this->db->run(
            'gf_ls',
            'SELECT username FROM accounts WHERE id = :id',
            [':id' => $accountId],
        )->fetchColumn();
        if ($username === false) {
            throw new ConflictException('Account not found.');
        }

        $this->changePassword((string) $username, $newPassword);
    }

    /**
     * Portal-side account metadata (email + verification flag), or null.
     *
     * @return array{email: ?string, email_verified: bool}|null
     */
    public function webAccount(int $accountId): ?array
    {
        $row = $this->db->run(
            'gf_ls',
            'SELECT email, email_verified FROM web_account WHERE account_id = :id',
            [':id' => $accountId],
        )->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'email' => $row['email'] !== null ? (string) $row['email'] : null,
            'email_verified' => in_array($row['email_verified'], [true, 't', '1', 1], true),
        ];
    }

    /**
     * Hash a password with bcrypt via PostgreSQL's pgcrypto.
     *
     * The hash MUST be produced by pgcrypto, not PHP's password_hash(): the
     * account_login() stored procedure verifies it with pgcrypto's crypt(),
     * and pgcrypto does not accept the $2y$ bcrypt variant that password_hash()
     * emits. Generating the hash here with the same implementation that later
     * verifies it guarantees they agree.
     */
    private function hashPassword(string $password): string
    {
        $row = $this->db->run(
            'gf_ms',
            "SELECT crypt(:pw, gen_salt('bf', 12)) AS hash",
            [':pw' => $password],
        )->fetch();

        return (string) $row['hash'];
    }
}
