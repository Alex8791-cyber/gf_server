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

    /**
     * Every news item, drafts included, newest first (for the admin list).
     *
     * @return list<array{id: int, title: string, body: string, published_at: ?string}>
     */
    public function allForAdmin(int $limit = 100): array
    {
        // $limit is an int by signature, so concatenating it is injection-safe.
        $rows = $this->db->run(
            'gf_ls',
            'SELECT id, title, body, published_at FROM web_news '
            . 'ORDER BY created_at DESC LIMIT ' . $limit,
        )->fetchAll();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'body' => (string) $r['body'],
            'published_at' => $r['published_at'] !== null ? (string) $r['published_at'] : null,
        ], $rows);
    }

    /**
     * Any news item by id, draft or published, or null.
     *
     * @return array{id: int, title: string, body: string, published_at: ?string}|null
     */
    public function findAny(int $id): ?array
    {
        $row = $this->db->run(
            'gf_ls',
            'SELECT id, title, body, published_at FROM web_news WHERE id = :id',
            [':id' => $id],
        )->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'published_at' => $row['published_at'] !== null ? (string) $row['published_at'] : null,
        ];
    }

    /** Create an unpublished news item. Returns the new id. */
    public function create(string $title, string $body, ?int $authorId): int
    {
        $id = $this->db->run(
            'gf_ls',
            'INSERT INTO web_news (title, body, author_account_id) '
            . 'VALUES (:t, :b, :a) RETURNING id',
            [':t' => $title, ':b' => $body, ':a' => $authorId],
        )->fetchColumn();

        return (int) $id;
    }

    /** Update a news item's title and body. */
    public function update(int $id, string $title, string $body): void
    {
        $this->db->run(
            'gf_ls',
            'UPDATE web_news SET title = :t, body = :b WHERE id = :id',
            [':t' => $title, ':b' => $body, ':id' => $id],
        );
    }

    /** Publish (set published_at if unset) or unpublish (clear published_at). */
    public function setPublished(int $id, bool $published): void
    {
        $sql = $published
            ? 'UPDATE web_news SET published_at = COALESCE(published_at, now()) WHERE id = :id'
            : 'UPDATE web_news SET published_at = NULL WHERE id = :id';
        $this->db->run('gf_ls', $sql, [':id' => $id]);
    }

    /** Delete a news item. */
    public function delete(int $id): void
    {
        $this->db->run('gf_ls', 'DELETE FROM web_news WHERE id = :id', [':id' => $id]);
    }
}
