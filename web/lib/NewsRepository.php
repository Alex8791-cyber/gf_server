<?php

declare(strict_types=1);

namespace GfServer;

/** Read access to published news items (web_news, gf_ls). */
final class NewsRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Published news, newest first.
     *
     * @return list<array{id: int, title: string, body: string, published_at: string}>
     */
    public function published(int $limit = 20): array
    {
        // $limit is an int by signature, so concatenating it is injection-safe;
        // LIMIT is not reliably bindable as a string parameter via PDO.
        $rows = $this->db->run(
            'gf_ls',
            'SELECT id, title, body, published_at FROM web_news '
            . 'WHERE published_at IS NOT NULL '
            . 'ORDER BY published_at DESC LIMIT ' . $limit,
        )->fetchAll();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'body' => (string) $r['body'],
            'published_at' => (string) $r['published_at'],
        ], $rows);
    }

    /**
     * A single published news item by id, or null if missing or unpublished.
     *
     * @return array{id: int, title: string, body: string, published_at: string}|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->run(
            'gf_ls',
            'SELECT id, title, body, published_at FROM web_news '
            . 'WHERE id = :id AND published_at IS NOT NULL',
            [':id' => $id],
        )->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'published_at' => (string) $row['published_at'],
        ];
    }
}
