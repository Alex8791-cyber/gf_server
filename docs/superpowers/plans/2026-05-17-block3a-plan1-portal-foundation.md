# Block ③a Plan 1 — Portal Foundation & Deployment

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the foundation of the plain-PHP community portal — the portal database tables, the `web/app` HTTP infrastructure (router, sessions, CSRF, auth, mailer), a working home page, and the Apache/PHP-FPM deployment.

**Architecture:** A front controller (`web/public/index.php`) dispatches via a small exact-match `Router` to `Controller` classes that render PHP templates through a `View`. Domain logic stays in Block ②'s `web/lib`; the new `web/app` namespace holds the HTTP layer. Five `web_*` tables are added to `gf_ls`. Block ①'s `install.sh` is extended to install Apache + PHP-FPM and serve the portal.

**Tech Stack:** PHP 8.2+, PDO (pdo_pgsql), Composer/PHPUnit 11, PostgreSQL 16, Apache 2 + PHP-FPM, bash.

**Spec:** `docs/superpowers/specs/2026-05-17-block3a-core-portal-design.md` (this is Plan 1 of 4 staged plans for Block ③a).

---

## Environment note

The Windows dev machine has no PHP/Composer/psql. Per-task verification = file created, LF endings, `bash -n` for shell. Full verification (PHPUnit against PostgreSQL, migrations) runs in CI — the existing `web-backend` job picks up new migrations and `web/tests/` files automatically; no CI change is needed in this plan. PHP/SQL files MUST use LF line endings (write in binary mode to avoid CRLF).

All work happens on branch `block3a-core-portal` (already created). Each task ends with a commit; end every commit body with a blank line then `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

## File Structure

```
web/
  composer.json            # MODIFIED — add GfServer\App\ + files autoload
  lib/
    AccountService.php     # MODIFIED — add authenticate(), setAccountLocked()
  app/                     # NEW — HTTP layer (namespace GfServer\App)
    helpers.php            # global e() escaping helper
    Session.php
    Csrf.php
    Mailer.php             # interface
    SmtpMailer.php
    FakeMailer.php
    Response.php
    View.php
    Router.php
    Auth.php
    Controller.php         # abstract base
    Controller/
      HomeController.php
  public/                  # NEW — Apache DocumentRoot
    index.php
    assets/style.css
  templates/               # NEW
    layout.php
    home.php
  tests/                   # extended
    SessionTest.php  CsrfTest.php  MailerTest.php
    ViewTest.php  RouterTest.php  AuthTest.php
_utils/db/migrations/gf_ls/002_portal_tables.sql   # NEW
deploy/
  install.sh               # MODIFIED — Apache/PHP-FPM, admin bootstrap
  gfctl                    # MODIFIED — add-admin subcommand
  gfserver.env.example     # MODIFIED — portal vars
docs/deployment-runbook.md # MODIFIED — portal/HTTPS steps
```

---

## Task 1: Scaffolding — autoload, helpers, directories

**Files:**
- Modify: `web/composer.json`
- Create: `web/app/helpers.php`

- [ ] **Step 1: Replace the `autoload` block in `web/composer.json`**

Replace this exact block:

```json
    "autoload": {
        "psr-4": { "GfServer\\": "lib/" }
    },
```

with:

```json
    "autoload": {
        "psr-4": {
            "GfServer\\": "lib/",
            "GfServer\\App\\": "app/"
        },
        "files": [ "app/helpers.php" ]
    },
```

- [ ] **Step 2: Create `web/app/helpers.php`**

```php
<?php

declare(strict_types=1);

