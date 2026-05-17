<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\App\Auth;
use GfServer\App\Session;

final class AuthTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_SESSION'] = [];
    }

    public function testLoggedOutByDefault(): void
    {
        $auth = new Auth(new Session(), $this->db);
        $this->assertFalse($auth->isLoggedIn());
        $this->assertNull($auth->accountId());
        $this->assertFalse($auth->isAdmin());
    }

    public function testLoginRecordsTheAccountInTheSession(): void
    {
        $session = new Session();
        $auth = new Auth($session, $this->db);
        $auth->login(4242, 'someplayer');

        $this->assertTrue($auth->isLoggedIn());
        $this->assertSame(4242, $auth->accountId());
        $this->assertSame('someplayer', $auth->username());
    }

    public function testIsAdminReflectsWebAdminMembership(): void
    {
        $admin = $this->adminPdo('gf_ls');
        // web_admin.account_id has a foreign key to accounts.id, so the
        // account row must exist before the web_admin row.
        $admin->exec("INSERT INTO accounts (id, username) VALUES (2100001, 'tauthadmin')");
        $admin->exec('INSERT INTO web_admin (account_id) VALUES (2100001)');

        try {
            $session = new Session();
            $auth = new Auth($session, $this->db);

            $auth->login(2100001, 'tauthadmin');
            $this->assertTrue($auth->isAdmin());

            $auth->login(2100002, 'notadmin');
            $this->assertFalse($auth->isAdmin());
        } finally {
            $admin->exec('DELETE FROM web_admin WHERE account_id = 2100001');
            $admin->exec('DELETE FROM accounts WHERE id = 2100001');
        }
    }
}
