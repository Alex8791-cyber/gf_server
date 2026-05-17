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
    private const BCRYPT_COST = 12;

    public function __construct(private readonly Database $db)
    {
    }

    /** Register a new account. Returns the new accounts.id. */
    public function register(string $username, string $password): int
    {
        $username = strtolower(trim($username));
        Validation::username($username);
        Validation::password($password);

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);

        $taken = $this->db->run(
            'gf_ms',
            'SELECT 1 FROM tb_user WHERE mid = :m',
            [':m' => $username],
        )->fetchColumn();
        if ($taken !== false) {
            throw new ConflictException("Username '{$username}' is already taken.");
        }

        // 1) gf_ms.tb_user — the PK on mid makes this the uniqueness gate.
        try {
            $this->db->run(
                'gf_ms',
                'INSERT INTO tb_user (mid, password, pwd, pvalues) '
                . 'VALUES (:m, :pw, :pw, 99999)',
                [':m' => $username, ':pw' => $hash],
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new ConflictException("Username '{$username}' is already taken.");
            }
            throw new DatabaseException('Failed to create account.', 0, $e);
        }

        // 2) gf_ls.accounts — allocate id under an exclusive lock to avoid the
        //    COUNT()-based race the legacy PHP had. Compensate on failure.
        try {
            return $this->db->transaction('gf_ls', function (\PDO $pdo) use ($username): int {
                $pdo->exec('LOCK TABLE accounts IN EXCLUSIVE MODE');
                $nextId = (int) $pdo
                    ->query('SELECT COALESCE(MAX(id), 0) + 1 FROM accounts')
                    ->fetchColumn();
                $stmt = $pdo->prepare(
                    'INSERT INTO accounts (id, username, password, realname, worldserver) '
                    . "VALUES (:id, :u, '', :u, 0)"
                );
                $stmt->execute([':id' => $nextId, ':u' => $username]);

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

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);

        $stmt = $this->db->run(
            'gf_ms',
            'UPDATE tb_user SET pwd = :pw, password = :pw WHERE mid = :m',
            [':pw' => $hash, ':m' => $username],
        );
        if ($stmt->rowCount() === 0) {
            throw new ConflictException("Account '{$username}' not found.");
        }
    }
}
