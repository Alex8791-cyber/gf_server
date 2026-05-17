<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\NewsRepository;

final class NewsRepositoryTest extends DbTestCase
{
    private NewsRepository $news;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->news = new NewsRepository($this->db);
    }

    protected function tearDown(): void
    {
        $admin = $this->adminPdo('gf_ls');
        foreach ($this->created as $id) {
            $admin->prepare('DELETE FROM web_news WHERE id = :id')->execute([':id' => $id]);
        }
        $this->created = [];
    }

    private function insertNews(string $title, string $body, bool $published): int
    {
        $admin = $this->adminPdo('gf_ls');
        $publishedAt = $published ? 'now()' : 'NULL';
        $id = (int) $admin->query(
            "INSERT INTO web_news (title, body, published_at) "
            . "VALUES (" . $admin->quote($title) . ", " . $admin->quote($body) . ", {$publishedAt}) "
            . "RETURNING id"
        )->fetchColumn();
        $this->created[] = $id;

        return $id;
    }

    public function testPublishedReturnsOnlyPublishedItems(): void
    {
        $publishedId = $this->insertNews('Live news', 'visible body', true);
        $this->insertNews('Draft news', 'hidden body', false);

        $items = $this->news->published();
        $ids = array_column($items, 'id');

        $this->assertContains($publishedId, $ids);
        $this->assertNotContains('Draft news', array_column($items, 'title'));
    }

    public function testFindReturnsAPublishedItemAndNullOtherwise(): void
    {
        $publishedId = $this->insertNews('Findable', 'body here', true);
        $draftId = $this->insertNews('Unfindable', 'draft body', false);

        $found = $this->news->find($publishedId);
        $this->assertNotNull($found);
        $this->assertSame('Findable', $found['title']);

        $this->assertNull($this->news->find($draftId), 'drafts are not findable');
        $this->assertNull($this->news->find(999999999), 'missing id returns null');
    }
}
