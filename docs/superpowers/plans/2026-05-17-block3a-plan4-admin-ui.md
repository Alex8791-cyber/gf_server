# Block ③a Plan 4 — Admin UI

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the portal's login-protected admin UI — a dashboard, news management, player management (GM privileges and renaming), and account management (password reset, lock/unlock, resend confirmation).

**Architecture:** An abstract `AdminController` (extends `FormController`) gates every action behind login + `web_admin` membership. Four concrete admin controllers render PHP templates. `NewsRepository` and `AccountService` gain the write/lookup methods the admin needs. Routes are wired into the front controller.

**Tech Stack:** PHP 8.2+, PDO (pdo_pgsql), PHPUnit 11, PostgreSQL 16.

**Spec:** `docs/superpowers/specs/2026-05-17-block3a-core-portal-design.md` (Plan 4 of 4 for Block ③a — the final stage).

---

## Environment note

No PHP/Composer/psql on the Windows dev machine. Per-task verification = file created, LF endings (no CR bytes), `bash -n` for shell. Full verification (PHPUnit against PostgreSQL) runs in CI — the existing `web-backend` job picks up new `web/lib`/`web/tests` files automatically. PHP files MUST use LF line endings; write content verbatim. **No database migration is needed:** `gf_web` already has `SELECT/INSERT/UPDATE/DELETE` on `web_news`, `SELECT` on `accounts`, and the grants `AdminService`/`TokenService` need.

All work happens on branch `block3a-plan4-admin-ui` (create from `main`: `git checkout main && git pull && git checkout -b block3a-plan4-admin-ui`). Each task ends with a commit; end every commit body with a blank line then `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

## File Structure

```
web/lib/
  NewsRepository.php     # MODIFIED — admin methods (allForAdmin, findAny,
                         #            create, update, setPublished, delete)
  AccountService.php     # MODIFIED — add findByUsername
web/app/
  AdminController.php     # NEW — abstract base, login + web_admin gate
  Controller/
    DashboardController.php      # NEW
    NewsAdminController.php      # NEW
    PlayerAdminController.php    # NEW
    AccountAdminController.php   # NEW
web/templates/
  forbidden.php admin_dashboard.php admin_news_list.php admin_news_form.php
  admin_players.php admin_accounts.php                                   # NEW
web/public/index.php     # MODIFIED — admin routes and controllers
web/tests/
  NewsRepositoryTest.php AccountServiceTest.php   # MODIFIED
```

---

## Task 1: NewsRepository admin methods

**Files:** Modify `web/lib/NewsRepository.php`, `web/tests/NewsRepositoryTest.php`

- [ ] **Step 1: Add tests to `web/tests/NewsRepositoryTest.php`**

Insert these methods immediately before the final closing `}` of the class:

```php

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
```

- [ ] **Step 2: Add the admin methods to `web/lib/NewsRepository.php`**

Insert these methods immediately before the final closing `}` of the class (after the `find` method):

```php

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
```

- [ ] **Step 3: Verify** — confirm both files have LF endings; confirm `NewsRepository` has `allForAdmin`, `findAny`, `create`, `update`, `setPublished`, `delete`.

- [ ] **Step 4: Commit**

```bash
git add web/lib/NewsRepository.php web/tests/NewsRepositoryTest.php
git commit -m "feat(web): add NewsRepository admin methods"
```

---

## Task 2: AccountService::findByUsername

**Files:** Modify `web/lib/AccountService.php`, `web/tests/AccountServiceTest.php`

- [ ] **Step 1: Add a test to `web/tests/AccountServiceTest.php`**

Insert this method immediately before the final closing `}` of the class:

```php

    public function testFindByUsernameReturnsTheAccountId(): void
    {
        $username = $this->uniqueName();
        $id = $this->service->register($username, 'find-user-pw');

        $this->assertSame($id, $this->service->findByUsername($username));
        $this->assertSame($id, $this->service->findByUsername(strtoupper($username)));
        $this->assertNull($this->service->findByUsername('tno_such_user_x'));
    }