if (!function_exists('e')) {
    /** HTML-escape a value for safe output in a template. */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
```

- [ ] **Step 3: Verify**

Confirm `web/app/helpers.php` exists with LF endings; confirm `web/composer.json` is still valid JSON (the `autoload` block now lists two PSR-4 prefixes and a `files` entry).

- [ ] **Step 4: Commit**

```bash
git add web/composer.json web/app/helpers.php
git commit -m "chore(web): add app autoload namespace and e() helper"
```

---

## Task 2: Portal database tables migration

**Files:**
- Create: `_utils/db/migrations/gf_ls/002_portal_tables.sql`

**Note:** Migration files contain plain DDL with no `BEGIN`/`COMMIT` — the runner wraps each in a transaction. `GENERATED ... AS IDENTITY` columns do not need a separate sequence grant (unlike `serial`).

- [ ] **Step 1: Create `_utils/db/migrations/gf_ls/002_portal_tables.sql`**

```sql
-- Migration gf_ls/002: portal tables for the web frontend (Block 3a).

CREATE TABLE public.web_account (
    account_id     integer PRIMARY KEY REFERENCES public.accounts(id),
    email          text UNIQUE,
    email_verified boolean NOT NULL DEFAULT false,
    created_at     timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE public.web_admin (
    account_id integer PRIMARY KEY REFERENCES public.accounts(id),
    granted_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE public.web_news (
    id                bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    title             text NOT NULL,
    body              text NOT NULL,
    author_account_id integer REFERENCES public.accounts(id),
    published_at      timestamptz,
    created_at        timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE public.web_token (
    token      text PRIMARY KEY,
    account_id integer NOT NULL REFERENCES public.accounts(id),
    purpose    text NOT NULL CHECK (purpose IN ('password_reset', 'email_verify')),
    expires_at timestamptz NOT NULL,
    used_at    timestamptz
);

CREATE TABLE public.web_login_attempt (
    id           bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    ip           text NOT NULL,
    attempted_at timestamptz NOT NULL DEFAULT now(),
    success      boolean NOT NULL
);

CREATE INDEX web_login_attempt_ip_time_idx
    ON public.web_login_attempt (ip, attempted_at);

GRANT SELECT, INSERT, UPDATE, DELETE ON public.web_account TO gf_web;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.web_admin TO gf_web;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.web_news TO gf_web;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.web_token TO gf_web;
GRANT SELECT, INSERT, DELETE ON public.web_login_attempt TO gf_web;
```

- [ ] **Step 2: Verify**

Confirm the file exists with LF endings and creates the five `web_*` tables.

- [ ] **Step 3: Commit**

```bash
git add _utils/db/migrations/gf_ls/002_portal_tables.sql
git commit -m "feat(db): add portal tables migration"
```

---

## Task 3: AccountService — authenticate + setAccountLocked

**Files:**
- Modify: `web/lib/AccountService.php`
- Modify: `web/tests/AccountServiceTest.php`

- [ ] **Step 1: Add two tests to `web/tests/AccountServiceTest.php`**

Insert these two methods immediately before the final closing `}` of the class (after `testChangePasswordOnMissingAccountThrows`):

```php

    public function testAuthenticateAcceptsCorrectCredentials(): void
    {
        $username = $this->uniqueName();
        $id = $this->service->register($username, 'auth-good-pw');

        $this->assertSame($id, $this->service->authenticate($username, 'auth-good-pw'));
        $this->assertNull($this->service->authenticate($username, 'wrong-pw'));
        $this->assertNull($this->service->authenticate('tno_such_acct', 'auth-good-pw'));
    }

    public function testSetAccountLockedBlocksAndUnblocksGameLogin(): void
    {
        $username = $this->uniqueName();
        $this->service->register($username, 'lock-test-pw');

        $this->service->setAccountLocked($username, true);
        $locked = $this->db->run(
            'gf_ms',
            "SELECT (account_login(:u, :p, '127.0.0.1')).nRet",
            [':u' => $username, ':p' => 'lock-test-pw'],
        )->fetchColumn();
        $this->assertSame(5, (int) $locked, 'locked account is rejected (nRet=5)');

        $this->service->setAccountLocked($username, false);
        $open = $this->db->run(
            'gf_ms',
            "SELECT (account_login(:u, :p, '127.0.0.1')).nRet",
            [':u' => $username, ':p' => 'lock-test-pw'],
        )->fetchColumn();
        $this->assertSame(1, (int) $open, 'unlocked account is accepted (nRet=1)');
    }
```

- [ ] **Step 2: Add the two methods to `web/lib/AccountService.php`**

Insert these two methods immediately before the `hashPassword()` method:

```php
    /**
     * Verify a username/password pair against the stored bcrypt hash.
     * Returns the accounts.id on success, or null on any failure.
     */
    public function authenticate(string $username, string $password): ?int
    {
        $username = strtolower(trim($username));

        $ok = $this->db->run(
            'gf_ms',
            'SELECT 1 FROM tb_user WHERE mid = :m AND pwd = crypt(:pw, pwd)',
            [':m' => $username, ':pw' => $password],
        )->fetchColumn();
        if ($ok === false) {
            return null;
        }

        $id = $this->db->run(
            'gf_ls',
            'SELECT id FROM accounts WHERE username = :u',
            [':u' => $username],
        )->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Lock or unlock an account for game login by setting tb_user.byauthority
     * (255 = locked, 0 = open). Used by the email-confirmation lifecycle.
     */
    public function setAccountLocked(string $username, bool $locked): void
    {
        $username = strtolower(trim($username));

        $stmt = $this->db->run(
            'gf_ms',
            'UPDATE tb_user SET byauthority = :a WHERE mid = :m',
            [':a' => $locked ? 255 : 0, ':m' => $username],
        );
        if ($stmt->rowCount() === 0) {
            throw new ConflictException("Account '{$username}' not found.");
        }
    }

```

- [ ] **Step 3: Verify**

Confirm both files still have LF endings; confirm `AccountService` now has `authenticate` and `setAccountLocked` methods.

- [ ] **Step 4: Commit**

```bash
git add web/lib/AccountService.php web/tests/AccountServiceTest.php
git commit -m "feat(web): add AccountService authenticate and setAccountLocked"
```

---

## Task 4: Session wrapper

**Files:**
- Create: `web/app/Session.php`, `web/tests/SessionTest.php`

- [ ] **Step 1: Write `web/tests/SessionTest.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\App\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_SESSION'] = [];
    }

    public function testSetAndGet(): void
    {
        $session = new Session();
        $session->set('key', 'value');
        $this->assertSame('value', $session->get('key'));
    }

    public function testGetMissingKeyReturnsNull(): void
    {
        $this->assertNull((new Session())->get('absent'));
    }

    public function testRemove(): void
    {
        $session = new Session();
        $session->set('key', 'value');
        $session->remove('key');
        $this->assertNull($session->get('key'));
    }
}
```

- [ ] **Step 2: Write `web/app/Session.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App;

/**
 * Thin wrapper around PHP sessions with hardened cookie settings.
 * get/set/remove operate on $_SESSION; start()/regenerate()/destroy() manage
 * the underlying PHP session and are exercised at runtime behind the front
 * controller.
 */
final class Session
{
    /** Start the session with hardened cookie parameters (idempotent). */
    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'secure' => true,
            'samesite' => 'Lax',
        ]);
        session_name('GFSESSID');
        session_start();
    }

    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Regenerate the session id (call on login to prevent fixation). */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
