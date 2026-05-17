# Block ③a Plan 3 — Account Self-Service

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the portal's account self-service — registration with email confirmation, login/logout, password change, password reset, and an account page — with CSRF protection, token flows and rate limiting.

**Architecture:** Two new `web/lib` services (`TokenService`, `RateLimiter`) wrap the `web_token`/`web_login_attempt` tables; `AccountService` (Block ②) gains email/confirmation methods. A `FormController` base adds CSRF, flash messages and redirects. Five controllers render PHP form templates and are wired into the front controller. Email goes through the `Mailer` interface.

**Tech Stack:** PHP 8.2+, PDO (pdo_pgsql), PHPUnit 11, PostgreSQL 16, Apache 2 + PHP-FPM.

**Spec:** `docs/superpowers/specs/2026-05-17-block3a-core-portal-design.md` (Plan 3 of 4 for Block ③a).

---

## Environment note

No PHP/Composer/psql on the Windows dev machine. Per-task verification = file created, LF endings (no CR bytes), `bash -n` for shell. Full verification (PHPUnit against PostgreSQL) runs in CI — the existing `web-backend` job picks up new `web/lib`/`web/tests` files automatically. PHP files MUST use LF line endings; write content verbatim (do not let `\n`/`\r`/`\$` escapes be interpreted). **No database migration is needed:** `web_token`, `web_login_attempt`, `web_account` and their `gf_web` grants already exist (Plan 1 migration `gf_ls/002`).

All work happens on branch `block3a-plan3-account-self-service` (create from `main`: `git checkout main && git pull && git checkout -b block3a-plan3-account-self-service`). Each task ends with a commit; end every commit body with a blank line then `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

## File Structure

```
web/lib/
  Validation.php          # MODIFIED — add email() rule
  TokenService.php        # NEW — web_token create/consume
  RateLimiter.php         # NEW — web_login_attempt record/check
  AccountService.php      # MODIFIED — register(+email), findByEmail,
                          #            confirmEmail, changePasswordForAccount,
                          #            webAccount
web/app/
  View.php                # MODIFIED — add share()
  Auth.php                # MODIFIED — make session-key consts public
  FormController.php       # NEW — CSRF / flash / redirect base
  Controller/
    RegisterController.php       # NEW
    ConfirmController.php        # NEW
    LoginController.php          # NEW
    AccountController.php        # NEW
    PasswordResetController.php  # NEW
web/templates/
  register.php register_done.php confirm.php login.php account.php
  password.php forgot.php forgot_done.php reset.php reset_done.php   # NEW
  layout.php              # MODIFIED — auth-aware nav
web/public/index.php      # MODIFIED — routes, controllers, shared nav state
web/tests/
  TokenServiceTest.php RateLimiterTest.php   # NEW
  ValidationTest.php AccountServiceTest.php ViewTest.php   # MODIFIED
deploy/install.sh         # MODIFIED — PORTAL_DOMAIN in the FPM pool env
```

---

## Task 1: Validation::email

**Files:** Modify `web/lib/Validation.php`, `web/tests/ValidationTest.php`

- [ ] **Step 1: Add a test to `web/tests/ValidationTest.php`**

Insert this method immediately before the final closing `}` of the class:

```php

    public function testEmailAcceptsValidAndRejectsInvalid(): void
    {
        Validation::email('player@example.com');

        $this->expectException(ValidationException::class);
        Validation::email('not-an-email');
    }
```

- [ ] **Step 2: Add the `email` method to `web/lib/Validation.php`**

Insert this method immediately before the `characterName` method:

```php
    /** Email address: must be syntactically valid and at most 254 chars. */
    public static function email(string $email): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new ValidationException('Please enter a valid email address.');
        }
    }

```

- [ ] **Step 3: Verify** — confirm both files have LF endings and `Validation` now has an `email` method.

- [ ] **Step 4: Commit**

```bash
git add web/lib/Validation.php web/tests/ValidationTest.php
git commit -m "feat(web): add email validation rule"
```

---

## Task 2: TokenService

**Files:** Create `web/lib/TokenService.php`, `web/tests/TokenServiceTest.php`

- [ ] **Step 1: Write `web/tests/TokenServiceTest.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\TokenService;

final class TokenServiceTest extends DbTestCase
{
    private TokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new TokenService($this->db);
        $this->seedAccount(2300001);
    }

    protected function tearDown(): void
    {
        $admin = $this->adminPdo('gf_ls');
        $admin->exec('DELETE FROM web_token WHERE account_id = 2300001');
        $admin->exec('DELETE FROM accounts WHERE id = 2300001');
    }

    private function seedAccount(int $id): void
    {
        $this->adminPdo('gf_ls')->exec(
            "INSERT INTO accounts (id, username) VALUES ({$id}, 'ttoken{$id}') "
            . "ON CONFLICT (id) DO NOTHING"
        );
    }

    public function testCreatedTokenCanBeConsumedOnce(): void
    {
        $raw = $this->tokens->create(2300001, 'email_verify', 3600);

        $this->assertSame(2300001, $this->tokens->consume($raw, 'email_verify'));
        $this->assertNull($this->tokens->consume($raw, 'email_verify'), 'token is single-use');
    }

    public function testConsumeRejectsAWrongPurpose(): void
    {
        $raw = $this->tokens->create(2300001, 'email_verify', 3600);
        $this->assertNull($this->tokens->consume($raw, 'password_reset'));
    }

    public function testConsumeRejectsAnExpiredToken(): void
    {
        $raw = $this->tokens->create(2300001, 'password_reset', -10);
        $this->assertNull($this->tokens->consume($raw, 'password_reset'));
    }

    public function testConsumeRejectsAnUnknownToken(): void
    {
        $this->assertNull($this->tokens->consume('deadbeef', 'email_verify'));
    }
}
```

- [ ] **Step 2: Write `web/lib/TokenService.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer;