```

- [ ] **Step 2: Add the method to `web/lib/AccountService.php`**

Insert this method immediately before the existing `findByEmail` method (before its doc comment):

```php
    /** Return the accounts.id for a username, or null if unknown. */
    public function findByUsername(string $username): ?int
    {
        $id = $this->db->run(
            'gf_ls',
            'SELECT id FROM accounts WHERE username = :u',
            [':u' => strtolower(trim($username))],
        )->fetchColumn();

        return $id === false ? null : (int) $id;
    }

```

- [ ] **Step 3: Verify** — confirm both files have LF endings; confirm `AccountService` has `findByUsername`.

- [ ] **Step 4: Commit**

```bash
git add web/lib/AccountService.php web/tests/AccountServiceTest.php
git commit -m "feat(web): add AccountService findByUsername"
```

---

## Task 3: AdminController base and forbidden page

**Files:** Create `web/app/AdminController.php`, `web/templates/forbidden.php`

- [ ] **Step 1: Write `web/app/AdminController.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App;

/**
 * Base for admin controllers. Every admin action calls denyUnlessAdmin()
 * first: it returns a redirect/forbidden Response when the visitor is not a
 * logged-in web_admin, or null when access is allowed.
 */
abstract class AdminController extends FormController
{
    public function __construct(View $view, Session $session, protected readonly Auth $auth)
    {
        parent::__construct($view, $session);
    }

    /** Returns a Response to send when access is denied, or null when allowed. */
    protected function denyUnlessAdmin(): ?Response
    {
        if (!$this->auth->isLoggedIn()) {
            return $this->redirect('/login');
        }
        if (!$this->auth->isAdmin()) {
            return $this->html('forbidden', ['title' => 'Forbidden'], 403);
        }

        return null;
    }
}
```

- [ ] **Step 2: Write `web/templates/forbidden.php`**

```php
<section class="page">
    <h1>Access denied</h1>
    <p>You do not have permission to view that page.</p>
    <p><a href="/">Back to the home page</a></p>
</section>
```

- [ ] **Step 3: Verify** — confirm both files have LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/app/AdminController.php web/templates/forbidden.php
git commit -m "feat(web): add AdminController base"
```

---

## Task 4: Dashboard

**Files:** Create `web/app/Controller/DashboardController.php`, `web/templates/admin_dashboard.php`

- [ ] **Step 1: Write `web/app/Controller/DashboardController.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\App\AdminController;
use GfServer\App\Auth;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\ServerStatus;

/** The admin dashboard landing page. */
final class DashboardController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        private readonly ServerStatus $status,
    ) {
        parent::__construct($view, $session, $auth);
    }

    public function index(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }

        return $this->page('admin_dashboard', [
            'title' => 'Admin',
            'accounts' => $this->status->accountCount(),
            'characters' => $this->status->characterCount(),
        ]);
    }
}
```

- [ ] **Step 2: Write `web/templates/admin_dashboard.php`**

```php
<section class="page">
    <h1>Admin dashboard</h1>
    <?php foreach ($flashes as $flash): ?>
        <p class="flash"><?= e($flash) ?></p>
    <?php endforeach; ?>
    <p class="status-counts">
        Accounts: <strong><?= (int) $accounts ?></strong> &mdash;
        Characters: <strong><?= (int) $characters ?></strong>
    </p>
    <ul class="admin-menu">
        <li><a href="/admin/news">Manage news</a></li>
        <li><a href="/admin/players">Manage players</a></li>
        <li><a href="/admin/accounts">Manage accounts</a></li>
    </ul>
</section>
```

- [ ] **Step 3: Verify** — confirm both files have LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/app/Controller/DashboardController.php web/templates/admin_dashboard.php
git commit -m "feat(web): add admin dashboard"
```

---

## Task 5: News management

**Files:** Create `web/app/Controller/NewsAdminController.php`, `web/templates/admin_news_list.php`, `web/templates/admin_news_form.php`

- [ ] **Step 1: Write `web/app/Controller/NewsAdminController.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\App\AdminController;
use GfServer\App\Auth;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\NewsRepository;