```

- [ ] **Step 3: Verify**

Confirm both files exist with LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/app/Session.php web/tests/SessionTest.php
git commit -m "feat(web): add hardened Session wrapper"
```

---

## Task 5: CSRF tokens

**Files:**
- Create: `web/app/Csrf.php`, `web/tests/CsrfTest.php`

- [ ] **Step 1: Write `web/tests/CsrfTest.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\App\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testGenerateReturnsA64CharHexToken(): void
    {
        $token = Csrf::generate();
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testGenerateReturnsADifferentTokenEachCall(): void
    {
        $this->assertNotSame(Csrf::generate(), Csrf::generate());
    }

    public function testCheckAcceptsAMatchingToken(): void
    {
        $token = Csrf::generate();
        $this->assertTrue(Csrf::check($token, $token));
    }

    public function testCheckRejectsMismatchAndNulls(): void
    {
        $this->assertFalse(Csrf::check(Csrf::generate(), Csrf::generate()));
        $this->assertFalse(Csrf::check(null, 'x'));
        $this->assertFalse(Csrf::check('x', null));
        $this->assertFalse(Csrf::check('', ''));
    }
}
```

- [ ] **Step 2: Write `web/app/Csrf.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App;

/**
 * CSRF token helpers. The caller stores generate()'s result in the session
 * and passes it (with the value submitted by the form) to check().
 */
final class Csrf
{
    /** Produce a fresh random CSRF token. */
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Constant-time compare of the session token against the submitted one. */
    public static function check(?string $stored, ?string $submitted): bool
    {
        if ($stored === null || $submitted === null || $stored === '') {
            return false;
        }

        return hash_equals($stored, $submitted);
    }
}
```

- [ ] **Step 3: Verify**

Confirm both files exist with LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/app/Csrf.php web/tests/CsrfTest.php
git commit -m "feat(web): add CSRF token helpers"
```

---

## Task 6: Mailer

**Files:**
- Create: `web/app/Mailer.php`, `web/app/SmtpMailer.php`, `web/app/FakeMailer.php`, `web/tests/MailerTest.php`

- [ ] **Step 1: Write `web/tests/MailerTest.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\App\FakeMailer;
use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase
{
    public function testFakeMailerRecordsSentMessages(): void
    {
        $mailer = new FakeMailer();
        $mailer->send('player@example.com', 'Subject', 'Body text');

        $this->assertCount(1, $mailer->sent);
        $this->assertSame('player@example.com', $mailer->sent[0]['to']);
        $this->assertSame('Subject', $mailer->sent[0]['subject']);
        $this->assertSame('Body text', $mailer->sent[0]['body']);
    }

    public function testFakeMailerLastReturnsTheMostRecentMessage(): void
    {
        $mailer = new FakeMailer();
        $mailer->send('a@example.com', 'First', 'one');
        $mailer->send('b@example.com', 'Second', 'two');

        $this->assertSame('b@example.com', $mailer->last()['to']);
        $this->assertNull((new FakeMailer())->last());
    }
}
```

- [ ] **Step 2: Write `web/app/Mailer.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App;

/** Sends a transactional email. */
interface Mailer
{
    public function send(string $to, string $subject, string $body): void;
}
```

- [ ] **Step 3: Write `web/app/FakeMailer.php`** with EXACTLY this content:

```php
<?php