/**
 * Single-use, expiring tokens for email confirmation and password reset
 * (table web_token, gf_ls). Only the SHA-256 hash of a token is stored; the
 * raw token is what gets emailed to the user.
 */
final class TokenService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Create a token for an account and return the raw token to email out.
     * $ttlSeconds is an internal integer, so concatenating it is injection-safe.
     */
    public function create(int $accountId, string $purpose, int $ttlSeconds): string
    {
        $raw = bin2hex(random_bytes(32));

        $this->db->run(
            'gf_ls',
            'INSERT INTO web_token (token, account_id, purpose, expires_at) '
            . 'VALUES (:t, :a, :p, now() + make_interval(secs => ' . $ttlSeconds . '))',
            [':t' => hash('sha256', $raw), ':a' => $accountId, ':p' => $purpose],
        );

        return $raw;
    }

    /**
     * Validate and consume a token. Returns the account id on success (and
     * marks the token used), or null if it is unknown, the wrong purpose,
     * expired, or already used.
     */
    public function consume(string $rawToken, string $purpose): ?int
    {
        $hash = hash('sha256', $rawToken);

        $row = $this->db->run(
            'gf_ls',
            'SELECT account_id FROM web_token '
            . 'WHERE token = :t AND purpose = :p '
            . 'AND used_at IS NULL AND expires_at > now()',
            [':t' => $hash, ':p' => $purpose],
        )->fetch();

        if ($row === false) {
            return null;
        }

        $this->db->run(
            'gf_ls',
            'UPDATE web_token SET used_at = now() WHERE token = :t',
            [':t' => $hash],
        );

        return (int) $row['account_id'];
    }
}
```

- [ ] **Step 3: Verify** — confirm both files exist with LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/lib/TokenService.php web/tests/TokenServiceTest.php
git commit -m "feat(web): add TokenService"
```

---

## Task 3: RateLimiter

**Files:** Create `web/lib/RateLimiter.php`, `web/tests/RateLimiterTest.php`

- [ ] **Step 1: Write `web/tests/RateLimiterTest.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\RateLimiter;

final class RateLimiterTest extends DbTestCase
{
    private RateLimiter $limiter;
    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = new RateLimiter($this->db);
        $this->ip = '203.0.113.' . random_int(2, 254);
    }

    protected function tearDown(): void
    {
        $this->adminPdo('gf_ls')
            ->prepare('DELETE FROM web_login_attempt WHERE ip = :ip')
            ->execute([':ip' => $this->ip]);
    }

    public function testFailuresAccumulateAndTripTheLimit(): void
    {
        $this->assertFalse($this->limiter->tooManyFailures($this->ip, 3, 3600));

        $this->limiter->record($this->ip, false);
        $this->limiter->record($this->ip, false);
        $this->assertFalse($this->limiter->tooManyFailures($this->ip, 3, 3600));

        $this->limiter->record($this->ip, false);
        $this->assertTrue($this->limiter->tooManyFailures($this->ip, 3, 3600));
    }

    public function testSuccessfulAttemptsDoNotCountAsFailures(): void
    {
        $this->limiter->record($this->ip, true);
        $this->limiter->record($this->ip, true);
        $this->assertFalse($this->limiter->tooManyFailures($this->ip, 1, 3600));
    }
}
```

- [ ] **Step 2: Write `web/lib/RateLimiter.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer;

/**
 * IP-based rate limiting backed by web_login_attempt (gf_ls). Used to throttle
 * login, registration and password-reset requests.
 */
final class RateLimiter
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Record one attempt from $ip. */
    public function record(string $ip, bool $success): void
    {
        $this->db->run(
            'gf_ls',
            'INSERT INTO web_login_attempt (ip, success) VALUES (:ip, :s)',
            [':ip' => $ip, ':s' => $success ? 'true' : 'false'],
        );
    }

    /**
     * True if $ip has had at least $max failed attempts within the last
     * $windowSeconds. $windowSeconds is an internal integer.
     */
    public function tooManyFailures(string $ip, int $max, int $windowSeconds): bool
    {
        $count = (int) $this->db->run(
            'gf_ls',
            'SELECT count(*) FROM web_login_attempt '
            . 'WHERE ip = :ip AND success = false '
            . 'AND attempted_at > now() - make_interval(secs => ' . $windowSeconds . ')',
            [':ip' => $ip],
        )->fetchColumn();

        return $count >= $max;
    }
}
```