/** Admin news management: list, create, edit, publish, delete. */
final class NewsAdminController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        private readonly NewsRepository $news,
    ) {
        parent::__construct($view, $session, $auth);
    }

    public function index(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }

        return $this->page('admin_news_list', [
            'title' => 'Manage news',
            'items' => $this->news->allForAdmin(),
        ]);
    }

    public function createForm(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }

        return $this->page('admin_news_form', [
            'title' => 'New post',
            'item' => null,
            'action' => '/admin/news/new',
            'error' => null,
        ]);
    }

    public function create(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if (!$this->csrfValid()) {
            return $this->page('admin_news_form', ['title' => 'New post', 'item' => null, 'action' => '/admin/news/new', 'error' => 'Invalid form token.'], 400);
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($title === '' || $body === '') {
            return $this->page('admin_news_form', ['title' => 'New post', 'item' => null, 'action' => '/admin/news/new', 'error' => 'Title and body are required.'], 422);
        }

        $this->news->create($title, $body, $this->auth->accountId());
        $this->flash('News post created.');

        return $this->redirect('/admin/news');
    }

    public function editForm(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $item = $this->news->findAny((int) ($_GET['id'] ?? 0));
        if ($item === null) {
            return $this->page('admin_news_form', ['title' => 'Edit post', 'item' => null, 'action' => '/admin/news/edit', 'error' => 'That news post does not exist.'], 404);
        }

        return $this->page('admin_news_form', [
            'title' => 'Edit post',
            'item' => $item,
            'action' => '/admin/news/edit',
            'error' => null,
        ]);
    }

    public function update(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if (!$this->csrfValid()) {
            return $this->redirect('/admin/news');
        }

        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($title !== '' && $body !== '' && $this->news->findAny($id) !== null) {
            $this->news->update($id, $title, $body);
            $this->flash('News post updated.');
        } else {
            $this->flash('Could not update that post.');
        }

        return $this->redirect('/admin/news');
    }

    public function togglePublish(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if ($this->csrfValid()) {
            $this->news->setPublished((int) ($_POST['id'] ?? 0), ($_POST['publish'] ?? '') === '1');
            $this->flash('News visibility updated.');
        }

        return $this->redirect('/admin/news');
    }

    public function delete(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if ($this->csrfValid()) {
            $this->news->delete((int) ($_POST['id'] ?? 0));
            $this->flash('News post deleted.');
        }

        return $this->redirect('/admin/news');
    }
}
```

- [ ] **Step 2: Write `web/templates/admin_news_list.php`**

```php
<section class="page">
    <h1>Manage news</h1>
    <?php foreach ($flashes as $flash): ?>
        <p class="flash"><?= e($flash) ?></p>
    <?php endforeach; ?>
    <p><a class="button" href="/admin/news/new">New post</a></p>
    <?php if ($items === []): ?>
        <p>No news posts yet.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr><th>Title</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= e($item['title']) ?></td>
                        <td><?= $item['published_at'] !== null ? 'Published' : 'Draft' ?></td>
                        <td class="admin-actions">
                            <a href="/admin/news/edit?id=<?= (int) $item['id'] ?>">Edit</a>
                            <form method="post" action="/admin/news/publish">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                <input type="hidden" name="publish" value="<?= $item['published_at'] !== null ? '0' : '1' ?>">
                                <button type="submit"><?= $item['published_at'] !== null ? 'Unpublish' : 'Publish' ?></button>
                            </form>
                            <form method="post" action="/admin/news/delete">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                <button type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
```

- [ ] **Step 3: Write `web/templates/admin_news_form.php`**

```php
<section class="page">
    <h1><?= e($title) ?></h1>
    <?php if ($error !== null): ?>
        <p class="form-error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if ($item !== null || $action === '/admin/news/new'): ?>
        <form method="post" action="<?= e($action) ?>" class="form">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <?php if ($item !== null): ?>
                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
            <?php endif; ?>
            <label>Title<br>
                <input type="text" name="title" value="<?= e($item['title'] ?? '') ?>" required>
            </label>
            <label>Body<br>
                <textarea name="body" rows="10" required><?= e($item['body'] ?? '') ?></textarea>
            </label>
            <button type="submit" class="button">Save</button>
        </form>
    <?php endif; ?>
    <p><a href="/admin/news">Back to news list</a></p>