declare(strict_types=1);

namespace GfServer\App;

/** Test double: records messages instead of sending them. */
final class FakeMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, body: string}> */
    public array $sent = [];

    public function send(string $to, string $subject, string $body): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }

    /** @return array{to: string, subject: string, body: string}|null */
    public function last(): ?array
    {
        if ($this->sent === []) {
            return null;
        }

        return $this->sent[array_key_last($this->sent)];
    }
}
```

- [ ] **Step 4: Write `web/app/SmtpMailer.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App;

/**
 * Sends mail through an SMTP relay using PHP's mail() configured via the
 * relay set in php.ini / the deployment, or directly if a host is given.
 * Connection details come from the GF_SMTP_* environment variables.
 */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
    }

    /** Build an SmtpMailer from GF_SMTP_* / GF_MAIL_FROM* environment variables. */
    public static function fromEnv(): self
    {
        $from = getenv('GF_MAIL_FROM') ?: 'noreply@localhost';
        $name = getenv('GF_MAIL_FROM_NAME') ?: 'Grand Fantasia';

        return new self($from, $name);
    }

    public function send(string $to, string $subject, string $body): void
    {
        $headers = [
            'From' => sprintf('%s <%s>', $this->fromName, $this->fromAddress),
            'Content-Type' => 'text/plain; charset=UTF-8',
            'MIME-Version' => '1.0',
        ];
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $ok = mail($to, $subject, $body, implode("\r\n", $headerLines));
        if ($ok === false) {
            throw new \RuntimeException("Failed to send mail to {$to}.");
        }
    }
}
```

- [ ] **Step 5: Verify**

Confirm all four files exist with LF endings. `web/app/FakeMailer.php` must contain the corrected `last()` body (single `if ($this->sent === [])` form), not the first version.

- [ ] **Step 6: Commit**

```bash
git add web/app/Mailer.php web/app/SmtpMailer.php web/app/FakeMailer.php web/tests/MailerTest.php
git commit -m "feat(web): add Mailer interface with SMTP and fake implementations"
```

---

## Task 7: Response + View

**Files:**
- Create: `web/app/Response.php`, `web/app/View.php`, `web/tests/ViewTest.php`

- [ ] **Step 1: Write `web/tests/ViewTest.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\App\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gfview_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . '/layout.php', '[<?= $content ?>]');
        file_put_contents($this->dir . '/page.php', 'Hi <?= e($name) ?>');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/layout.php');
        @unlink($this->dir . '/page.php');
        @rmdir($this->dir);
    }

    public function testRenderWrapsTheTemplateInTheLayout(): void
    {
        $html = (new View($this->dir))->render('page', ['name' => 'Bob']);
        $this->assertSame('[Hi Bob]', $html);
    }

    public function testRenderEscapesDataViaTheEHelper(): void
    {
        $html = (new View($this->dir))->render('page', ['name' => '<script>']);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testRenderRejectsAMissingTemplate(): void
    {
        $this->expectException(\RuntimeException::class);
        (new View($this->dir))->render('does_not_exist');
    }
}
```

- [ ] **Step 2: Write `web/app/Response.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App;

/** An HTTP response: status, headers and body. */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /** Emit the response (status, headers, body) to the client. */
    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->body;
    }
}
```

- [ ] **Step 3: Write `web/app/View.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App;

/**
 * Renders a PHP template and wraps its output in templates/layout.php.
 * Templates receive the data array as local variables and may call e().
 */
final class View
{
    public function __construct(private readonly string $templateDir)
    {
    }

    /**
     * Render $template (without .php) wrapped in the layout.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $content = $this->capture($template, $data);

        return $this->capture('layout', ['content' => $content] + $data);
    }

    /** @param array<string, mixed> $data */
    private function capture(string $template, array $data): string
    {
        $file = "{$this->templateDir}/{$template}.php";
        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }
}
```

- [ ] **Step 4: Verify**

Confirm the three files exist with LF endings.

- [ ] **Step 5: Commit**

```bash
git add web/app/Response.php web/app/View.php web/tests/ViewTest.php
git commit -m "feat(web): add Response and template View"
```

---

## Task 8: Router

**Files:**
- Create: `web/app/Router.php`, `web/tests/RouterTest.php`

- [ ] **Step 1: Write `web/tests/RouterTest.php`** with EXACTLY this content (all identifiers are plain ASCII):

```php
<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\App\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchReturnsTheRegisteredHandler(): void
    {
        $router = new Router();
        $router->add('GET', '/', 'HomeController', 'index');

        $this->assertSame(['HomeController', 'index'], $router->match('GET', '/'));
    }

    public function testMatchReturnsNullForAnUnknownPath(): void
    {
        $router = new Router();
        $router->add('GET', '/', 'HomeController', 'index');

        $this->assertNull($router->match('GET', '/missing'));
        $this->assertNull($router->match('POST', '/'));
    }

    public function testTrailingSlashIsNormalisedAwayExceptRoot(): void
    {
        $router = new Router();
        $router->add('GET', '/news', 'NewsController', 'index');

        $this->assertSame(['NewsController', 'index'], $router->match('GET', '/news/'));
    }
}
```

- [ ] **Step 2: Write `web/app/Router.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App;