- [ ] **Step 3: Verify** — confirm both files exist with LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/lib/RateLimiter.php web/tests/RateLimiterTest.php
git commit -m "feat(web): add RateLimiter"
```

---

## Task 4: AccountService email/confirmation methods

**Files:** Modify `web/lib/AccountService.php`, `web/tests/AccountServiceTest.php`

**Background:** Block ②'s `register(string $username, string $password): int` creates `tb_user` + `accounts`. This task adds an optional `$email`: when given, the account is created **locked** (`tb_user.byauthority = 255`) and a `web_account` row is written. Existing callers that pass no email keep the old behaviour. Four read/confirmation methods are also added.

- [ ] **Step 1: Add tests to `web/tests/AccountServiceTest.php`**

Insert these methods immediately before the final closing `}` of the class:

```php

    public function testRegisterWithEmailLocksTheAccountAndStoresTheEmail(): void
    {
        $username = $this->uniqueName();
        $id = $this->service->register($username, 'email-reg-pw', 'a' . $username . '@example.com');

        $byauthority = $this->db->run(
            'gf_ms',
            'SELECT byauthority FROM tb_user WHERE mid = :m',
            [':m' => $username],
        )->fetchColumn();
        $this->assertSame(255, (int) $byauthority, 'account starts locked');

        $web = $this->db->run(
            'gf_ls',
            'SELECT email, email_verified FROM web_account WHERE account_id = :id',
            [':id' => $id],
        )->fetch();
        $this->assertSame('a' . $username . '@example.com', $web['email']);
    }

    public function testFindByEmailReturnsTheAccountId(): void
    {
        $username = $this->uniqueName();
        $email = 'f' . $username . '@example.com';
        $id = $this->service->register($username, 'find-email-pw', $email);

        $this->assertSame($id, $this->service->findByEmail($email));
        $this->assertNull($this->service->findByEmail('nobody@example.com'));
    }

    public function testConfirmEmailVerifiesAndUnlocksTheAccount(): void
    {
        $username = $this->uniqueName();
        $id = $this->service->register($username, 'confirm-pw', 'c' . $username . '@example.com');

        $this->service->confirmEmail($id);

        $verified = $this->db->run(
            'gf_ls',
            'SELECT email_verified FROM web_account WHERE account_id = :id',
            [':id' => $id],
        )->fetchColumn();
        $this->assertTrue($verified === true || $verified === 't' || $verified === '1');

        $login = $this->db->run(
            'gf_ms',
            "SELECT (account_login(:u, :p, '127.0.0.1')).nRet",
            [':u' => $username, ':p' => 'confirm-pw'],
        )->fetchColumn();
        $this->assertSame(1, (int) $login, 'confirmed account can log in');
    }

    public function testChangePasswordForAccountUpdatesByAccountId(): void
    {
        $username = $this->uniqueName();
        $id = $this->service->register($username, 'old-acc-pw', 'p' . $username . '@example.com');
        $this->service->confirmEmail($id);

        $this->service->changePasswordForAccount($id, 'new-acc-pw');

        $login = $this->db->run(
            'gf_ms',
            "SELECT (account_login(:u, :p, '127.0.0.1')).nRet",
            [':u' => $username, ':p' => 'new-acc-pw'],
        )->fetchColumn();
        $this->assertSame(1, (int) $login);
    }
```

- [ ] **Step 2: Replace the `register` method in `web/lib/AccountService.php`**

Replace the entire existing `register` method (from its doc comment `/** Register a new account. Returns the new accounts.id. */` through its closing `}`) with:

```php
    /**
     * Register a new account. Returns the new accounts.id.
     *
     * When $email is given the account is created locked
     * (tb_user.byauthority = 255) and a web_account row is stored — the
     * email-confirmation flow unlocks it. When $email is null the account is
     * created unlocked (the original Block 2 behaviour).
     */
    public function register(string $username, string $password, ?string $email = null): int
    {
        $username = strtolower(trim($username));
        Validation::username($username);
        Validation::password($password);
        if ($email !== null) {
            Validation::email($email);
        }

        $hash = $this->hashPassword($password);

        $taken = $this->db->run(
            'gf_ms',
            'SELECT 1 FROM tb_user WHERE mid = :m',
            [':m' => $username],
        )->fetchColumn();
        if ($taken !== false) {
            throw new ConflictException("Username '{$username}' is already taken.");
        }

        if ($email !== null) {
            $emailTaken = $this->db->run(
                'gf_ls',
                'SELECT 1 FROM web_account WHERE email = :e',
                [':e' => $email],
            )->fetchColumn();
            if ($emailTaken !== false) {
                throw new ConflictException('That email address is already registered.');
            }
        }

        // 1) gf_ms.tb_user — the PK on mid makes this the uniqueness gate.
        //    byauthority 255 = locked (pending email confirmation).
        try {
            $this->db->run(
                'gf_ms',
                'INSERT INTO tb_user (mid, password, pwd, pvalues, byauthority) '
                . 'VALUES (:m, :pw, :pw, 99999, :auth)',
                [':m' => $username, ':pw' => $hash, ':auth' => $email !== null ? 255 : 0],
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new ConflictException("Username '{$username}' is already taken.");
            }
            throw new DatabaseException('Failed to create account.', 0, $e);
        }

        // 2) gf_ls — allocate the id under an exclusive lock, write accounts
        //    and (when registering with email) web_account, atomically.
        try {
            return $this->db->transaction('gf_ls', function (\PDO $pdo) use ($username, $email): int {
                $pdo->exec('LOCK TABLE accounts IN EXCLUSIVE MODE');
                $nextId = (int) $pdo
                    ->query('SELECT COALESCE(MAX(id), 0) + 1 FROM accounts')
                    ->fetchColumn();
                $stmt = $pdo->prepare(
                    'INSERT INTO accounts (id, username, password, realname, worldserver) '
                    . "VALUES (:id, :u, '', :u, 0)"
                );
                $stmt->execute([':id' => $nextId, ':u' => $username]);

                if ($email !== null) {
                    $web = $pdo->prepare(
                        'INSERT INTO web_account (account_id, email) VALUES (:id, :e)'
                    );
                    $web->execute([':id' => $nextId, ':e' => $email]);
                }

                return $nextId;
            });
        } catch (\Throwable $e) {
            // Compensating action: drop the orphaned tb_user row.
            try {
                $this->db->run('gf_ms', 'DELETE FROM tb_user WHERE mid = :m', [':m' => $username]);
            } catch (\Throwable) {
                // best effort — surface the original failure regardless
            }
            throw new DatabaseException('Failed to create account.', 0, $e);
        }
    }