</section>
```

- [ ] **Step 4: Verify** — confirm the three files have LF endings.

- [ ] **Step 5: Commit**

```bash
git add web/app/Controller/NewsAdminController.php web/templates/admin_news_list.php web/templates/admin_news_form.php
git commit -m "feat(web): add admin news management"
```

---

## Task 6: Player management

**Files:** Create `web/app/Controller/PlayerAdminController.php`, `web/templates/admin_players.php`

- [ ] **Step 1: Write `web/app/Controller/PlayerAdminController.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\AdminService;
use GfServer\App\AdminController;
use GfServer\App\Auth;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\ConflictException;
use GfServer\ValidationException;

/** Admin player management: GM privileges and renaming. */
final class PlayerAdminController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        private readonly AdminService $admin,
    ) {
        parent::__construct($view, $session, $auth);
    }

    public function index(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }

        return $this->page('admin_players', ['title' => 'Manage players']);
    }

    public function setGm(): Response
    {
        return $this->run(function (): void {
            $this->admin->setGmPrivilege(
                (string) ($_POST['player'] ?? ''),
                ($_POST['grant'] ?? '') === '1',
            );
            $this->flash('GM privilege updated.');
        });
    }

    public function renamePlayer(): Response
    {
        return $this->run(function (): void {
            $this->admin->renamePlayer(
                (string) ($_POST['old_name'] ?? ''),
                (string) ($_POST['new_name'] ?? ''),
            );
            $this->flash('Player renamed.');
        });
    }

    public function renameSprite(): Response
    {
        return $this->run(function (): void {
            $this->admin->renameSprite(
                (string) ($_POST['player'] ?? ''),
                (string) ($_POST['sprite'] ?? ''),
            );
            $this->flash('Sprite renamed.');
        });
    }

    /**
     * Shared handling for the player POST actions: admin gate, CSRF check,
     * run $action, turn domain exceptions into a flash, redirect.
     */
    private function run(callable $action): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if (!$this->csrfValid()) {
            return $this->redirect('/admin/players');
        }

        try {
            $action();
        } catch (ConflictException | ValidationException $e) {
            $this->flash('Error: ' . $e->getMessage());
        }

        return $this->redirect('/admin/players');
    }
}
```

- [ ] **Step 2: Write `web/templates/admin_players.php`**

```php
<section class="page">
    <h1>Manage players</h1>
    <?php foreach ($flashes as $flash): ?>
        <p class="flash"><?= e($flash) ?></p>
    <?php endforeach; ?>

    <h2>GM privilege</h2>
    <form method="post" action="/admin/players/gm" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Character name<br><input type="text" name="player" required></label>
        <label>Action<br>
            <select name="grant">
                <option value="1">Grant GM</option>
                <option value="0">Revoke GM</option>
            </select>
        </label>
        <button type="submit" class="button">Apply</button>
    </form>

    <h2>Rename a player</h2>
    <form method="post" action="/admin/players/rename" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Current name<br><input type="text" name="old_name" required></label>
        <label>New name<br><input type="text" name="new_name" required></label>
        <button type="submit" class="button">Rename player</button>
    </form>

    <h2>Rename a sprite</h2>
    <form method="post" action="/admin/players/sprite" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Character name<br><input type="text" name="player" required></label>
        <label>New sprite name<br><input type="text" name="sprite" required></label>
        <button type="submit" class="button">Rename sprite</button>
    </form>

    <p><a href="/admin">Back to dashboard</a></p>
</section>
```

- [ ] **Step 3: Verify** — confirm both files have LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/app/Controller/PlayerAdminController.php web/templates/admin_players.php
git commit -m "feat(web): add admin player management"
```

---

## Task 7: Account management