/**
 * Exact-match router: maps "METHOD /path" to a [controller, action] pair.
 * Path parameters are not used — dynamic pages take ids via the query string.
 */
final class Router
{
    /** @var array<string, array{0: string, 1: string}> */
    private array $routes = [];

    public function add(string $method, string $path, string $controller, string $action): void
    {
        $this->routes[$this->key($method, $path)] = [$controller, $action];
    }

    /** @return array{0: string, 1: string}|null */
    public function match(string $method, string $path): ?array
    {
        return $this->routes[$this->key($method, $path)] ?? null;
    }

    private function key(string $method, string $path): string
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        return strtoupper($method) . ' ' . $path;
    }
}
```

- [ ] **Step 3: Verify**

Confirm both files exist with LF endings; confirm `RouterTest.php` uses the corrected ASCII method names (no Cyrillic characters).

- [ ] **Step 4: Commit**

```bash
git add web/app/Router.php web/tests/RouterTest.php
git commit -m "feat(web): add exact-match Router"
```

---

## Task 9: Auth

**Files:**
- Create: `web/app/Auth.php`, `web/tests/AuthTest.php`

- [ ] **Step 1: Write `web/tests/AuthTest.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\App\Auth;
use GfServer\App\Session;

final class AuthTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_SESSION'] = [];
    }

    public function testLoggedOutByDefault(): void
    {
        $auth = new Auth(new Session(), $this->db);
        $this->assertFalse($auth->isLoggedIn());
        $this->assertNull($auth->accountId());
        $this->assertFalse($auth->isAdmin());
    }

    public function testLoginRecordsTheAccountInTheSession(): void
    {
        $session = new Session();
        $auth = new Auth($session, $this->db);
        $auth->login(4242, 'someplayer');

        $this->assertTrue($auth->isLoggedIn());
        $this->assertSame(4242, $auth->accountId());
        $this->assertSame('someplayer', $auth->username());
    }

    public function testIsAdminReflectsWebAdminMembership(): void
    {
        $admin = $this->adminPdo('gf_ls');
        $admin->exec('INSERT INTO web_admin (account_id) VALUES (2100001)');

        try {
            $session = new Session();
            $auth = new Auth($session, $this->db);

            $auth->login(2100001, 'theadmin');
            $this->assertTrue($auth->isAdmin());

            $auth->login(2100002, 'notadmin');
            $this->assertFalse($auth->isAdmin());
        } finally {
            $admin->exec('DELETE FROM web_admin WHERE account_id = 2100001');
        }
    }
}
```

- [ ] **Step 2: Write `web/app/Auth.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App;

use GfServer\Database;

/**
 * Portal authentication state. Login identity lives in the session; admin
 * status is determined by membership in the web_admin table (gf_ls).
 */
final class Auth
{
    private const SESSION_ACCOUNT_ID = 'auth.account_id';
    private const SESSION_USERNAME = 'auth.username';

    public function __construct(
        private readonly Session $session,
        private readonly Database $db,
    ) {
    }

    public function login(int $accountId, string $username): void
    {
        $this->session->regenerate();
        $this->session->set(self::SESSION_ACCOUNT_ID, $accountId);
        $this->session->set(self::SESSION_USERNAME, $username);
    }

    public function logout(): void
    {
        $this->session->remove(self::SESSION_ACCOUNT_ID);
        $this->session->remove(self::SESSION_USERNAME);
    }

    public function isLoggedIn(): bool
    {
        return $this->session->get(self::SESSION_ACCOUNT_ID) !== null;
    }

    public function accountId(): ?int
    {
        $id = $this->session->get(self::SESSION_ACCOUNT_ID);

        return $id === null ? null : (int) $id;
    }

    public function username(): ?string
    {
        $name = $this->session->get(self::SESSION_USERNAME);

        return $name === null ? null : (string) $name;
    }

    /** True if the logged-in account is listed in web_admin. */
    public function isAdmin(): bool
    {
        $id = $this->accountId();
        if ($id === null) {
            return false;
        }

        $row = $this->db->run(
            'gf_ls',
            'SELECT 1 FROM web_admin WHERE account_id = :id',
            [':id' => $id],
        )->fetchColumn();

        return $row !== false;
    }
}
```

- [ ] **Step 3: Verify**

Confirm both files exist with LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/app/Auth.php web/tests/AuthTest.php
git commit -m "feat(web): add portal Auth (session identity + admin check)"
```

