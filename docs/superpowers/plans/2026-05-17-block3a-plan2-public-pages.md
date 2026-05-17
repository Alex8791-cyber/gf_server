# Block ③a Plan 2 — Public Pages

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the portal's public pages — news, downloads, server status, rankings — on top of the Plan 1 foundation.

**Architecture:** Three data classes in `web/lib` (`NewsRepository`, `RankingRepository`, `ServerStatus`) wrap database reads; four controllers in `web/app/Controller` render PHP templates; `web/public/index.php` wires them and registers routes. The deployment grows a dedicated PHP-FPM pool so the portal process receives the `GF_DB_*` environment variables.

**Tech Stack:** PHP 8.2+, PDO (pdo_pgsql), PHPUnit 11, PostgreSQL 16, Apache 2 + PHP-FPM.

**Spec:** `docs/superpowers/specs/2026-05-17-block3a-core-portal-design.md` (Plan 2 of 4 for Block ③a).

---

## Environment note

No PHP/Composer/psql on the Windows dev machine. Per-task verification = file created, LF endings, `bash -n` for shell. Full verification (PHPUnit against PostgreSQL) runs in CI — the existing `web-backend` job picks up new `web/lib`/`web/tests` files automatically; no CI change is needed. PHP files MUST use LF line endings (write in binary mode to avoid CRLF). **No database migration is needed:** the `gf_web` role already has SELECT on `web_news`, `accounts` and `player_characters` from earlier blocks.

All work happens on branch `block3a-plan2-public-pages` (create it from `main`: `git checkout main && git pull && git checkout -b block3a-plan2-public-pages`). Each task ends with a commit; end every commit body with a blank line then `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

## File Structure

```
web/lib/
  NewsRepository.php       # NEW — reads web_news
  RankingRepository.php    # NEW — reads player_characters
  ServerStatus.php         # NEW — port checks + row counts
web/app/Controller/
  NewsController.php        # NEW
  DownloadsController.php   # NEW
  StatusController.php      # NEW
  RankingController.php     # NEW
web/templates/
  news_list.php  news_show.php  downloads.php  status.php  rankings.php   # NEW
web/public/index.php        # MODIFIED — wire repositories, controllers, routes
web/tests/
  NewsRepositoryTest.php  RankingRepositoryTest.php  ServerStatusTest.php  # NEW
