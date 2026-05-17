<?php

declare(strict_types=1);

namespace GfServer;

/**
 * Administrative operations on existing characters: granting GM status and
 * renaming. All queries are parameterised.
 */
final class AdminService
{
    private const GM_PRIVILEGE = 5;
    private const PLAYER_PRIVILEGE = 0;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Grant or revoke in-game GM status for the character named $playerName.
     * Updates player_characters.privilege (gf_gs) and tb_user.byauthority
     * (gf_ms) for the owning account.
     */
    public function setGmPrivilege(string $playerName, bool $grant): void
    {
        $privilege = $grant ? self::GM_PRIVILEGE : self::PLAYER_PRIVILEGE;

        $character = $this->db->run(
            'gf_gs',
            'SELECT account_id FROM player_characters WHERE given_name = :n',
            [':n' => $playerName],
        )->fetch();
        if ($character === false) {
            throw new ConflictException("Player '{$playerName}' not found.");
        }

        $this->db->run(
            'gf_gs',
            'UPDATE player_characters SET privilege = :p WHERE given_name = :n',
            [':p' => $privilege, ':n' => $playerName],
        );

        // tb_user is keyed by account name; player_characters has the numeric
        // account_id. The account username equals the lowercase login name,
        // looked up via accounts.id in gf_ls.
        $account = $this->db->run(
            'gf_ls',
            'SELECT username FROM accounts WHERE id = :id',
            [':id' => (int) $character['account_id']],
        )->fetch();
        if ($account !== false) {
            $this->db->run(
                'gf_ms',
                'UPDATE tb_user SET byauthority = :p WHERE mid = :m',
                [':p' => $privilege, ':m' => strtolower((string) $account['username'])],
            );
        }
    }

    /** Rename a player character. */
    public function renamePlayer(string $oldName, string $newName): void
    {
        Validation::characterName($newName);

        $exists = $this->db->run(
            'gf_gs',
            'SELECT 1 FROM player_characters WHERE given_name = :n',
            [':n' => $oldName],
        )->fetchColumn();
        if ($exists === false) {
            throw new ConflictException("Player '{$oldName}' not found.");
        }

        $taken = $this->db->run(
            'gf_gs',
            'SELECT 1 FROM player_characters WHERE given_name = :n',
            [':n' => $newName],
        )->fetchColumn();
        if ($taken !== false) {
            throw new ConflictException("Name '{$newName}' is already in use.");
        }

        $this->db->run(
            'gf_gs',
            'UPDATE player_characters SET given_name = :new WHERE given_name = :old',
            [':new' => $newName, ':old' => $oldName],
        );
    }

    /** Rename the sprite (elf) belonging to the character named $playerName. */
    public function renameSprite(string $playerName, string $spriteName): void
    {
        Validation::characterName($spriteName);

        $character = $this->db->run(
            'gf_gs',
            'SELECT id FROM player_characters WHERE given_name = :n',
            [':n' => $playerName],
        )->fetch();
        if ($character === false) {
            throw new ConflictException("Player '{$playerName}' not found.");
        }

        $this->db->run(
            'gf_gs',
            'UPDATE elf1 SET name = :name WHERE player_id = :pid',
            [':name' => $spriteName, ':pid' => (int) $character['id']],
        );
    }
}