```

- [ ] **Step 3: Add the four new methods to `web/lib/AccountService.php`**

Insert these methods immediately before the `private function hashPassword` doc comment:

```php
    /** Return the accounts.id for an email address, or null if unknown. */
    public function findByEmail(string $email): ?int
    {
        $id = $this->db->run(
            'gf_ls',
            'SELECT account_id FROM web_account WHERE email = :e',
            [':e' => $email],
        )->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** Mark an account's email verified and unlock it for game login. */
    public function confirmEmail(int $accountId): void
    {
        $this->db->run(
            'gf_ls',
            'UPDATE web_account SET email_verified = true WHERE account_id = :id',
            [':id' => $accountId],
        );

        $username = $this->db->run(
            'gf_ls',
            'SELECT username FROM accounts WHERE id = :id',
            [':id' => $accountId],
        )->fetchColumn();
        if ($username !== false) {
            $this->db->run(
                'gf_ms',
                'UPDATE tb_user SET byauthority = 0 WHERE mid = :m',
                [':m' => strtolower((string) $username)],
            );
        }
    }

    /** Set a new password for an account identified by id. */
    public function changePasswordForAccount(int $accountId, string $newPassword): void
    {
        $username = $this->db->run(
            'gf_ls',
            'SELECT username FROM accounts WHERE id = :id',
            [':id' => $accountId],
        )->fetchColumn();
        if ($username === false) {
            throw new ConflictException('Account not found.');
        }

        $this->changePassword((string) $username, $newPassword);
    }

    /**
     * Portal-side account metadata (email + verification flag), or null.
     *
     * @return array{email: ?string, email_verified: bool}|null
     */
    public function webAccount(int $accountId): ?array
    {
        $row = $this->db->run(
            'gf_ls',
            'SELECT email, email_verified FROM web_account WHERE account_id = :id',
            [':id' => $accountId],
        )->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'email' => $row['email'] !== null ? (string) $row['email'] : null,
            'email_verified' => in_array($row['email_verified'], [true, 't', '1', 1], true),
        ];
    }

```

- [ ] **Step 4: Verify** — confirm both files have LF endings; confirm `AccountService` has `register` (3-arg), `findByEmail`, `confirmEmail`, `changePasswordForAccount`, `webAccount`.

- [ ] **Step 5: Commit**

```bash
git add web/lib/AccountService.php web/tests/AccountServiceTest.php
git commit -m "feat(web): add AccountService email and confirmation methods"
```

---

## Task 5: FormController base and View::share()

**Files:** Modify `web/app/View.php`, `web/tests/ViewTest.php`; create `web/app/FormController.php`

- [ ] **Step 1: Add a `share` test to `web/tests/ViewTest.php`**

Insert this method immediately before the final closing `}` of the class:

```php

    public function testSharedDataIsAvailableToTemplates(): void
    {
        $view = new View($this->dir);
        $view->share(['name' => 'Shared']);
        $this->assertSame('[Hi Shared]', $view->render('page'));
    }
```

- [ ] **Step 2: Add `share` to `web/app/View.php`**

Replace this exact block:

```php
final class View
{
    public function __construct(private readonly string $templateDir)
    {
    }
```

with:

```php
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(private readonly string $templateDir)
    {
    }

    /**
     * Register data made available to every rendered template (e.g. the
     * logged-in user for the layout). Per-render data takes precedence.
     *
     * @param array<string, mixed> $data
     */
    public function share(array $data): void
    {
        $this->shared = $data + $this->shared;
    }
```

Then, in the `render` method, replace this exact line:

```php
        $content = $this->capture($template, $data);
```

with:

```php
        $data += $this->shared;
        $content = $this->capture($template, $data);
```

- [ ] **Step 3: Write `web/app/FormController.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App;

/**
 * Base for interactive (form-handling) controllers. Adds CSRF tokens, flash
 * messages and redirects on top of the plain Controller.
 */
abstract class FormController extends Controller
{
    public function __construct(View $view, protected readonly Session $session)
    {
        parent::__construct($view);
    }

    /** The per-session CSRF token, created on first use. */
    protected function csrfToken(): string
    {
        $token = $this->session->get('csrf');
        if (!is_string($token) || $token === '') {
            $token = Csrf::generate();
            $this->session->set('csrf', $token);
        }

        return $token;
    }

    /** True if the CSRF token submitted in $_POST matches the session token. */
    protected function csrfValid(): bool
    {
        $submitted = $_POST['csrf'] ?? null;

        return Csrf::check(
            is_string($this->session->get('csrf')) ? $this->session->get('csrf') : null,
            is_string($submitted) ? $submitted : null,
        );
    }

    /** Queue a flash message shown on the next rendered page. */
    protected function flash(string $message): void
    {
        $messages = $this->session->get('flash');
        $messages = is_array($messages) ? $messages : [];
        $messages[] = $message;
        $this->session->set('flash', $messages);
    }

    /**
     * Render a form page, auto-injecting the CSRF token and flash messages.
     *
     * @param array<string, mixed> $data
     */
    protected function page(string $template, array $data = [], int $status = 200): Response
    {
        $flashes = $this->session->get('flash');
        $this->session->remove('flash');

        $data += [
            'csrf' => $this->csrfToken(),
            'flashes' => is_array($flashes) ? $flashes : [],
        ];

        return $this->html($template, $data, $status);
    }

    protected function redirect(string $location): Response
    {
        return Response::redirect($location);
    }
}
```

- [ ] **Step 4: Verify** — confirm `View.php`, `FormController.php`, `ViewTest.php` have LF endings.

- [ ] **Step 5: Commit**

```bash
git add web/app/View.php web/app/FormController.php web/tests/ViewTest.php
git commit -m "feat(web): add FormController base and View shared data"
```

---

## Task 6: Registration

**Files:** Create `web/app/Controller/RegisterController.php`, `web/templates/register.php`, `web/templates/register_done.php`

- [ ] **Step 1: Write `web/app/Controller/RegisterController.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\AccountService;
use GfServer\App\FormController;
use GfServer\App\Mailer;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\RateLimiter;
use GfServer\TokenService;
use GfServer\ValidationException;
use GfServer\ConflictException;

/** Account registration with email confirmation. */
final class RegisterController extends FormController
{
    private const CONFIRM_TTL = 86400;

