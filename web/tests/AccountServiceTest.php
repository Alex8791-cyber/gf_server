<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\AccountService;
use GfServer\ConflictException;
use GfServer\ValidationException;

final class AccountServiceTest extends DbTestCase
{
    private AccountService $service;

    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AccountService($this->db);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $username) {
            $this->purgeAccount($username);
        }
        $this->created = [];
    }

    private function uniqueName(): string
    {
        $name = 't' . substr(bin2hex(random_bytes(8)), 0, 12);
        $this->created[] = $name;

        return $name;
    }

    public function testRegisterCreatesRowsInBothDatabases(): void
    {
        $username = $this->uniqueName();
        $id = $this->service->register($username, 'secret-password');

        $this->assertGreaterThan(0, $id);

        $accountRow = $this->db->run(
            'gf_ls',
            'SELECT username FROM accounts WHERE id = :id',
            [':id' => $id],
        )->fetch();
        $this->assertSame($username, $accountRow['username']);

        $userRow = $this->db->run(
            'gf_ms',
            'SELECT pwd FROM tb_user WHERE mid = :m',
            [':m' => $username],
        )->fetch();
        $this->assertNotFalse($userRow, 'tb_user row exists');
        $this->assertStringStartsWith('$2y$', $userRow['pwd'], 'password stored as bcrypt');
        $this->assertNotSame('secret-password', $userRow['pwd'], 'password not plaintext');
    }

    public function testRegisterRejectsDuplicateUsername(): void
    {
        $username = $this->uniqueName();
        $this->service->register($username, 'secret-password');

        $this->expectException(ConflictException::class);
        $this->service->register($username, 'another-password');
    }

    public function testRegisterRejectsInvalidInput(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->register('no', 'secret-password');
    }

    public function testRegisteredAccountPassesAccountLogin(): void
    {
        $username = $this->uniqueName();
        $this->service->register($username, 'round-trip-pw');

        $accept = $this->db->run(
            'gf_ms',
            "SELECT (account_login(:u, :p, '127.0.0.1')).nRet",
            [':u' => $username, ':p' => 'round-trip-pw'],
        )->fetchColumn();
        $this->assertSame(1, (int) $accept, 'correct password is accepted (nRet=1)');

        $reject = $this->db->run(
            'gf_ms',
            "SELECT (account_login(:u, :p, '127.0.0.1')).nRet",
            [':u' => $username, ':p' => 'wrong-password'],
        )->fetchColumn();
        $this->assertSame(3, (int) $reject, 'wrong password is rejected (nRet=3)');
    }

    public function testChangePasswordUpdatesHashAndStillLogsIn(): void
    {
        $username = $this->uniqueName();
        $this->service->register($username, 'first-password');
        $this->service->changePassword($username, 'second-password');

        $accept = $this->db->run(
            'gf_ms',
            "SELECT (account_login(:u, :p, '127.0.0.1')).nRet",
            [':u' => $username, ':p' => 'second-password'],
        )->fetchColumn();
        $this->assertSame(1, (int) $accept, 'new password works');

        $reject = $this->db->run(
            'gf_ms',
            "SELECT (account_login(:u, :p, '127.0.0.1')).nRet",
            [':u' => $username, ':p' => 'first-password'],
        )->fetchColumn();
        $this->assertSame(3, (int) $reject, 'old password no longer works');
    }

    public function testChangePasswordOnMissingAccountThrows(): void
    {
        $this->expectException(ConflictException::class);
        $this->service->changePassword('tnosuchaccount', 'whatever-password');
    }
}