**Files:** Create `web/app/Controller/AccountAdminController.php`, `web/templates/admin_accounts.php`

- [ ] **Step 1: Write `web/app/Controller/AccountAdminController.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\AccountService;
use GfServer\App\AdminController;
use GfServer\App\Auth;
use GfServer\App\Mailer;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\ConflictException;
use GfServer\TokenService;
use GfServer\ValidationException;

/** Admin account management: password reset, lock/unlock, resend confirmation. */
final class AccountAdminController extends AdminController
{
    private const CONFIRM_TTL = 86400;

    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        private readonly AccountService $accounts,
        private readonly TokenService $tokens,
        private readonly Mailer $mailer,
    ) {
        parent::__construct($view, $session, $auth);
    }

    public function index(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }

        return $this->page('admin_accounts', ['title' => 'Manage accounts']);
    }

    public function resetPassword(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if (!$this->csrfValid()) {
            return $this->redirect('/admin/accounts');
        }

        try {
            $this->accounts->changePassword(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? ''),
            );
            $this->flash('Password reset.');
        } catch (ConflictException | ValidationException $e) {
            $this->flash('Error: ' . $e->getMessage());
        }

        return $this->redirect('/admin/accounts');
    }

    public function setLocked(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if (!$this->csrfValid()) {
            return $this->redirect('/admin/accounts');
        }

        try {
            $this->accounts->setAccountLocked(
                (string) ($_POST['username'] ?? ''),
                ($_POST['lock'] ?? '') === '1',
            );
            $this->flash('Account lock state updated.');
        } catch (ConflictException $e) {
            $this->flash('Error: ' . $e->getMessage());
        }

        return $this->redirect('/admin/accounts');
    }

    public function resendConfirmation(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if (!$this->csrfValid()) {
            return $this->redirect('/admin/accounts');
        }

        $username = (string) ($_POST['username'] ?? '');
        $accountId = $this->accounts->findByUsername($username);
        $web = $accountId !== null ? $this->accounts->webAccount($accountId) : null;

        if ($accountId === null || $web === null || $web['email'] === null) {
            $this->flash('No email address is on file for that account.');

            return $this->redirect('/admin/accounts');
        }

        $token = $this->tokens->create($accountId, 'email_verify', self::CONFIRM_TTL);
        $link = $this->portalUrl('/confirm?token=' . $token);
        $this->mailer->send(
            $web['email'],
            'Confirm your Grand Fantasia account',
            "Please confirm your account by opening this link:\n{$link}\n",
        );
        $this->flash('Confirmation email resent.');

        return $this->redirect('/admin/accounts');
    }

    /** Absolute portal URL built from the configured domain (not the Host header). */
    private function portalUrl(string $path): string
    {
        $domain = getenv('PORTAL_DOMAIN');
        $domain = ($domain !== false && $domain !== '') ? $domain : 'localhost';

        return 'https://' . $domain . $path;
    }
}
```

- [ ] **Step 2: Write `web/templates/admin_accounts.php`**

```php
<section class="page">
    <h1>Manage accounts</h1>
    <?php foreach ($flashes as $flash): ?>
        <p class="flash"><?= e($flash) ?></p>
    <?php endforeach; ?>

    <h2>Reset a password</h2>
    <form method="post" action="/admin/accounts/password" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Username<br><input type="text" name="username" required></label>
        <label>New password<br><input type="text" name="password" required></label>
        <button type="submit" class="button">Reset password</button>
    </form>

    <h2>Lock or unlock an account</h2>
    <form method="post" action="/admin/accounts/lock" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Username<br><input type="text" name="username" required></label>
        <label>Action<br>
            <select name="lock">
                <option value="1">Lock</option>
                <option value="0">Unlock</option>
            </select>
        </label>
        <button type="submit" class="button">Apply</button>
    </form>

    <h2>Resend confirmation email</h2>
    <form method="post" action="/admin/accounts/resend" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Username<br><input type="text" name="username" required></label>
        <button type="submit" class="button">Resend email</button>
    </form>

    <p><a href="/admin">Back to dashboard</a></p>
</section>
```

