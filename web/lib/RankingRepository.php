<?php

declare(strict_types=1);

namespace GfServer;

/** Read access to the character leaderboard (player_characters, gf_gs). */
final class RankingRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The highest-level named characters, level descending.
     *
     * @return list<array{given_name: string, level: int}>
     */
    public function topByLevel(int $limit = 50): array
    {
        // $limit is an int by signature, so concatenating it is injection-safe;
        // LIMIT is not reliably bindable as a string parameter via PDO.
        $rows = $this->db->run(
            'gf_gs',
            'SELECT given_name, level FROM player_characters '
            . 'WHERE given_name IS NOT NULL '
            . 'ORDER BY level DESC, exp DESC LIMIT ' . $limit,
        )->fetchAll();

        return array_map(static fn (array $r): array => [
            'given_name' => (string) $r['given_name'],
            'level' => (int) $r['level'],
        ], $rows);
    }
}