deploy/install.sh           # MODIFIED — dedicated PHP-FPM pool with env vars
deploy/gfserver.env.example # MODIFIED — GF_DOWNLOAD_URL
```

---

## Task 1: NewsRepository

**Files:** Create `web/lib/NewsRepository.php`, `web/tests/NewsRepositoryTest.php`

- [ ] **Step 1: Write `web/tests/NewsRepositoryTest.php`**

```php
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
```

- [ ] **Step 2: Write `web/lib/NewsRepository.php`**

```php
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
        $rows = $this->db->run(
            'gf_ls',
            'SELECT id, title, body, published_at FROM web_news '
            . 'WHERE published_at IS NOT NULL '
            . 'ORDER BY published_at DESC LIMIT :limit',
            [':limit' => $limit],
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
```

- [ ] **Step 3: Verify** — confirm both files exist with LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/lib/NewsRepository.php web/tests/NewsRepositoryTest.php
git commit -m "feat(web): add NewsRepository"
```

---

## Task 2: RankingRepository

**Files:** Create `web/lib/RankingRepository.php`, `web/tests/RankingRepositoryTest.php`

- [ ] **Step 1: Write `web/tests/RankingRepositoryTest.php`**

```php
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
```

- [ ] **Step 2: Write `web/lib/RankingRepository.php`**

```php
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
        $rows = $this->db->run(
            'gf_gs',
            'SELECT given_name, level FROM player_characters '
            . 'WHERE given_name IS NOT NULL '
            . 'ORDER BY level DESC, exp DESC LIMIT :limit',
            [':limit' => $limit],
        )->fetchAll();

        return array_map(static fn (array $r): array => [
            'given_name' => (string) $r['given_name'],
            'level' => (int) $r['level'],
        ], $rows);
    }
}
```

- [ ] **Step 3: Verify** — confirm both files exist with LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/lib/RankingRepository.php web/tests/RankingRepositoryTest.php
git commit -m "feat(web): add RankingRepository"
```

---

## Task 3: ServerStatus

**Files:** Create `web/lib/ServerStatus.php`, `web/tests/ServerStatusTest.php`

- [ ] **Step 1: Write `web/tests/ServerStatusTest.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\ServerStatus;

final class ServerStatusTest extends DbTestCase
{
    private ServerStatus $status;

    protected function setUp(): void
    {
        parent::setUp();
        $this->status = new ServerStatus($this->db);
    }

    public function testIsPortOpenReturnsFalseForAClosedPort(): void
    {
        // Port 1 on localhost is not listening in the CI container.
        $this->assertFalse($this->status->isPortOpen('127.0.0.1', 1, 0.5));
    }

    public function testAccountCountIsANonNegativeInteger(): void
    {
        $count = $this->status->accountCount();
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testCharacterCountIsANonNegativeInteger(): void
    {
        $count = $this->status->characterCount();
        $this->assertGreaterThanOrEqual(0, $count);
    }
}
```

- [ ] **Step 2: Write `web/lib/ServerStatus.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer;

/** Live server status: TCP port reachability and population counts. */
final class ServerStatus
{
    public function __construct(private readonly Database $db)
    {
    }

    /** True if a TCP connection to host:port succeeds within $timeout seconds. */
    public function isPortOpen(string $host, int $port, float $timeout = 1.0): bool
    {
        $conn = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($conn === false) {
            return false;
        }
        fclose($conn);

        return true;
    }

    /** Number of registered accounts. */
    public function accountCount(): int
    {
        return (int) $this->db
            ->run('gf_ls', 'SELECT count(*) FROM accounts')
            ->fetchColumn();
    }

    /** Number of created characters. */
    public function characterCount(): int
    {
        return (int) $this->db
            ->run('gf_gs', 'SELECT count(*) FROM player_characters')
            ->fetchColumn();
    }
}
```

- [ ] **Step 3: Verify** — confirm both files exist with LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/lib/ServerStatus.php web/tests/ServerStatusTest.php
git commit -m "feat(web): add ServerStatus"
```

---

## Task 4: NewsController + templates

**Files:** Create `web/app/Controller/NewsController.php`, `web/templates/news_list.php`, `web/templates/news_show.php`

- [ ] **Step 1: Write `web/app/Controller/NewsController.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\App\Controller;
use GfServer\App\Response;
use GfServer\App\View;
use GfServer\NewsRepository;

/** Lists news and shows a single news item (via ?id=). */
final class NewsController extends Controller
{
    public function __construct(View $view, private readonly NewsRepository $news)
    {
        parent::__construct($view);
    }

    public function index(): Response
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : null;

        if ($id !== null) {
            $item = $this->news->find($id);
            if ($item === null) {
                return $this->html('news_show', ['title' => 'News', 'item' => null], 404);
            }

            return $this->html('news_show', ['title' => $item['title'], 'item' => $item]);
        }

        return $this->html('news_list', [
            'title' => 'News',
            'items' => $this->news->published(),
        ]);
    }
}
```

- [ ] **Step 2: Write `web/templates/news_list.php`**

```php
<section class="page">
    <h1>News</h1>
    <?php if ($items === []): ?>
        <p>No news yet.</p>
    <?php else: ?>
        <ul class="news-list">
            <?php foreach ($items as $item): ?>
                <li>
                    <a href="/news?id=<?= (int) $item['id'] ?>"><?= e($item['title']) ?></a>
                    <span class="news-date"><?= e(substr($item['published_at'], 0, 10)) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
```

- [ ] **Step 3: Write `web/templates/news_show.php`**

```php
<section class="page">
    <?php if ($item === null): ?>
        <h1>News not found</h1>
        <p>That news item does not exist. <a href="/news">Back to news</a>.</p>
    <?php else: ?>
        <article class="news-article">
            <h1><?= e($item['title']) ?></h1>
            <p class="news-date"><?= e(substr($item['published_at'], 0, 10)) ?></p>
            <div class="news-body"><?= nl2br(e($item['body'])) ?></div>
            <p><a href="/news">Back to news</a></p>
        </article>
    <?php endif; ?>
</section>
```

- [ ] **Step 4: Verify** — confirm the three files exist with LF endings.

- [ ] **Step 5: Commit**

```bash
git add web/app/Controller/NewsController.php web/templates/news_list.php web/templates/news_show.php
git commit -m "feat(web): add news pages"
```

---

## Task 5: DownloadsController + template

**Files:** Create `web/app/Controller/DownloadsController.php`, `web/templates/downloads.php`

- [ ] **Step 1: Write `web/app/Controller/DownloadsController.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\App\Controller;
use GfServer\App\Response;

/** The client download page. */
final class DownloadsController extends Controller
{
    public function index(): Response
    {
        $url = getenv('GF_DOWNLOAD_URL');
        $downloadUrl = ($url !== false && $url !== '') ? $url : null;

        return $this->html('downloads', [
            'title' => 'Download',
            'downloadUrl' => $downloadUrl,
        ]);
    }
}
```

- [ ] **Step 2: Write `web/templates/downloads.php`**

```php
<section class="page">
    <h1>Download the client</h1>
    <?php if ($downloadUrl === null): ?>
        <p>The client download will be available soon.</p>
    <?php else: ?>
        <p>Download the full game client to start playing:</p>
        <p><a class="button" href="<?= e($downloadUrl) ?>">Download client</a></p>
    <?php endif; ?>
</section>
```

- [ ] **Step 3: Verify** — confirm both files exist with LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/app/Controller/DownloadsController.php web/templates/downloads.php
git commit -m "feat(web): add downloads page"
```

---

## Task 6: StatusController + template

**Files:** Create `web/app/Controller/StatusController.php`, `web/templates/status.php`

- [ ] **Step 1: Write `web/app/Controller/StatusController.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\App\Controller;
use GfServer\App\Response;
use GfServer\App\View;
use GfServer\ServerStatus;

/** The live server-status page. */
final class StatusController extends Controller
{
    /** Component display name => local TCP port to probe. */
    private const COMPONENTS = [
        'Login Server' => 6543,
        'Gateway Server' => 5560,
        'Ticket Server' => 7777,
    ];

    public function __construct(View $view, private readonly ServerStatus $status)
    {
        parent::__construct($view);
    }

    public function index(): Response
    {
        $components = [];
        foreach (self::COMPONENTS as $name => $port) {
            $components[$name] = $this->status->isPortOpen('127.0.0.1', $port);
        }

        return $this->html('status', [
            'title' => 'Server Status',
            'components' => $components,
            'accounts' => $this->status->accountCount(),
            'characters' => $this->status->characterCount(),
        ]);
    }
}
```

- [ ] **Step 2: Write `web/templates/status.php`**

```php
<section class="page">
    <h1>Server Status</h1>
    <ul class="status-list">
        <?php foreach ($components as $name => $online): ?>
            <li>
                <span class="status-name"><?= e($name) ?></span>
                <?php if ($online): ?>
                    <span class="status-up">Online</span>
                <?php else: ?>
                    <span class="status-down">Offline</span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="status-counts">
        Registered accounts: <strong><?= (int) $accounts ?></strong> &mdash;
        Characters created: <strong><?= (int) $characters ?></strong>
    </p>
</section>
```

- [ ] **Step 3: Verify** — confirm both files exist with LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/app/Controller/StatusController.php web/templates/status.php
git commit -m "feat(web): add server status page"
```

---

## Task 7: RankingController + template

**Files:** Create `web/app/Controller/RankingController.php`, `web/templates/rankings.php`

- [ ] **Step 1: Write `web/app/Controller/RankingController.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\App\Controller;
use GfServer\App\Response;
use GfServer\App\View;
use GfServer\RankingRepository;

/** The character leaderboard page. */
final class RankingController extends Controller
{
    public function __construct(View $view, private readonly RankingRepository $rankings)
    {
        parent::__construct($view);
    }

    public function index(): Response
    {
        return $this->html('rankings', [
            'title' => 'Rankings',
            'players' => $this->rankings->topByLevel(),
        ]);
    }
}
```

- [ ] **Step 2: Write `web/templates/rankings.php`**

```php
<section class="page">
    <h1>Rankings</h1>
    <?php if ($players === []): ?>
        <p>No characters yet.</p>
    <?php else: ?>
        <table class="ranking-table">
            <thead>
                <tr><th>#</th><th>Character</th><th>Level</th></tr>
            </thead>
            <tbody>
                <?php foreach ($players as $index => $player): ?>
                    <tr>
                        <td><?= (int) $index + 1 ?></td>
                        <td><?= e($player['given_name']) ?></td>
                        <td><?= (int) $player['level'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
```

- [ ] **Step 3: Verify** — confirm both files exist with LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/app/Controller/RankingController.php web/templates/rankings.php
git commit -m "feat(web): add rankings page"
```

---

## Task 8: Wire routes and controllers into the front controller

**Files:** Modify `web/public/index.php`

- [ ] **Step 1: Replace the wiring section of `web/public/index.php`**

Replace this exact block:

```php
use GfServer\App\Controller\HomeController;
use GfServer\App\Response;
use GfServer\App\Router;
use GfServer\App\Session;
use GfServer\App\View;

require __DIR__ . '/../vendor/autoload.php';
```

with:

```php
use GfServer\App\Controller\DownloadsController;
use GfServer\App\Controller\HomeController;
use GfServer\App\Controller\NewsController;
use GfServer\App\Controller\RankingController;
use GfServer\App\Controller\StatusController;
use GfServer\App\Response;
use GfServer\App\Router;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\Config;
use GfServer\Database;
use GfServer\NewsRepository;
use GfServer\RankingRepository;
use GfServer\ServerStatus;

require __DIR__ . '/../vendor/autoload.php';
```

- [ ] **Step 2: Replace the router/factory section of `web/public/index.php`**

Replace this exact block:

```php
$router = new Router();
$router->add('GET', '/', 'HomeController', 'index');

/**
 * Instantiate a controller by name with its dependencies. Extended by later
 * plans as more controllers are added.
 */
$makeController = static function (string $name) use ($view): object {
    return match ($name) {
        'HomeController' => new HomeController($view),
        default => throw new \RuntimeException("Unknown controller: {$name}"),
    };
};
```

with:

```php
$router = new Router();
$router->add('GET', '/', 'HomeController', 'index');
$router->add('GET', '/news', 'NewsController', 'index');
$router->add('GET', '/downloads', 'DownloadsController', 'index');
$router->add('GET', '/status', 'StatusController', 'index');
$router->add('GET', '/rankings', 'RankingController', 'index');

// Lazily open the database only when a controller needs it.
$db = null;
$getDb = static function () use (&$db): Database {
    if ($db === null) {
        $db = new Database(Config::fromEnv());
    }

    return $db;
};

/**
 * Instantiate a controller by name with its dependencies. Extended by later
 * plans as more controllers are added.
 */
$makeController = static function (string $name) use ($view, $getDb): object {
    return match ($name) {
        'HomeController' => new HomeController($view),
        'NewsController' => new NewsController($view, new NewsRepository($getDb())),
        'DownloadsController' => new DownloadsController($view),
        'StatusController' => new StatusController($view, new ServerStatus($getDb())),
        'RankingController' => new RankingController($view, new RankingRepository($getDb())),
        default => throw new \RuntimeException("Unknown controller: {$name}"),
    };
};
```

- [ ] **Step 2b: Verify the file parses**

The whole file must remain valid PHP. Confirm the two replacements landed and the rest of `index.php` (the security headers, `$session`, `$view`, the dispatch `try`/`catch`, `$response->send()`) is unchanged.

- [ ] **Step 3: Commit**

```bash
git add web/public/index.php
git commit -m "feat(web): route the public pages through the front controller"
```

---

## Task 9: Dedicated PHP-FPM pool, env vars, self-review

**Files:** Modify `deploy/install.sh`, `deploy/gfserver.env.example`

**Why:** The portal now opens the database, so the PHP process needs the `GF_DB_*` (and `GF_MAIL_FROM*`, `GF_DOWNLOAD_URL`) environment variables. A dedicated PHP-FPM pool with explicit `env[]` entries provides them.

- [ ] **Step 1: Add `GF_DOWNLOAD_URL` to `deploy/gfserver.env.example`**

Replace this exact block:

```
# Outgoing mail identity for account emails.
GF_MAIL_FROM=noreply@gf.example.com
GF_MAIL_FROM_NAME=Grand Fantasia
```

with:

```
# Outgoing mail identity for account emails.
GF_MAIL_FROM=noreply@gf.example.com
GF_MAIL_FROM_NAME=Grand Fantasia

# Full URL of the game client download (leave empty until available).
GF_DOWNLOAD_URL=
```

- [ ] **Step 2: Replace the PHP-FPM-socket section of `setup_web_server` in `deploy/install.sh`**

Replace this exact block:

```
  # Discover the PHP-FPM socket rather than hard-coding the PHP version.
  local php_fpm_sock
  php_fpm_sock="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1)"
  [ -n "$php_fpm_sock" ] || die "PHP-FPM socket not found under /run/php/."
```

with:

```
  # Dedicated PHP-FPM pool so the portal process receives the GF_* env vars.
  local php_ver pool_sock
  php_ver="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
  [ -n "$php_ver" ] || die "Could not determine the PHP version."
  pool_sock="/run/php/php-fpm-gfserver.sock"

  log "Writing the gfserver PHP-FPM pool..."
  cat > "/etc/php/${php_ver}/fpm/pool.d/gfserver.conf" <<POOL
[gfserver]
user = www-data
group = www-data
listen = ${pool_sock}
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
clear_env = yes
env[GF_DB_HOST] = 127.0.0.1
env[GF_DB_PORT] = 5432
env[GF_DB_USER] = gf_web
env[GF_DB_PASSWORD] = ${WEB_DB_PASSWORD}
env[GF_MAIL_FROM] = ${GF_MAIL_FROM:-noreply@localhost}
env[GF_MAIL_FROM_NAME] = ${GF_MAIL_FROM_NAME:-Grand Fantasia}
env[GF_DOWNLOAD_URL] = ${GF_DOWNLOAD_URL:-}
POOL
  systemctl restart "php${php_ver}-fpm"
```

- [ ] **Step 3: Point the virtual host at the dedicated pool socket**

In `setup_web_server`, replace this exact line:

```
        SetHandler "proxy:unix:${php_fpm_sock}|fcgi://localhost"
```

with:

```
        SetHandler "proxy:unix:${pool_sock}|fcgi://localhost"
```

- [ ] **Step 4: Verify**

Run: `bash -n deploy/install.sh`
Expected: no output, exit 0.

- [ ] **Step 5: Self-review against the Plan 2 scope**

Confirm each deliverable exists:
- `NewsRepository`, `RankingRepository`, `ServerStatus` in `web/lib` (Tasks 1-3) with tests.
- `NewsController`, `DownloadsController`, `StatusController`, `RankingController` in `web/app/Controller` (Tasks 4-7) with templates.
- `index.php` registers the `/news`, `/downloads`, `/status`, `/rankings` routes and constructs the controllers (Task 8).
- `setup_web_server` provisions the dedicated FPM pool with `env[]` vars (Tasks 2-3 of this task).

Confirm no template emits a dynamic value without `e()` (except trusted server-rendered HTML and integer casts). Confirm no SQL is built by concatenating request input.

- [ ] **Step 6: Commit**

```bash
git add deploy/install.sh deploy/gfserver.env.example
git commit -m "feat(deploy): dedicated PHP-FPM pool with portal env vars"
```

---

## Verification Summary

- **Per task (Windows):** file creation, LF endings, `bash -n` for shell scripts.
- **CI (`web-backend` job):** runs migrations and PHPUnit over `web/tests/` — picks up the three new repository/status test files automatically. `Database` connects as `gf_web`, which already holds SELECT on `web_news`, `accounts` and `player_characters`.
- **Deferred to the deployment phase:** the FPM pool, the live pages in a browser, real port probing — manual acceptance.

## Open Items

1. The home page stays the static Plan 1 welcome; enriching it with live numbers is optional polish, not in this plan.
2. Controllers are thin glue (repository → template) and are not unit-tested in isolation; the repositories and `ServerStatus` carry the test coverage, and the pages are verified in the deployment phase.
3. Plans 3 (account self-service) and 4 (admin UI) follow as separate plans.