- [ ] **Step 3: Verify** — confirm both files have LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/app/Controller/AccountAdminController.php web/templates/admin_accounts.php
git commit -m "feat(web): add admin account management"
```

---

## Task 8: Wire the admin routes

**Files:** Modify `web/public/index.php`

- [ ] **Step 1: Replace the `use` / `require` block of `web/public/index.php`**

Replace this exact block:

```php
use GfServer\AccountService;
use GfServer\App\Auth;
use GfServer\App\Controller\AccountController;
use GfServer\App\Controller\ConfirmController;
use GfServer\App\Controller\DownloadsController;
use GfServer\App\Controller\HomeController;
use GfServer\App\Controller\LoginController;
use GfServer\App\Controller\NewsController;
use GfServer\App\Controller\PasswordResetController;
use GfServer\App\Controller\RankingController;
use GfServer\App\Controller\RegisterController;
use GfServer\App\Controller\StatusController;
use GfServer\App\Response;
use GfServer\App\Router;
use GfServer\App\Session;
use GfServer\App\SmtpMailer;
use GfServer\App\View;
use GfServer\Config;
use GfServer\Database;
use GfServer\NewsRepository;
use GfServer\RankingRepository;
use GfServer\RateLimiter;
use GfServer\ServerStatus;
use GfServer\TokenService;

require __DIR__ . '/../vendor/autoload.php';
```

with:

```php
use GfServer\AccountService;
use GfServer\AdminService;
use GfServer\App\Auth;
use GfServer\App\Controller\AccountAdminController;
use GfServer\App\Controller\AccountController;
use GfServer\App\Controller\ConfirmController;
use GfServer\App\Controller\DashboardController;
use GfServer\App\Controller\DownloadsController;
use GfServer\App\Controller\HomeController;
use GfServer\App\Controller\LoginController;
use GfServer\App\Controller\NewsAdminController;
use GfServer\App\Controller\NewsController;
use GfServer\App\Controller\PasswordResetController;
use GfServer\App\Controller\PlayerAdminController;
use GfServer\App\Controller\RankingController;
use GfServer\App\Controller\RegisterController;
use GfServer\App\Controller\StatusController;
use GfServer\App\Response;
use GfServer\App\Router;
use GfServer\App\Session;
use GfServer\App\SmtpMailer;
use GfServer\App\View;
use GfServer\Config;
use GfServer\Database;
use GfServer\NewsRepository;
use GfServer\RankingRepository;
use GfServer\RateLimiter;
use GfServer\ServerStatus;
use GfServer\TokenService;

require __DIR__ . '/../vendor/autoload.php';
```

- [ ] **Step 2: Add the admin routes**

In `web/public/index.php`, replace this exact line:

```php
$router->add('POST', '/reset', 'PasswordResetController', 'submitReset');
```

with:

```php
$router->add('POST', '/reset', 'PasswordResetController', 'submitReset');
$router->add('GET', '/admin', 'DashboardController', 'index');
$router->add('GET', '/admin/news', 'NewsAdminController', 'index');
$router->add('GET', '/admin/news/new', 'NewsAdminController', 'createForm');
$router->add('POST', '/admin/news/new', 'NewsAdminController', 'create');
$router->add('GET', '/admin/news/edit', 'NewsAdminController', 'editForm');
$router->add('POST', '/admin/news/edit', 'NewsAdminController', 'update');
$router->add('POST', '/admin/news/publish', 'NewsAdminController', 'togglePublish');
$router->add('POST', '/admin/news/delete', 'NewsAdminController', 'delete');
$router->add('GET', '/admin/players', 'PlayerAdminController', 'index');
$router->add('POST', '/admin/players/gm', 'PlayerAdminController', 'setGm');
$router->add('POST', '/admin/players/rename', 'PlayerAdminController', 'renamePlayer');
$router->add('POST', '/admin/players/sprite', 'PlayerAdminController', 'renameSprite');
$router->add('GET', '/admin/accounts', 'AccountAdminController', 'index');
$router->add('POST', '/admin/accounts/password', 'AccountAdminController', 'resetPassword');
$router->add('POST', '/admin/accounts/lock', 'AccountAdminController', 'setLocked');
$router->add('POST', '/admin/accounts/resend', 'AccountAdminController', 'resendConfirmation');
```

- [ ] **Step 3: Add the admin controllers to the factory**

In `web/public/index.php`, replace this exact block:

```php
        'PasswordResetController' => new PasswordResetController(
            $view,
            $session,
            new AccountService($getDb()),
            new TokenService($getDb()),
            SmtpMailer::fromEnv(),
            new RateLimiter($getDb()),
        ),
        default => throw new \RuntimeException("Unknown controller: {$name}"),
