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

    public function testAdminCreateUpdateAndFindAny(): void
    {
        $id = $this->news->create('Admin draft', 'draft body', null);
        $this->created[] = $id;

        $found = $this->news->findAny($id);
        $this->assertNotNull($found);
        $this->assertSame('Admin draft', $found['title']);
        $this->assertNull($found['published_at'], 'created news starts unpublished');

        $this->news->update($id, 'Edited title', 'edited body');
        $this->assertSame('Edited title', $this->news->findAny($id)['title']);
    }

    public function testAdminPublishAndUnpublish(): void
    {
        $id = $this->news->create('Publish me', 'body', null);
        $this->created[] = $id;

        $this->news->setPublished($id, true);
        $this->assertNotNull($this->news->findAny($id)['published_at']);
        $this->assertNotNull($this->news->find($id), 'published news is publicly visible');

        $this->news->setPublished($id, false);
        $this->assertNull($this->news->findAny($id)['published_at']);
        $this->assertNull($this->news->find($id), 'unpublished news is hidden again');
    }

    public function testAdminListIncludesDraftsAndDeleteRemoves(): void
    {
        $id = $this->news->create('Listed draft', 'body', null);
        $this->created[] = $id;

        $ids = array_column($this->news->allForAdmin(), 'id');
        $this->assertContains($id, $ids, 'allForAdmin includes drafts');

        $this->news->delete($id);
        $this->assertNull($this->news->findAny($id));
    }
}