    public function __construct(
        View $view,
        Session $session,
        private readonly AccountService $accounts,
        private readonly TokenService $tokens,
        private readonly Mailer $mailer,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($view, $session);
    }

    public function showForm(): Response
    {
        return $this->page('register', ['title' => 'Register', 'error' => null]);
    }

    public function submit(): Response
    {
        if (!$this->csrfValid()) {
            return $this->page('register', ['title' => 'Register', 'error' => 'Invalid form token, please try again.'], 400);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($this->limiter->tooManyFailures($ip, 10, 3600)) {
            return $this->page('register', ['title' => 'Register', 'error' => 'Too many attempts. Please try again later.'], 429);
        }

        $username = (string) ($_POST['username'] ?? '');
        $email = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $this->limiter->record($ip, false);

        try {
            $id = $this->accounts->register($username, $password, $email);
        } catch (ValidationException | ConflictException $e) {
            return $this->page('register', ['title' => 'Register', 'error' => $e->getMessage()], 422);
        }

        $token = $this->tokens->create($id, 'email_verify', self::CONFIRM_TTL);
        $link = $this->portalUrl('/confirm?token=' . $token);
        $this->mailer->send(
            $email,
            'Confirm your Grand Fantasia account',
            "Welcome to Grand Fantasia!\n\n"
            . "Confirm your account by opening this link:\n{$link}\n\n"
            . "If you did not register, ignore this email.",
        );

        return $this->page('register_done', ['title' => 'Check your email']);
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

- [ ] **Step 2: Write `web/templates/register.php`**

```php
<section class="page">
    <h1>Create an account</h1>
    <?php foreach ($flashes as $flash): ?>
        <p class="flash"><?= e($flash) ?></p>
    <?php endforeach; ?>
    <?php if ($error !== null): ?>
        <p class="form-error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/register" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Username<br><input type="text" name="username" required></label>
        <label>Email<br><input type="email" name="email" required></label>
        <label>Password<br><input type="password" name="password" required></label>
        <button type="submit" class="button">Register</button>
    </form>
    <p>Already have an account? <a href="/login">Log in</a>.</p>
</section>
```

- [ ] **Step 3: Write `web/templates/register_done.php`**

```php
<section class="page">
    <h1>Almost there</h1>
    <p>Your account has been created. We have sent a confirmation link to your
       email address &mdash; open it to activate your account and start playing.</p>
    <p><a href="/login">Back to login</a></p>
</section>
```

- [ ] **Step 4: Verify** — confirm the three files have LF endings.

- [ ] **Step 5: Commit**

```bash
git add web/app/Controller/RegisterController.php web/templates/register.php web/templates/register_done.php
git commit -m "feat(web): add account registration"
```

---

## Task 7: Email confirmation

**Files:** Create `web/app/Controller/ConfirmController.php`, `web/templates/confirm.php`

- [ ] **Step 1: Write `web/app/Controller/ConfirmController.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\AccountService;
use GfServer\App\FormController;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\TokenService;

/** Handles the email-confirmation link. */
final class ConfirmController extends FormController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly TokenService $tokens,
        private readonly AccountService $accounts,
    ) {
        parent::__construct($view, $session);
    }

    public function confirm(): Response
    {
        $token = (string) ($_GET['token'] ?? '');
        $accountId = $token !== '' ? $this->tokens->consume($token, 'email_verify') : null;

        if ($accountId === null) {
            return $this->page('confirm', ['title' => 'Confirmation', 'ok' => false], 400);
        }

        $this->accounts->confirmEmail($accountId);

        return $this->page('confirm', ['title' => 'Confirmation', 'ok' => true]);
    }
}
```

- [ ] **Step 2: Write `web/templates/confirm.php`**

```php
<section class="page">
    <?php if ($ok): ?>
        <h1>Account confirmed</h1>
        <p>Your email is verified and your account is active. You can log in now.</p>
        <p><a class="button" href="/login">Log in</a></p>
    <?php else: ?>
        <h1>Confirmation failed</h1>
        <p>That confirmation link is invalid or has expired.</p>
        <p><a href="/login">Back to login</a></p>
    <?php endif; ?>
</section>
```

- [ ] **Step 3: Verify** — confirm both files have LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/app/Controller/ConfirmController.php web/templates/confirm.php
git commit -m "feat(web): add email confirmation"
```

---

## Task 8: Login and logout

**Files:** Create `web/app/Controller/LoginController.php`, `web/templates/login.php`

- [ ] **Step 1: Write `web/app/Controller/LoginController.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\AccountService;
use GfServer\App\Auth;
use GfServer\App\FormController;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\RateLimiter;

/** Portal login and logout. */
final class LoginController extends FormController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AccountService $accounts,
        private readonly Auth $auth,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($view, $session);
    }

    public function showForm(): Response
    {
        return $this->page('login', ['title' => 'Log in', 'error' => null]);
    }

    public function submit(): Response
    {
        if (!$this->csrfValid()) {
            return $this->page('login', ['title' => 'Log in', 'error' => 'Invalid form token, please try again.'], 400);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($this->limiter->tooManyFailures($ip, 5, 900)) {
            return $this->page('login', ['title' => 'Log in', 'error' => 'Too many failed attempts. Please try again later.'], 429);
        }

        $username = (string) ($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $accountId = $this->accounts->authenticate($username, $password);

        if ($accountId === null) {
            $this->limiter->record($ip, false);

            return $this->page('login', ['title' => 'Log in', 'error' => 'Wrong username or password.'], 401);
        }

        $this->limiter->record($ip, true);
        $this->auth->login($accountId, strtolower(trim($username)));
        $this->flash('Welcome back.');

        return $this->redirect('/account');
    }

    public function logout(): Response
    {
        if ($this->csrfValid()) {
            $this->auth->logout();
        }

        return $this->redirect('/');
    }
}
```

- [ ] **Step 2: Write `web/templates/login.php`**

```php
<section class="page">
    <h1>Log in</h1>
    <?php foreach ($flashes as $flash): ?>
        <p class="flash"><?= e($flash) ?></p>
    <?php endforeach; ?>
    <?php if ($error !== null): ?>
        <p class="form-error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/login" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Username<br><input type="text" name="username" required></label>
        <label>Password<br><input type="password" name="password" required></label>
        <button type="submit" class="button">Log in</button>
    </form>
    <p><a href="/forgot">Forgot your password?</a></p>
    <p>No account yet? <a href="/register">Register</a>.</p>
</section>
```

- [ ] **Step 3: Verify** — confirm both files have LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/app/Controller/LoginController.php web/templates/login.php
git commit -m "feat(web): add portal login and logout"
```

---

## Task 9: Account page and password change

**Files:** Create `web/app/Controller/AccountController.php`, `web/templates/account.php`, `web/templates/password.php`

- [ ] **Step 1: Write `web/app/Controller/AccountController.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\AccountService;
use GfServer\App\Auth;
use GfServer\App\FormController;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\ValidationException;

/** The logged-in account home and password change. */
final class AccountController extends FormController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly Auth $auth,
        private readonly AccountService $accounts,
    ) {
        parent::__construct($view, $session);
    }

    public function home(): Response
    {
        if (!$this->auth->isLoggedIn()) {
            return $this->redirect('/login');
        }

        $web = $this->accounts->webAccount((int) $this->auth->accountId());

        return $this->page('account', [
            'title' => 'My account',
            'username' => (string) $this->auth->username(),
            'email' => $web['email'] ?? null,
            'verified' => $web['email_verified'] ?? false,
        ]);
    }

    public function showPasswordForm(): Response
    {
        if (!$this->auth->isLoggedIn()) {
            return $this->redirect('/login');
        }

        return $this->page('password', ['title' => 'Change password', 'error' => null]);
    }

    public function changePassword(): Response
    {
        if (!$this->auth->isLoggedIn()) {
            return $this->redirect('/login');
        }
        if (!$this->csrfValid()) {
            return $this->page('password', ['title' => 'Change password', 'error' => 'Invalid form token, please try again.'], 400);
        }

        $username = (string) $this->auth->username();
        $current = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');

        if ($this->accounts->authenticate($username, $current) === null) {
            return $this->page('password', ['title' => 'Change password', 'error' => 'Your current password is wrong.'], 401);
        }

        try {
            $this->accounts->changePassword($username, $new);
        } catch (ValidationException $e) {
            return $this->page('password', ['title' => 'Change password', 'error' => $e->getMessage()], 422);
        }

        $this->flash('Your password has been changed.');

        return $this->redirect('/account');
    }
}
```

- [ ] **Step 2: Write `web/templates/account.php`**

```php
<section class="page">
    <h1>My account</h1>
    <?php foreach ($flashes as $flash): ?>
        <p class="flash"><?= e($flash) ?></p>
    <?php endforeach; ?>
    <dl class="account-details">
        <dt>Username</dt><dd><?= e($username) ?></dd>
        <dt>Email</dt><dd><?= e($email ?? '(none)') ?></dd>
        <dt>Email verified</dt><dd><?= $verified ? 'Yes' : 'No' ?></dd>
    </dl>
    <p><a class="button" href="/account/password">Change password</a></p>
    <form method="post" action="/logout" class="form-inline">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="button button-secondary">Log out</button>
    </form>
</section>
```

- [ ] **Step 3: Write `web/templates/password.php`**

```php
<section class="page">
    <h1>Change password</h1>
    <?php if ($error !== null): ?>
        <p class="form-error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/account/password" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Current password<br><input type="password" name="current" required></label>
        <label>New password<br><input type="password" name="new" required></label>
        <button type="submit" class="button">Change password</button>
    </form>
    <p><a href="/account">Back to my account</a></p>
</section>
```

- [ ] **Step 4: Verify** — confirm the three files have LF endings.

- [ ] **Step 5: Commit**

```bash
git add web/app/Controller/AccountController.php web/templates/account.php web/templates/password.php
git commit -m "feat(web): add account page and password change"
```

---

## Task 10: Password reset

**Files:** Create `web/app/Controller/PasswordResetController.php`, `web/templates/forgot.php`, `web/templates/forgot_done.php`, `web/templates/reset.php`, `web/templates/reset_done.php`

- [ ] **Step 1: Write `web/app/Controller/PasswordResetController.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\AccountService;
use GfServer\App\FormController;
use GfServer\App\Mailer;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\RateLimiter;
use GfServer\TokenService;
use GfServer\ValidationException;

/** Password reset: request a link, then set a new password. */
final class PasswordResetController extends FormController
{
    private const RESET_TTL = 3600;

    public function __construct(
        View $view,
        Session $session,
        private readonly AccountService $accounts,
        private readonly TokenService $tokens,
        private readonly Mailer $mailer,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($view, $session);
    }

    public function showRequestForm(): Response
    {
        return $this->page('forgot', ['title' => 'Reset password', 'error' => null]);
    }

    public function submitRequest(): Response
    {
        if (!$this->csrfValid()) {
            return $this->page('forgot', ['title' => 'Reset password', 'error' => 'Invalid form token, please try again.'], 400);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($this->limiter->tooManyFailures($ip, 10, 3600)) {
            return $this->page('forgot', ['title' => 'Reset password', 'error' => 'Too many attempts. Please try again later.'], 429);
        }
        $this->limiter->record($ip, false);

        $email = (string) ($_POST['email'] ?? '');
        $accountId = $this->accounts->findByEmail($email);
        if ($accountId !== null) {
            $token = $this->tokens->create($accountId, 'password_reset', self::RESET_TTL);
            $link = $this->portalUrl('/reset?token=' . $token);
            $this->mailer->send(
                $email,
                'Reset your Grand Fantasia password',
                "A password reset was requested for your account.\n\n"
                . "Open this link to set a new password:\n{$link}\n\n"
                . "This link expires in one hour. If you did not request this, ignore this email.",
            );
        }

        // Always the same response, so the form cannot reveal which emails exist.
        return $this->page('forgot_done', ['title' => 'Reset password']);
    }

    public function showResetForm(): Response
    {
        return $this->page('reset', [
            'title' => 'Set a new password',
            'token' => (string) ($_GET['token'] ?? ''),
            'error' => null,
        ]);
    }

    public function submitReset(): Response
    {
        $token = (string) ($_POST['token'] ?? '');

        if (!$this->csrfValid()) {
            return $this->page('reset', ['title' => 'Set a new password', 'token' => $token, 'error' => 'Invalid form token, please try again.'], 400);
        }

        $accountId = $token !== '' ? $this->tokens->consume($token, 'password_reset') : null;
        if ($accountId === null) {
            return $this->page('reset', ['title' => 'Set a new password', 'token' => '', 'error' => 'That reset link is invalid or has expired.'], 400);
        }

        try {
            $this->accounts->changePasswordForAccount($accountId, (string) ($_POST['new'] ?? ''));
        } catch (ValidationException $e) {
            return $this->page('reset', ['title' => 'Set a new password', 'token' => $token, 'error' => $e->getMessage()], 422);
        }

        return $this->page('reset_done', ['title' => 'Password changed']);
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

Note on the reset flow: `submitReset` consumes (and thereby spends) the token. If `changePasswordForAccount` then fails validation the token is already used; the error page intentionally keeps the entered `token` in the hidden field, but a retry will report the link as expired. This is acceptable — the user requests a fresh link. Do not change this behaviour.

- [ ] **Step 2: Write `web/templates/forgot.php`**

```php
<section class="page">
    <h1>Reset your password</h1>
    <?php if ($error !== null): ?>
        <p class="form-error"><?= e($error) ?></p>
    <?php endif; ?>
    <p>Enter your email address and we will send you a reset link.</p>
    <form method="post" action="/forgot" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Email<br><input type="email" name="email" required></label>
        <button type="submit" class="button">Send reset link</button>
    </form>
    <p><a href="/login">Back to login</a></p>
</section>
```

- [ ] **Step 3: Write `web/templates/forgot_done.php`**

```php
<section class="page">
    <h1>Check your email</h1>
    <p>If that email address has an account, a password-reset link has been
       sent to it. The link expires in one hour.</p>
    <p><a href="/login">Back to login</a></p>
</section>
```

- [ ] **Step 4: Write `web/templates/reset.php`**

```php
<section class="page">
    <h1>Set a new password</h1>
    <?php if ($error !== null): ?>
        <p class="form-error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if ($token === ''): ?>
        <p><a href="/forgot">Request a new reset link</a>.</p>
    <?php else: ?>
        <form method="post" action="/reset" class="form">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <label>New password<br><input type="password" name="new" required></label>
            <button type="submit" class="button">Set password</button>
        </form>
    <?php endif; ?>
</section>
```

- [ ] **Step 5: Write `web/templates/reset_done.php`**

```php
<section class="page">
    <h1>Password changed</h1>
    <p>Your password has been updated. You can log in with it now.</p>
    <p><a class="button" href="/login">Log in</a></p>
</section>
```

- [ ] **Step 6: Verify** — confirm the five files have LF endings.

- [ ] **Step 7: Commit**

```bash
git add web/app/Controller/PasswordResetController.php web/templates/forgot.php web/templates/forgot_done.php web/templates/reset.php web/templates/reset_done.php
git commit -m "feat(web): add password reset"
```

---

## Task 11: Wire routes, auth-aware nav, PORTAL_DOMAIN

**Files:** Modify `web/app/Auth.php`, `web/public/index.php`, `web/templates/layout.php`, `deploy/install.sh`

- [ ] **Step 1: Make the `Auth` session-key constants public**

In `web/app/Auth.php`, replace this exact block:

```php
    private const SESSION_ACCOUNT_ID = 'auth.account_id';
    private const SESSION_USERNAME = 'auth.username';
```

with:

```php
    public const SESSION_ACCOUNT_ID = 'auth.account_id';
    public const SESSION_USERNAME = 'auth.username';
```

- [ ] **Step 2: Replace the `use` / `require` block of `web/public/index.php`**

Replace this exact block:

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

with:

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

- [ ] **Step 3: Replace the router/factory block of `web/public/index.php`**

Replace this exact block:

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

with:

```php
// Make the logged-in user's name available to every template (the nav).
$navUsername = $session->get(Auth::SESSION_USERNAME);
$view->share([
    'navLoggedIn' => is_string($navUsername) && $navUsername !== '',
    'navUsername' => is_string($navUsername) ? $navUsername : '',
]);

$router = new Router();
$router->add('GET', '/', 'HomeController', 'index');
$router->add('GET', '/news', 'NewsController', 'index');
$router->add('GET', '/downloads', 'DownloadsController', 'index');
$router->add('GET', '/status', 'StatusController', 'index');
$router->add('GET', '/rankings', 'RankingController', 'index');
$router->add('GET', '/register', 'RegisterController', 'showForm');
$router->add('POST', '/register', 'RegisterController', 'submit');
$router->add('GET', '/confirm', 'ConfirmController', 'confirm');
$router->add('GET', '/login', 'LoginController', 'showForm');
$router->add('POST', '/login', 'LoginController', 'submit');
$router->add('POST', '/logout', 'LoginController', 'logout');
$router->add('GET', '/account', 'AccountController', 'home');
$router->add('GET', '/account/password', 'AccountController', 'showPasswordForm');
$router->add('POST', '/account/password', 'AccountController', 'changePassword');
$router->add('GET', '/forgot', 'PasswordResetController', 'showRequestForm');
$router->add('POST', '/forgot', 'PasswordResetController', 'submitRequest');
$router->add('GET', '/reset', 'PasswordResetController', 'showResetForm');
$router->add('POST', '/reset', 'PasswordResetController', 'submitReset');

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
$makeController = static function (string $name) use ($view, $session, $getDb): object {
    return match ($name) {
        'HomeController' => new HomeController($view),
        'NewsController' => new NewsController($view, new NewsRepository($getDb())),
        'DownloadsController' => new DownloadsController($view),
        'StatusController' => new StatusController($view, new ServerStatus($getDb())),
        'RankingController' => new RankingController($view, new RankingRepository($getDb())),
        'RegisterController' => new RegisterController(
            $view,
            $session,
            new AccountService($getDb()),
            new TokenService($getDb()),
            SmtpMailer::fromEnv(),
            new RateLimiter($getDb()),
        ),
        'ConfirmController' => new ConfirmController(
            $view,
            $session,
            new TokenService($getDb()),
            new AccountService($getDb()),
        ),
        'LoginController' => new LoginController(
            $view,
            $session,
            new AccountService($getDb()),
            new Auth($session, $getDb()),
            new RateLimiter($getDb()),
        ),
        'AccountController' => new AccountController(
            $view,
            $session,
            new Auth($session, $getDb()),
            new AccountService($getDb()),
        ),
        'PasswordResetController' => new PasswordResetController(
            $view,
            $session,
            new AccountService($getDb()),
            new TokenService($getDb()),
            SmtpMailer::fromEnv(),
            new RateLimiter($getDb()),
        ),
        default => throw new \RuntimeException("Unknown controller: {$name}"),
    };
};
```

- [ ] **Step 4: Replace the `<nav>` block of `web/templates/layout.php`**

Replace this exact block:

```php
        <nav>
            <a href="/">Home</a>
            <a href="/news">News</a>
            <a href="/downloads">Download</a>
            <a href="/status">Status</a>
            <a href="/rankings">Rankings</a>
        </nav>
```

with:

```php
        <nav>
            <a href="/">Home</a>
            <a href="/news">News</a>
            <a href="/downloads">Download</a>
            <a href="/status">Status</a>
            <a href="/rankings">Rankings</a>
            <?php if (!empty($navLoggedIn)): ?>
                <a href="/account"><?= e($navUsername ?? '') ?></a>
            <?php else: ?>
                <a href="/login">Log in</a>
                <a href="/register">Register</a>
            <?php endif; ?>
        </nav>
```

- [ ] **Step 5: Add `PORTAL_DOMAIN` to the PHP-FPM pool env in `deploy/install.sh`**

In `setup_web_server`, replace this exact line:

```
env[GF_DOWNLOAD_URL] = ${GF_DOWNLOAD_URL:-}
```

with:

```
env[GF_DOWNLOAD_URL] = ${GF_DOWNLOAD_URL:-}
env[PORTAL_DOMAIN] = ${PORTAL_DOMAIN:-localhost}
```

- [ ] **Step 6: Verify** — run `bash -n deploy/install.sh` (expect exit 0); confirm `index.php` still starts with `<?php` and the dispatch `try`/`catch` is unchanged; confirm all four files have LF endings.

- [ ] **Step 7: Commit**

```bash
git add web/app/Auth.php web/public/index.php web/templates/layout.php deploy/install.sh
git commit -m "feat(web): route account self-service and add auth-aware nav"
```

---

## Task 12: Self-review

- [ ] **Step 1: Review against the Plan 3 scope**

Confirm each deliverable exists:
- `Validation::email` (Task 1); `TokenService`, `RateLimiter` in `web/lib` (Tasks 2-3) with tests.
- `AccountService` has the 3-arg `register`, `findByEmail`, `confirmEmail`, `changePasswordForAccount`, `webAccount` (Task 4).
- `FormController` and `View::share` (Task 5).
- `RegisterController`, `ConfirmController`, `LoginController`, `AccountController`, `PasswordResetController` and their templates (Tasks 6-10).
- `index.php` registers all account routes; `layout.php` nav is auth-aware; `Auth` consts are public; the FPM pool exports `PORTAL_DOMAIN` (Task 11).

Confirm: every form template has the CSRF hidden field and every state-changing controller action calls `csrfValid()`. Every dynamic template value is `e()`-escaped. No SQL is built from request input (the only concatenations are the internal-integer TTL/window values in `TokenService`/`RateLimiter`). The password-reset request always renders the same `forgot_done` page regardless of whether the email exists.

Report findings. If a genuine gap is found, fix it; otherwise this task is just the review.

- [ ] **Step 2: Commit (only if Step 1 changed anything)**

```bash
git add -A
git commit -m "fix(web): address Plan 3 self-review findings"
```

If Step 1 found nothing to change, skip the commit and report the clean review.

---

## Verification Summary

- **Per task (Windows):** file creation, LF endings, `bash -n` for shell scripts.
- **CI (`web-backend` job):** runs migrations + PHPUnit over `web/tests/` — picks up `TokenServiceTest`, `RateLimiterTest` and the new `AccountServiceTest`/`ValidationTest`/`ViewTest` methods. `gf_web` already has the needed grants on `web_token`, `web_login_attempt`, `web_account`, `tb_user`, `accounts`.
- **Deferred to the deployment phase:** the form pages in a browser, real SMTP delivery, the confirmation/reset links end to end — manual acceptance.

## Open Items

1. Controllers are exercised by the service/repository tests beneath them plus deployment-phase manual checks; they are not unit-tested in isolation (HTTP-layer testing is out of scope here).
2. Resending a confirmation email is not in Plan 3 — it belongs to the admin UI (Plan 4).
3. Plan 4 (admin UI) is the final stage of Block ③a.
