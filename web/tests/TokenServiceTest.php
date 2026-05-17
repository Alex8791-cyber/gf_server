<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\TokenService;

final class TokenServiceTest extends DbTestCase
{
    private TokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new TokenService($this->db);
        $this->seedAccount(2300001);
    }

    protected function tearDown(): void
    {
        $admin = $this->adminPdo('gf_ls');
        $admin->exec('DELETE FROM web_token WHERE account_id = 2300001');
        $admin->exec('DELETE FROM accounts WHERE id = 2300001');
    }

    private function seedAccount(int $id): void
    {
        $this->adminPdo('gf_ls')->exec(
            "INSERT INTO accounts (id, username) VALUES ({$id}, 'ttoken{$id}') "
            . "ON CONFLICT (id) DO NOTHING"
        );
    }

    public function testCreatedTokenCanBeConsumedOnce(): void
    {
        $raw = $this->tokens->create(2300001, 'email_verify', 3600);

        $this->assertSame(2300001, $this->tokens->consume($raw, 'email_verify'));
        $this->assertNull($this->tokens->consume($raw, 'email_verify'), 'token is single-use');
    }

    public function testConsumeRejectsAWrongPurpose(): void
    {
        $raw = $this->tokens->create(2300001, 'email_verify', 3600);
        $this->assertNull($this->tokens->consume($raw, 'password_reset'));
    }

    public function testConsumeRejectsAnExpiredToken(): void
    {
        $raw = $this->tokens->create(2300001, 'password_reset', -10);
        $this->assertNull($this->tokens->consume($raw, 'password_reset'));
    }

    public function testConsumeRejectsAnUnknownToken(): void
    {
        $this->assertNull($this->tokens->consume('deadbeef', 'email_verify'));
    }
}