---

## Task 10: Controller base, Home page, layout, CSS

**Files:**
- Create: `web/app/Controller.php`, `web/app/Controller/HomeController.php`, `web/templates/layout.php`, `web/templates/home.php`, `web/public/assets/style.css`

- [ ] **Step 1: Write `web/app/Controller.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App;

/** Base controller: gives subclasses a View and an html() helper. */
abstract class Controller
{
    public function __construct(protected readonly View $view)
    {
    }

    /**
     * Render a template (wrapped in the layout) into an HTML Response.
     *
     * @param array<string, mixed> $data
     */
    protected function html(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data), $status);
    }
}
```

- [ ] **Step 2: Write `web/app/Controller/HomeController.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\App\Controller;
use GfServer\App\Response;

/** The portal landing page. */
final class HomeController extends Controller
{
    public function index(): Response
    {
        return $this->html('home', ['title' => 'Home']);
    }
}
```

- [ ] **Step 3: Write `web/templates/layout.php`**

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Grand Fantasia') ?> &mdash; Grand Fantasia</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <header class="site-header">
        <a class="brand" href="/">Grand Fantasia</a>
        <nav>
            <a href="/">Home</a>
            <a href="/news">News</a>
            <a href="/downloads">Download</a>
            <a href="/status">Status</a>
            <a href="/rankings">Rankings</a>
        </nav>
    </header>
    <main class="site-main">
        <?= $content ?>
    </main>
    <footer class="site-footer">
        <p>Grand Fantasia private server.</p>
    </footer>
</body>
</html>
```

- [ ] **Step 4: Write `web/templates/home.php`**

```php
<section class="hero">
    <h1>Welcome to Grand Fantasia</h1>
    <p>A community private server. Create an account and start your adventure.</p>
    <p class="cta">
        <a class="button" href="/register">Create account</a>
        <a class="button button-secondary" href="/downloads">Download the client</a>
    </p>
</section>
```

- [ ] **Step 5: Write `web/public/assets/style.css`**

```css
:root {
    --bg: #1b1d24;
    --panel: #252833;
    --text: #e8e8ec;
    --muted: #9aa0ad;
    --accent: #c8a24a;
    --link: #7cc4ff;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.6;
}

a { color: var(--link); }

.site-header {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    padding: 1rem 2rem;
    background: var(--panel);
    border-bottom: 2px solid var(--accent);
}

.site-header .brand {
    font-weight: 700;
    font-size: 1.25rem;
    color: var(--accent);
    text-decoration: none;
}

.site-header nav { display: flex; gap: 1rem; }
.site-header nav a { text-decoration: none; }

.site-main {
    max-width: 960px;
    margin: 0 auto;
    padding: 2rem;
}

.hero { text-align: center; padding: 2rem 0; }
.hero h1 { font-size: 2.25rem; margin-bottom: 0.5rem; }
.cta { margin-top: 1.5rem; }

.button {
    display: inline-block;
    padding: 0.6rem 1.2rem;
    margin: 0.25rem;
    background: var(--accent);
    color: #1b1d24;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 600;
}

.button-secondary {
    background: transparent;
    color: var(--text);
    border: 1px solid var(--muted);
}

.site-footer {
    text-align: center;
    padding: 1.5rem;
    color: var(--muted);
    border-top: 1px solid var(--panel);
}
```

- [ ] **Step 6: Verify**

Confirm all five files exist with LF endings.

- [ ] **Step 7: Commit**

```bash
git add web/app/Controller.php web/app/Controller/HomeController.php web/templates/layout.php web/templates/home.php web/public/assets/style.css
git commit -m "feat(web): add Controller base, home page, layout and stylesheet"
```

---

## Task 11: Front controller

**Files:**
- Create: `web/public/index.php`

- [ ] **Step 1: Write `web/public/index.php`**

```php
<?php

declare(strict_types=1);

use GfServer\App\Controller\HomeController;
use GfServer\App\Response;
use GfServer\App\Router;
use GfServer\App\Session;
use GfServer\App\View;

require __DIR__ . '/../vendor/autoload.php';

// --- Security headers ------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'");

// --- Wiring ----------------------------------------------------------------
$session = new Session();
$session->start();

$view = new View(__DIR__ . '/../templates');

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

// --- Dispatch --------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$route = $router->match($method, $path);

try {
    if ($route === null) {
        $response = Response::html($view->render('home', ['title' => 'Not found']), 404);
    } else {
        [$controllerName, $action] = $route;
        $controller = $makeController($controllerName);
        $response = $controller->$action();
    }
} catch (\Throwable $e) {
    error_log('Portal error: ' . $e->getMessage());
    $response = Response::html('<h1>Internal error</h1>', 500);
}

