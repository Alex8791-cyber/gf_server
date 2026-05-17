<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\AdminService;
use GfServer\ConflictException;

final class AdminServiceTest extends DbTestCase
{
    private AdminService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AdminService($this->db);
    }

    public function testSetGmPrivilegeOnMissingPlayerThrows(): void
    {
        $this->expectException(ConflictException::class);
        $this->service->setGmPrivilege('tno_such_player_x', true);
    }

    public function testRenamePlayerOnMissingPlayerThrows(): void
    {
        $this->expectException(ConflictException::class);
        $this->service->renamePlayer('tno_such_player_x', 'NewName');
    }

    public function testRenameSpriteOnMissingPlayerThrows(): void
    {
        $this->expectException(ConflictException::class);
        $this->service->renameSprite('tno_such_player_x', 'NewSprite');
    }

    public function testSetGmPrivilegeUpdatesAnExistingCharacter(): void
    {
        $admin = $this->adminPdo('gf_gs');
        $admin->exec(
            "INSERT INTO player_characters (id, account_id, given_name, privilege) "
            . "VALUES (2000001, 2000001, 'TestGmChar', 0)"
        );
        $msAdmin = $this->adminPdo('gf_ms');
        $msAdmin->exec(
            "INSERT INTO tb_user (mid, password, pwd, pvalues) "
            . "VALUES ('tgmchar', '', '', 0)"
        );

        try {
            $this->service->setGmPrivilege('TestGmChar', true);
            $privilege = $admin
                ->query("SELECT privilege FROM player_characters WHERE given_name = 'TestGmChar'")
                ->fetchColumn();
            $this->assertSame(5, (int) $privilege);
        } finally {
            $admin->exec("DELETE FROM player_characters WHERE id = 2000001");
            $msAdmin->exec("DELETE FROM tb_user WHERE mid = 'tgmchar'");
        }
    }
}
