<?php

declare(strict_types=1);

namespace GfServer\Tests;

final class DatabaseTest extends DbTestCase
{
    public function testConnectsToAllThreeGameDatabases(): void
    {
        foreach (['gf_gs', 'gf_ls', 'gf_ms'] as $name) {
            $value = $this->db->run($name, 'SELECT 1 AS ok')->fetchColumn();
            $this->assertSame(1, (int) $value, "connection to {$name} works");
        }
    }

    public function testRunBindsParametersSafely(): void
    {
        $injection = "x'; DROP TABLE accounts; --";
        $row = $this->db->run('gf_ls', 'SELECT :v AS echoed', [':v' => $injection])->fetchColumn();
        $this->assertSame($injection, $row, 'parameters are bound, not interpolated');
    }

    public function testRejectsUnknownDatabase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->run('gf_unknown', 'SELECT 1');
    }
}
