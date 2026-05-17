<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\RankingRepository;

final class RankingRepositoryTest extends DbTestCase
{
    private RankingRepository $rankings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankings = new RankingRepository($this->db);
    }

    public function testTopByLevelReturnsCharactersOrderedByLevelDescending(): void
    {
        $admin = $this->adminPdo('gf_gs');
        $admin->exec(
            "INSERT INTO player_characters (id, given_name, level, exp) VALUES "
            . "(2200001, 'RankLow', 10, 0), "
            . "(2200002, 'RankHigh', 90, 0), "
            . "(2200003, 'RankMid', 50, 0)"
        );

        try {
            $players = $this->rankings->topByLevel(100);
            $names = array_column($players, 'given_name');

            $posHigh = array_search('RankHigh', $names, true);
            $posMid = array_search('RankMid', $names, true);
            $posLow = array_search('RankLow', $names, true);

            $this->assertNotFalse($posHigh);
            $this->assertNotFalse($posMid);
            $this->assertNotFalse($posLow);
            $this->assertLessThan($posMid, $posHigh, 'higher level ranks first');
            $this->assertLessThan($posLow, $posMid, 'mid level ranks before low');
        } finally {
            $admin->exec('DELETE FROM player_characters WHERE id IN (2200001, 2200002, 2200003)');
        }
    }

    public function testTopByLevelRespectsTheLimit(): void
    {
        $admin = $this->adminPdo('gf_gs');
        $admin->exec(
            "INSERT INTO player_characters (id, given_name, level, exp) VALUES "
            . "(2200004, 'LimitA', 5, 0), (2200005, 'LimitB', 6, 0)"
        );

        try {
            $this->assertCount(1, $this->rankings->topByLevel(1));
        } finally {
            $admin->exec('DELETE FROM player_characters WHERE id IN (2200004, 2200005)');
        }
    }
}