$response->send();
```

- [ ] **Step 2: Verify**

Confirm `web/public/index.php` exists with LF endings.

- [ ] **Step 3: Commit**

```bash
git add web/public/index.php
git commit -m "feat(web): add front controller"
```

---

## Task 12: install.sh — Apache + PHP-FPM web server

**Files:**
- Modify: `deploy/install.sh`

- [ ] **Step 1: Add a `setup_web_server` function to `deploy/install.sh`**

Locate the line `# --- 6. Render component setup.ini files` and insert the following function on the lines immediately BEFORE it:

```bash
# --- 5d. Web server (Apache + PHP-FPM) -------------------------------------
setup_web_server() {
  log "Installing Apache and PHP-FPM..."
  apt-get install -y \
    apache2 php-fpm php-cli php-pgsql php-mbstring \
    composer certbot python3-certbot-apache

  log "Building the portal web dependencies..."
  sudo -u "$GF_USER" composer install --no-interaction --no-progress \
    --no-dev --working-dir "${GF_ROOT}/web"

  # Apache must traverse ${GF_ROOT} to reach web/public.
  usermod -aG "$GF_GROUP" www-data
  chmod 750 "$GF_ROOT"

  log "Writing the Apache virtual host..."
  cat > /etc/apache2/sites-available/gfserver.conf <<APACHE
<VirtualHost *:80>
    ServerName ${PORTAL_DOMAIN}
    DocumentRoot ${GF_ROOT}/web/public

    <Directory ${GF_ROOT}/web/public>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>

    <FilesMatch \.php\$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/gfserver-error.log
    CustomLog \${APACHE_LOG_DIR}/gfserver-access.log combined
</VirtualHost>
APACHE

  a2enmod proxy_fcgi setenvif >/dev/null
  a2dissite 000-default >/dev/null 2>&1 || true
  a2ensite gfserver >/dev/null
  systemctl reload apache2
  log "Web server ready. Run 'certbot --apache' once DNS points at this host (see runbook)."
}
```

- [ ] **Step 2: Wire `setup_web_server` into `main()`**

Replace this exact block in `deploy/install.sh`:

```
  configure_postgres
  setup_web_role
  run_migrations
  render_configs
```

with:

```
  configure_postgres
  setup_web_role
  run_migrations
  setup_web_server
  render_configs
```

- [ ] **Step 3: Open ports 80 and 443 in the firewall**

In `deploy/install.sh`, in the `configure_firewall` function, replace this exact block:

```
  local port
  for port in $GAME_PORTS; do
    ufw allow "${port}/tcp" comment 'GF game port'
  done
```

with:

```
  ufw allow 80/tcp comment 'HTTP'
  ufw allow 443/tcp comment 'HTTPS'
  local port
  for port in $GAME_PORTS; do
    ufw allow "${port}/tcp" comment 'GF game port'
  done
```

- [ ] **Step 4: Verify**

Run: `bash -n deploy/install.sh`
Expected: no output, exit 0.

- [ ] **Step 5: Commit**

```bash
git add deploy/install.sh
git commit -m "feat(deploy): install Apache + PHP-FPM and serve the portal"
```

---

## Task 13: Admin bootstrap, env vars, runbook, self-review

**Files:**
- Modify: `deploy/install.sh`, `deploy/gfctl`, `deploy/gfserver.env.example`, `docs/deployment-runbook.md`

- [ ] **Step 1: Add a `bootstrap_admin` function to `deploy/install.sh`**

Locate the line `# --- 5d. Web server (Apache + PHP-FPM)` and insert the following function on the lines immediately BEFORE it:

```bash
# --- 5c2. First portal admin -----------------------------------------------
bootstrap_admin() {
  if [ -z "${ADMIN_ACCOUNT:-}" ]; then
    log "ADMIN_ACCOUNT not set — skipping portal admin bootstrap."
    return
  fi
  log "Granting portal admin to '${ADMIN_ACCOUNT}'..."
  sudo -u postgres psql -v ON_ERROR_STOP=1 -q -d gf_ls <<SQL
INSERT INTO web_admin (account_id)
SELECT id FROM accounts WHERE username = lower('${ADMIN_ACCOUNT}')
ON CONFLICT (account_id) DO NOTHING;
SQL
}
```

- [ ] **Step 2: Wire `bootstrap_admin` into `main()`**

Replace this exact block in `deploy/install.sh`:

```
  run_migrations
  setup_web_server
```

with:

```
  run_migrations
  bootstrap_admin
  setup_web_server
```

- [ ] **Step 3: Add an `add-admin` subcommand to `deploy/gfctl`**

Apply two exact-string edits to `deploy/gfctl`:

(a) Replace this line:

```
  migrate) require_root; "${SCRIPT_DIR}/migrate.sh" ;;
```

with these two lines:

```
  migrate) require_root; "${SCRIPT_DIR}/migrate.sh" ;;
  add-admin) require_root; shift; cmd_add_admin "${1:-}" ;;
```

(b) Replace this line:

```
  migrate  Apply pending database migrations
```

with these two lines:

```
  migrate  Apply pending database migrations
  add-admin  Grant portal admin to an account (add-admin <username>)
```

- [ ] **Step 4: Add the `cmd_add_admin` function to `deploy/gfctl`**

In `deploy/gfctl`, locate the line `case "${1:-}" in` and insert the following function on the lines immediately BEFORE it:

```bash
cmd_add_admin() {
  local username="${1:-}"
  [ -n "$username" ] || die "add-admin needs a username."
  sudo -u postgres psql -v ON_ERROR_STOP=1 -q -d gf_ls <<SQL
INSERT INTO web_admin (account_id)
SELECT id FROM accounts WHERE username = lower('${username}')
ON CONFLICT (account_id) DO NOTHING;
SQL
  log "Portal admin granted to '${username}' (if the account exists)."
}

```

- [ ] **Step 5: Add portal variables to `deploy/gfserver.env.example`**

Append this block to the end of `deploy/gfserver.env.example`:

```bash

# --- Portal (Block 3a) -----------------------------------------------------
# Domain the portal is served on (used for the Apache virtual host).
PORTAL_DOMAIN=gf.example.com

# Account username granted portal admin on install (leave empty to skip).
ADMIN_ACCOUNT=

# Outgoing mail identity for account emails.
GF_MAIL_FROM=noreply@gf.example.com
GF_MAIL_FROM_NAME=Grand Fantasia
```

- [ ] **Step 6: Add a portal section to `docs/deployment-runbook.md`**

Append this section to the end of `docs/deployment-runbook.md`:

```markdown

## Portal (Block 3a)

`install.sh` also installs Apache + PHP-FPM and serves the portal from
`/opt/gfserver/web/public` on port 80.

1. Point your portal domain's DNS A record at the VPS, and set
   `PORTAL_DOMAIN`, `ADMIN_ACCOUNT` and the `GF_MAIL_FROM*` values in
   `deploy/gfserver.env` before running `install.sh`.
2. After install, enable HTTPS:
   ```bash
   sudo certbot --apache -d <your-portal-domain>
   ```
   certbot adds the TLS virtual host and the HTTP→HTTPS redirect.
3. Configure an SMTP relay for outgoing mail (account confirmation and
   password reset). Until a relay is configured, mail sending will fail.
4. Grant additional portal admins at any time:
   ```bash
   sudo deploy/gfctl add-admin <username>
   ```

Verify: browse to the domain — the portal home page should load.
```

- [ ] **Step 7: Self-review against the Plan 1 scope**

Confirm each deliverable exists:
- Portal tables migration → Task 2.
- `AccountService::authenticate` / `setAccountLocked` → Task 3.
- `web/app` infrastructure (Session, Csrf, Mailer×3, Response, View, Router, Auth, Controller) → Tasks 4-10.
- Home page + layout + CSS + front controller → Tasks 10-11.
- Apache/PHP-FPM deployment + firewall + admin bootstrap + runbook → Tasks 12-13.

Confirm `bash -n deploy/install.sh` and `bash -n deploy/gfctl` both pass. Confirm no file under `web/app` or `web/templates` builds SQL or HTML by unescaped concatenation of request input.

- [ ] **Step 8: Commit**

```bash
git add deploy/install.sh deploy/gfctl deploy/gfserver.env.example docs/deployment-runbook.md
git commit -m "feat(deploy): portal admin bootstrap, env vars and runbook"
```

---

## Verification Summary

- **Per task (Windows):** file creation, LF endings, `bash -n` for shell scripts.
- **CI (`web-backend` job):** the existing job applies all migrations and runs all of `web/tests/` against PostgreSQL 16 — it picks up `gf_ls/002` and the six new test files with no workflow change. `composer install` regenerates the autoloader for the new `GfServer\App\` namespace.
- **Deferred to the deployment phase:** the Apache/PHP-FPM/HTTPS setup, real SMTP, and browsing the live home page — manual acceptance, consistent with the deferred-deployment-testing decision.

## Open Items

1. The home page is intentionally minimal in Plan 1 (static welcome). Plan 2
   enriches it / adds the public pages (news, downloads, status, rankings).
2. SMTP relay configuration is a deployment-phase step; `SmtpMailer` uses
   PHP `mail()` and depends on a relay being configured on the host.
3. Plans 3 and 4 add the account self-service flows and the admin UI; they
   register their routes/controllers in the `$makeController` factory in
   `web/public/index.php`.