```

with:

```php
        'PasswordResetController' => new PasswordResetController(
            $view,
            $session,
            new AccountService($getDb()),
            new TokenService($getDb()),
            SmtpMailer::fromEnv(),
            new RateLimiter($getDb()),
        ),
        'DashboardController' => new DashboardController(
            $view,
            $session,
            new Auth($session, $getDb()),
            new ServerStatus($getDb()),
        ),
        'NewsAdminController' => new NewsAdminController(
            $view,
            $session,
            new Auth($session, $getDb()),
            new NewsRepository($getDb()),
        ),
        'PlayerAdminController' => new PlayerAdminController(
            $view,
            $session,
            new Auth($session, $getDb()),
            new AdminService($getDb()),
        ),
        'AccountAdminController' => new AccountAdminController(
            $view,
            $session,
            new Auth($session, $getDb()),
            new AccountService($getDb()),
            new TokenService($getDb()),
            SmtpMailer::fromEnv(),
        ),
        default => throw new \RuntimeException("Unknown controller: {$name}"),
```

- [ ] **Step 4: Verify** — confirm `web/public/index.php` still starts with `<?php`, the dispatch `try`/`catch` and `$response->send()` are unchanged, and the file has LF endings.

- [ ] **Step 5: Commit**

```bash
git add web/public/index.php
git commit -m "feat(web): route the admin UI through the front controller"
```

---

## Task 9: Self-review

- [ ] **Step 1: Review against the Plan 4 scope**

Confirm each deliverable exists:
- `NewsRepository` admin methods + `AccountService::findByUsername` (Tasks 1-2) with tests.
- `AdminController` base + `forbidden.php` (Task 3).
- `DashboardController`, `NewsAdminController`, `PlayerAdminController`, `AccountAdminController` and their templates (Tasks 4-7).
- `index.php` registers every `/admin*` route and builds the four controllers (Task 8).

Confirm: every admin controller action calls `denyUnlessAdmin()` before doing anything, and returns its result when non-null. Every state-changing POST action also calls `csrfValid()`. Every template value is `e()`-escaped or `(int)`-cast. The admin controllers extend `AdminController` (which extends `FormController`).

Report findings. If a genuine gap is found, fix it; otherwise this task is just the review.

- [ ] **Step 2: Commit (only if Step 1 changed anything)**

```bash
git add -A
git commit -m "fix(web): address Plan 4 self-review findings"
```

If Step 1 found nothing to change, skip the commit and report the clean review.

---

## Verification Summary

- **Per task (Windows):** file creation, LF endings.
- **CI (`web-backend` job):** runs migrations + PHPUnit over `web/tests/` — picks up the new `NewsRepositoryTest`/`AccountServiceTest` methods. `gf_web` already has every grant the admin code uses.
- **Deferred to the deployment phase:** the admin pages in a browser, behind a real `web_admin` login — manual acceptance.

## Open Items

1. Admin controllers are thin glue over `NewsRepository`/`AdminService`/`AccountService`; the repository/service methods carry the test coverage, and the pages are verified in the deployment phase.
2. There is no "Admin" link in the public navigation — admins reach `/admin` directly. Adding a conditional nav link is optional polish (it would need a per-request `web_admin` lookup).
3. This is the final plan of Block ③a. Block ③b (forum integration) remains as a separate, later sub-block with its own spec.
