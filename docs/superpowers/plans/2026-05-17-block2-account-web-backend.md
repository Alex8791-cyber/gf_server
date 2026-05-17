# Block ② Account-/Web-Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the SQL-injectable PHP files with a tested PHP domain library (PDO, prepared statements) and migrate account passwords to bcrypt via an editable `account_login()` stored procedure.

**Architecture:** A `web/lib/` PHP 8 library (`Config`, `Database`, `Validation`, `AccountService`, `AdminService`, typed exceptions) consumed later by the Block ③ portal. A numbered SQL migration system (`_utils/db/migrations/<db>/`) applied by `deploy/migrate.sh` rewrites `account_login()` for bcrypt and adds a least-privilege `gf_web` role. PHPUnit integration tests run against real PostgreSQL in CI.

**Tech Stack:** PHP 8.2+, PDO (pdo_pgsql), Composer, PHPUnit 11, PostgreSQL 16, bash, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-05-17-block2-account-web-backend-design.md`

---

## Environment note

The Windows dev machine has **no PHP, no Composer, no psql**. Implementers cannot run `php -l`, PHPUnit, or psql locally. Per-task verification is limited to: file created, LF line endings, content matches. **Full verification (PHPUnit against PostgreSQL, migrations) runs in CI** — the `web-backend` job added in Task 12. This mirrors Block ①. Files that run on Linux MUST use LF line endings (write in binary mode to avoid CRLF).

All work happens on branch `block2-account-web-backend` (already created). Each task ends with a commit; end every commit body with a blank line then `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

## File Structure

```
web/
  composer.json              # PSR-4 autoload GfServer\ -> lib/, dev: phpunit
  phpunit.xml                # PHPUnit config
  lib/
    Config.php               # DB connection settings from env
    Database.php             # PDO wrapper, 3 connections, query helpers
    Validation.php           # username / password / name rules
    ValidationException.php  # typed exception
    ConflictException.php    # typed exception
    DatabaseException.php    # typed exception
    AccountService.php       # register, changePassword
    AdminService.php         # setGmPrivilege, renamePlayer, renameSprite
  tests/
    bootstrap.php            # composer autoload
    DbTestCase.php           # base test case: gf_web Database + superuser cleanup
    DatabaseTest.php
    ValidationTest.php
    AccountServiceTest.php
    AdminServiceTest.php
_utils/db/migrations/
  gf_gs/001_gf_web_grants.sql
  gf_ls/001_gf_web_grants.sql
  gf_ms/001_password_hashing.sql
  gf_ms/002_gf_web_grants.sql
deploy/
  migrate.sh                 # NEW — numbered migration runner
  gfctl                      # MODIFIED — add `migrate` subcommand
  install.sh                 # MODIFIED — setup_web_role + run migrations
  gfserver.env.example       # MODIFIED — add WEB_DB_PASSWORD
.github/workflows/ci.yml     # MODIFIED — add web-backend job
.gitignore                   # MODIFIED — ignore web/vendor/
```

---

## Task 1: Project scaffolding

**Files:**
- Create: `web/composer.json`, `web/phpunit.xml`, `web/tests/bootstrap.php`
- Modify: `.gitignore`

- [ ] **Step 1: Create `web/composer.json`**

```json
{
    "name": "gfserver/web-backend",
    "description": "Account and admin backend for the gf_server private server.",
    "type": "project",
    "require": {
        "php": ">=8.2",
        "ext-pdo": "*",
        "ext-pdo_pgsql": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^11"
    },
    "autoload": {
        "psr-4": { "GfServer\\": "lib/" }
    },
    "autoload-dev": {
        "psr-4": { "GfServer\\Tests\\": "tests/" }
    },
    "config": {
        "sort-packages": true
    }
}
```

- [ ] **Step 2: Create `web/phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <testsuite name="gf-web-backend">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: Create `web/tests/bootstrap.php`**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
```

- [ ] **Step 4: Modify `.gitignore`** — append these lines to the existing file:

```gitignore

# PHP / Composer (web backend).
web/vendor/
web/composer.lock
```

- [ ] **Step 5: Verify**

Confirm the three new files exist and have LF line endings; confirm `.gitignore` ends with the appended block.

- [ ] **Step 6: Commit**

```bash
git add web/composer.json web/phpunit.xml web/tests/bootstrap.php .gitignore
git commit -m "chore(web): scaffold PHP web-backend project"
```

---

## Task 2: Password-hashing migration

**Files:**
- Create: `_utils/db/migrations/gf_ms/001_password_hashing.sql`

**Notes:** Migration files contain plain DDL with **no `BEGIN`/`COMMIT`** — the migration runner (Task 4) wraps each file in a single transaction. The `account_login` body below is the verbatim definition from `_utils/db/gf_ms.sql` with exactly two changes: `pPwd char(32)` → `pPwd text`, and `pPwd <> pPassword` → `pPwd <> crypt(pPassword, pPwd)`.

- [ ] **Step 1: Create `_utils/db/migrations/gf_ms/001_password_hashing.sql`**

```sql
-- Migration gf_ms/001: bcrypt password hashing.
-- Widens tb_user password columns to text, converts any existing plaintext
-- passwords to bcrypt, and rewrites account_login() to verify with bcrypt.

CREATE EXTENSION IF NOT EXISTS pgcrypto;

ALTER TABLE public.tb_user ALTER COLUMN pwd TYPE text;
ALTER TABLE public.tb_user ALTER COLUMN password TYPE text;

-- Convert existing plaintext passwords to bcrypt. Idempotent: rows that
-- already hold a bcrypt hash ($2a$/$2b$/$2y$) are skipped, so re-running
-- this migration (or applying it to already-hashed data) is safe.
UPDATE public.tb_user
   SET pwd = crypt(pwd, gen_salt('bf', 12))
 WHERE pwd IS NOT NULL
   AND pwd !~ '^\$2[aby]\$';

UPDATE public.tb_user
   SET password = pwd
 WHERE password IS DISTINCT FROM pwd;

-- account_login(): verbatim from gf_ms.sql, with pPwd typed text and the
-- password comparison switched to a bcrypt check.
CREATE OR REPLACE FUNCTION public.account_login(character varying, character varying, character varying) RETURNS public.res_set
    LANGUAGE plpgsql
    AS $_$declare
ppAccountID ALIAS FOR $1;
pPassword ALIAS FOR $2;
pClientIP ALIAS FOR $3;
pAccountID varchar(20);
pcount int;
pPwd text default null;
pBAuthority int2 default 0;
pGMIP varchar(15) default null;

res res_set;

BEGIN
pAccountID = lower(ppAccountID);
res.nRet=-1;

SELECT INTO pcount count(mid) FROM "tb_user" WHERE mid=pAccountID;
IF pcount =0 THEN --This Account is not exist
res.pIdNum= -1;
res.nRet = 2;
RETURN res;
END IF;

SELECT INTO pPwd,pBAuthority,res.pIdNum pwd,byAuthority,idnum FROM "tb_user" WHERE mid=pAccountID;

IF pPwd IS null THEN
res.nRet = 2;
RETURN res;
ELSEIF pPwd <> crypt(pPassword, pPwd) THEN
res.nRet = 3;
RETURN res;
END IF;

IF pBAuthority = 255 THEN   --This Account was locked
res.nRet = 5;
RETURN res;
END IF;

--IF pBAuthority = 1 THEN   --gmAccount  Check ip (0-->User, 1-->GM, 255-->Locked)
--SELECT INTO pGMIP ip FROM gmip WHERE ip=pClientIP;
--IF pGMIP IS NULL THEN
--res.nRet = 4;
--RETURN res;
--END IF;
--END IF;

res.nRet = 1;
RETURN res;
END;
$_$;
```

- [ ] **Step 2: Verify**

Confirm the file exists with LF endings and contains exactly one `CREATE OR REPLACE FUNCTION public.account_login` and the line `ELSEIF pPwd <> crypt(pPassword, pPwd) THEN`.

- [ ] **Step 3: Commit**

```bash
git add _utils/db/migrations/gf_ms/001_password_hashing.sql
git commit -m "feat(db): add bcrypt password-hashing migration"
```

---

## Task 3: gf_web grant migrations

**Files:**
- Create: `_utils/db/migrations/gf_ls/001_gf_web_grants.sql`
- Create: `_utils/db/migrations/gf_ms/002_gf_web_grants.sql`
- Create: `_utils/db/migrations/gf_gs/001_gf_web_grants.sql`

**Notes:** These grant the least-privilege `gf_web` role exactly the access the web backend needs. The role itself is created by `install.sh` (Task 5) / CI before migrations run.

- [ ] **Step 1: Create `_utils/db/migrations/gf_ls/001_gf_web_grants.sql`**

```sql
-- Migration gf_ls/001: least-privilege grants for the gf_web role.
GRANT CONNECT ON DATABASE gf_ls TO gf_web;
GRANT USAGE ON SCHEMA public TO gf_web;
GRANT SELECT, INSERT, UPDATE ON public.accounts TO gf_web;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.gm_tool_accounts TO gf_web;
```

- [ ] **Step 2: Create `_utils/db/migrations/gf_ms/002_gf_web_grants.sql`**

```sql
-- Migration gf_ms/002: least-privilege grants for the gf_web role.
GRANT CONNECT ON DATABASE gf_ms TO gf_web;
GRANT USAGE ON SCHEMA public TO gf_web;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.tb_user TO gf_web;
GRANT USAGE, SELECT ON SEQUENCE public.tb_user_idnum_seq TO gf_web;
GRANT EXECUTE ON FUNCTION public.account_login(character varying, character varying, character varying) TO gf_web;
```

- [ ] **Step 3: Create `_utils/db/migrations/gf_gs/001_gf_web_grants.sql`**

```sql
-- Migration gf_gs/001: least-privilege grants for the gf_web role.
GRANT CONNECT ON DATABASE gf_gs TO gf_web;
GRANT USAGE ON SCHEMA public TO gf_web;
GRANT SELECT, UPDATE ON public.player_characters TO gf_web;
GRANT SELECT, UPDATE ON public.elf1 TO gf_web;
```

- [ ] **Step 4: Verify**

Confirm all three files exist with LF endings.

- [ ] **Step 5: Commit**

```bash
git add _utils/db/migrations/gf_ls/001_gf_web_grants.sql _utils/db/migrations/gf_ms/002_gf_web_grants.sql _utils/db/migrations/gf_gs/001_gf_web_grants.sql
git commit -m "feat(db): add gf_web least-privilege grant migrations"
```

---

## Task 4: Migration runner and `gfctl migrate`

**Files:**
- Create: `deploy/migrate.sh`
- Modify: `deploy/gfctl`

- [ ] **Step 1: Create `deploy/migrate.sh`**

```bash
#!/usr/bin/env bash
# Apply numbered SQL migrations under _utils/db/migrations/<db>/.
# Each migration file is applied (with its INSERT into schema_migrations) in a
# single transaction; already-applied files are skipped.
#
# psql invocation is overridable for CI via GF_PSQL, e.g.
#   GF_PSQL="psql -h localhost -U postgres" GF_ROOT="$PWD" deploy/migrate.sh
# Default targets a local server install as the postgres OS user.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

PSQL="${GF_PSQL:-sudo -u postgres psql}"
migrations_dir="${GF_ROOT}/_utils/db/migrations"

[ -d "$migrations_dir" ] || die "Migrations directory not found: $migrations_dir"

run_sql() {
  local db="$1"; shift
  $PSQL -d "$db" -v ON_ERROR_STOP=1 -q "$@"
}

for db_dir in "$migrations_dir"/*/; do
  [ -d "$db_dir" ] || continue
  db="$(basename "$db_dir")"

  run_sql "$db" -c \
    'CREATE TABLE IF NOT EXISTS public.schema_migrations (filename text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now());'

  for file in "$db_dir"*.sql; do
    [ -e "$file" ] || continue
    name="$(basename "$file")"
    applied="$(run_sql "$db" -tAc \
      "SELECT 1 FROM public.schema_migrations WHERE filename = '${name}'")"
    if [ "$applied" = "1" ]; then
      log "skip ${db}/${name} (already applied)"
      continue
    fi
    log "apply ${db}/${name}"
    run_sql "$db" --single-transaction -f "$file" \
      -c "INSERT INTO public.schema_migrations (filename) VALUES ('${name}');"
  done
done

log "Migrations complete."
```

- [ ] **Step 2: Verify migrate.sh syntax**

Run: `bash -n deploy/migrate.sh`
Expected: no output, exit 0.

- [ ] **Step 3: Add a `migrate` subcommand to `deploy/gfctl`**

In `deploy/gfctl`, replace this exact line:

```
  backup)  require_root; systemctl start gf-backup.service; log "Backup finished — see ${GF_ROOT}/backup/." ;;
```

with:

```
  backup)  require_root; systemctl start gf-backup.service; log "Backup finished — see ${GF_ROOT}/backup/." ;;
  migrate) require_root; "${SCRIPT_DIR}/migrate.sh" ;;
```

Then, in the same file, replace this exact line:

```
Usage: gfctl {start|stop|restart|status|backup|restore <folder>}
```

with:

```
Usage: gfctl {start|stop|restart|status|backup|restore <folder>|migrate}
```

and replace this exact line:

```
  restore  Restore a backup folder (e.g. restore 2026-05-16_04-30-00)
```

with these two lines:

```
  restore  Restore a backup folder (e.g. restore 2026-05-16_04-30-00)
  migrate  Apply pending database migrations
```

- [ ] **Step 4: Verify gfctl**

Run: `bash -n deploy/gfctl`
Expected: no output, exit 0.
Run: `bash deploy/gfctl 2>&1 | head -1`
Expected: `Usage: gfctl {start|stop|restart|status|backup|restore <folder>|migrate}`

- [ ] **Step 5: Commit**

```bash
git add deploy/migrate.sh deploy/gfctl
git commit -m "feat(deploy): add SQL migration runner and gfctl migrate"
```

---

## Task 5: install.sh integration + env template

**Files:**
- Modify: `deploy/install.sh`
- Modify: `deploy/gfserver.env.example`

- [ ] **Step 1: Add `WEB_DB_PASSWORD` to `deploy/gfserver.env.example`**

Replace this exact block:

```
# Password for the non-superuser PostgreSQL role 'gf_app'.
# Leave empty to have install.sh generate a strong random password.
DB_PASSWORD=
```

with:

```
# Password for the non-superuser PostgreSQL role 'gf_app'.
# Leave empty to have install.sh generate a strong random password.
DB_PASSWORD=

# Password for the web-backend PostgreSQL role 'gf_web'.
# Leave empty to have install.sh generate a strong random password.
WEB_DB_PASSWORD=
```

- [ ] **Step 2: Add the `setup_web_role` and `run_migrations` functions to `deploy/install.sh`**

In `deploy/install.sh`, locate the line `# --- 6. Render component setup.ini files` and insert the following two functions on the lines immediately **before** it:

```bash
# --- 5b. Web-backend DB role -----------------------------------------------
setup_web_role() {
  if [ -z "${WEB_DB_PASSWORD:-}" ]; then
    WEB_DB_PASSWORD="$(openssl rand -base64 24 | tr -d '/+=' | head -c 28)"
    log "Generated a random gf_web password and stored it in gfserver.env."
    if grep -q '^WEB_DB_PASSWORD=' "${SCRIPT_DIR}/gfserver.env"; then
      sed -i "s|^WEB_DB_PASSWORD=.*|WEB_DB_PASSWORD=${WEB_DB_PASSWORD}|" "${SCRIPT_DIR}/gfserver.env"
    else
      printf 'WEB_DB_PASSWORD=%s\n' "$WEB_DB_PASSWORD" >> "${SCRIPT_DIR}/gfserver.env"
    fi
  fi
  log "Creating/updating the gf_web role..."
  sudo -u postgres psql -v ON_ERROR_STOP=1 -q <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gf_web') THEN
    CREATE ROLE gf_web LOGIN PASSWORD '${WEB_DB_PASSWORD}';
  ELSE
    ALTER ROLE gf_web LOGIN PASSWORD '${WEB_DB_PASSWORD}';
  END IF;
END
\$\$;
SQL
}

# --- 5c. Database migrations -----------------------------------------------
run_migrations() {
  log "Applying database migrations..."
  "${SCRIPT_DIR}/migrate.sh"
}

```

- [ ] **Step 3: Wire the new functions into `main()`**

In `deploy/install.sh`, replace this exact block:

```
  configure_postgres
  render_configs
```

with:

```
  configure_postgres
  setup_web_role
  run_migrations
  render_configs
```

- [ ] **Step 4: Validate WEB_DB_PASSWORD in `preflight`**

In `deploy/install.sh`, replace this exact block:

```
  if [ -n "${DB_PASSWORD:-}" ] && printf '%s' "$DB_PASSWORD" | grep -q '[^A-Za-z0-9._-]'; then
    die "DB_PASSWORD may only contain A-Z a-z 0-9 . _ - (edit gfserver.env)."
  fi
```

with:

```
  if [ -n "${DB_PASSWORD:-}" ] && printf '%s' "$DB_PASSWORD" | grep -q '[^A-Za-z0-9._-]'; then
    die "DB_PASSWORD may only contain A-Z a-z 0-9 . _ - (edit gfserver.env)."
  fi
  if [ -n "${WEB_DB_PASSWORD:-}" ] && printf '%s' "$WEB_DB_PASSWORD" | grep -q '[^A-Za-z0-9._-]'; then
    die "WEB_DB_PASSWORD may only contain A-Z a-z 0-9 . _ - (edit gfserver.env)."
  fi
```

- [ ] **Step 5: Verify**

Run: `bash -n deploy/install.sh`
Expected: no output, exit 0.

- [ ] **Step 6: Commit**

```bash
git add deploy/install.sh deploy/gfserver.env.example
git commit -m "feat(deploy): create gf_web role and run migrations on install"
```

---

## Task 6: Typed exceptions

**Files:**
- Create: `web/lib/ValidationException.php`, `web/lib/ConflictException.php`, `web/lib/DatabaseException.php`

- [ ] **Step 1: Create `web/lib/ValidationException.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer;

/** Thrown when user-supplied input fails a validation rule. */
final class ValidationException extends \RuntimeException
{
}
```

- [ ] **Step 2: Create `web/lib/ConflictException.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer;

/** Thrown when an operation conflicts with existing data (e.g. duplicate username). */
final class ConflictException extends \RuntimeException
{
}
```

- [ ] **Step 3: Create `web/lib/DatabaseException.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer;

/** Thrown when a database operation fails unexpectedly. */
final class DatabaseException extends \RuntimeException
{
}
```

- [ ] **Step 4: Verify**

Confirm the three files exist with LF endings.

- [ ] **Step 5: Commit**

```bash
git add web/lib/ValidationException.php web/lib/ConflictException.php web/lib/DatabaseException.php
git commit -m "feat(web): add typed domain exceptions"
```

---

## Task 7: `Config`

**Files:**
- Create: `web/lib/Config.php`

- [ ] **Step 1: Create `web/lib/Config.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer;

/**
 * Database connection settings, read from environment variables.
 *
 * The three game database NAMES (gf_gs, gf_ls, gf_ms) are fixed and not
 * configurable; only the host, port and the gf_web credentials are.
 */
final class Config
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $user,
        public readonly string $password,
    ) {
    }

    /** Build a Config from GF_DB_* environment variables. */
    public static function fromEnv(): self
    {
        $host = getenv('GF_DB_HOST');
        $port = getenv('GF_DB_PORT');
        $user = getenv('GF_DB_USER');
        $password = getenv('GF_DB_PASSWORD');

        if ($password === false || $password === '') {
            throw new \RuntimeException('GF_DB_PASSWORD is not set.');
        }

        return new self(
            $host !== false && $host !== '' ? $host : '127.0.0.1',
            $port !== false && $port !== '' ? (int) $port : 5432,
            $user !== false && $user !== '' ? $user : 'gf_web',
            $password,
        );
    }
}
```

- [ ] **Step 2: Verify**

Confirm `web/lib/Config.php` exists with LF endings.

- [ ] **Step 3: Commit**

```bash
git add web/lib/Config.php
git commit -m "feat(web): add Config (DB settings from env)"
```

---

## Task 8: `Database` + test base + DatabaseTest

**Files:**
- Create: `web/lib/Database.php`, `web/tests/DbTestCase.php`, `web/tests/DatabaseTest.php`

**Notes:** Tests run in CI (Task 12), not locally. Write the test first, then the implementation, per TDD ordering.

- [ ] **Step 1: Write `web/tests/DbTestCase.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\Config;
use GfServer\Database;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for integration tests that need the game databases.
 *
 * $this->db connects as the gf_web role (the role the code under test uses).
 * adminPdo() connects as a superuser for test setup/teardown that needs
 * privileges gf_web deliberately lacks (e.g. deleting accounts rows).
 */
abstract class DbTestCase extends TestCase
{
    protected Database $db;

    protected function setUp(): void
    {
        $this->db = new Database(Config::fromEnv());
    }

    /** Superuser PDO for a given game database, from GF_ADMIN_* env vars. */
    protected function adminPdo(string $database): \PDO
    {
        $host = getenv('GF_ADMIN_HOST') ?: '127.0.0.1';
        $port = getenv('GF_ADMIN_PORT') ?: '5432';
        $user = getenv('GF_ADMIN_USER') ?: 'postgres';
        $password = getenv('GF_ADMIN_PASSWORD') ?: '';
        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        return new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /** Remove an account created by a test, in both databases. */
    protected function purgeAccount(string $username): void
    {
        $this->adminPdo('gf_ms')
            ->prepare('DELETE FROM tb_user WHERE mid = :m')
            ->execute([':m' => $username]);
        $this->adminPdo('gf_ls')
            ->prepare('DELETE FROM accounts WHERE username = :u')
            ->execute([':u' => $username]);
    }
}
```

- [ ] **Step 2: Write `web/tests/DatabaseTest.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\Tests;

final class DatabaseTest extends DbTestCase
{
    public function testConnectsToAllThreeGameDatabases(): void
    {
        foreach (['gf_gs', 'gf_ls', 'gf_ms'] as $name) {
            $value = $this->db->run($name, 'SELECT 1 AS ok')->fetchColumn();
            $this->assertSame(1, (int) $value, "connection to {$name} works");
        }
    }

    public function testRunBindsParametersSafely(): void
    {
        $injection = "x'; DROP TABLE accounts; --";
        $row = $this->db->run('gf_ls', 'SELECT :v AS echoed', [':v' => $injection])->fetchColumn();
        $this->assertSame($injection, $row, 'parameters are bound, not interpolated');
    }

    public function testRejectsUnknownDatabase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->run('gf_unknown', 'SELECT 1');
    }
}
```

- [ ] **Step 3: Write `web/lib/Database.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer;

/**
 * PDO wrapper holding lazy connections to the three game databases.
 * All access goes through parameterised queries; no SQL is built by string
 * concatenation of user input.
 */
final class Database
{
    private const DATABASES = ['gf_gs', 'gf_ls', 'gf_ms'];

    /** @var array<string, \PDO> */
    private array $connections = [];

    public function __construct(private readonly Config $config)
    {
    }

    /** Return the (lazily opened) PDO connection for one game database. */
    public function pdo(string $database): \PDO
    {
        if (!in_array($database, self::DATABASES, true)) {
            throw new \InvalidArgumentException("Unknown database: {$database}");
        }
        if (!isset($this->connections[$database])) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $this->config->host,
                $this->config->port,
                $database,
            );
            $this->connections[$database] = new \PDO(
                $dsn,
                $this->config->user,
                $this->config->password,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ],
            );
        }

        return $this->connections[$database];
    }

    /**
     * Run a parameterised statement against one database and return it.
     *
     * @param array<string, mixed> $params
     */
    public function run(string $database, string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo($database)->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * Run $fn inside a transaction on one database. The PDO is passed to $fn.
     * Commits on success, rolls back and re-throws on any exception.
     *
     * @template T
     * @param callable(\PDO): T $fn
     * @return T
     */
    public function transaction(string $database, callable $fn): mixed
    {
        $pdo = $this->pdo($database);
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
```

- [ ] **Step 4: Verify**

Confirm the three files exist with LF endings. (PHPUnit runs in CI — Task 12.)

- [ ] **Step 5: Commit**

```bash
git add web/lib/Database.php web/tests/DbTestCase.php web/tests/DatabaseTest.php
git commit -m "feat(web): add Database PDO wrapper with integration tests"
```

---

## Task 9: `Validation` + ValidationTest

**Files:**
- Create: `web/lib/Validation.php`, `web/tests/ValidationTest.php`

- [ ] **Step 1: Write `web/tests/ValidationTest.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\Validation;
use GfServer\ValidationException;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    public function testAcceptsAValidUsername(): void
    {
        Validation::username('player_01');
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsTooShortUsername(): void
    {
        $this->expectException(ValidationException::class);
        Validation::username('abc');
    }

    public function testRejectsUsernameWithIllegalCharacters(): void
    {
        $this->expectException(ValidationException::class);
        Validation::username('bad name!');
    }

    public function testRejectsTooShortPassword(): void
    {
        $this->expectException(ValidationException::class);
        Validation::password('short');
    }

    public function testRejectsPasswordOverBcryptLimit(): void
    {
        $this->expectException(ValidationException::class);
        Validation::password(str_repeat('a', 73));
    }

    public function testRejectsCharacterNameWithWhitespace(): void
    {
        $this->expectException(ValidationException::class);
        Validation::characterName('Sir Lancelot');
    }
}
```

- [ ] **Step 2: Write `web/lib/Validation.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer;

/**
 * Centralised input rules. Each method returns void on success and throws
 * ValidationException on failure.
 */
final class Validation
{
    /** Username: 4-20 chars, ASCII letters/digits/underscore only. */
    public static function username(string $username): void
    {
        if (preg_match('/^[A-Za-z0-9_]{4,20}$/', $username) !== 1) {
            throw new ValidationException(
                'Username must be 4-20 characters: letters, digits, underscore.'
            );
        }
    }

    /** Password: 8-72 characters (72 is the bcrypt input limit). */
    public static function password(string $password): void
    {
        $length = strlen($password);
        if ($length < 8 || $length > 72) {
            throw new ValidationException(
                'Password must be between 8 and 72 characters.'
            );
        }
    }

    /** Player or sprite name: 4-16 chars, no whitespace. */
    public static function characterName(string $name): void
    {
        if (preg_match('/\s/', $name) === 1) {
            throw new ValidationException('Name must not contain whitespace.');
        }
        $length = mb_strlen($name);
        if ($length < 4 || $length > 16) {
            throw new ValidationException('Name must be 4-16 characters.');
        }
    }
}
```

- [ ] **Step 3: Verify**

Confirm both files exist with LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/lib/Validation.php web/tests/ValidationTest.php
git commit -m "feat(web): add Validation rules with tests"
```

---

## Task 10: `AccountService` + AccountServiceTest

**Files:**
- Create: `web/lib/AccountService.php`, `web/tests/AccountServiceTest.php`

- [ ] **Step 1: Write `web/tests/AccountServiceTest.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\AccountService;
use GfServer\ConflictException;
use GfServer\ValidationException;

final class AccountServiceTest extends DbTestCase
{
    private AccountService $service;

    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AccountService($this->db);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $username) {
            $this->purgeAccount($username);
        }
        $this->created = [];
    }

    private function uniqueName(): string
    {
        $name = 't' . substr(bin2hex(random_bytes(8)), 0, 12);
        $this->created[] = $name;

        return $name;
    }

    public function testRegisterCreatesRowsInBothDatabases(): void
    {
        $username = $this->uniqueName();
        $id = $this->service->register($username, 'secret-password');

        $this->assertGreaterThan(0, $id);

        $accountRow = $this->db->run(
            'gf_ls',
            'SELECT username FROM accounts WHERE id = :id',
            [':id' => $id],
        )->fetch();
        $this->assertSame($username, $accountRow['username']);

        $userRow = $this->db->run(
            'gf_ms',
            'SELECT pwd FROM tb_user WHERE mid = :m',
            [':m' => $username],
        )->fetch();
        $this->assertNotFalse($userRow, 'tb_user row exists');
        $this->assertStringStartsWith('$2y$', $userRow['pwd'], 'password stored as bcrypt');
        $this->assertNotSame('secret-password', $userRow['pwd'], 'password not plaintext');
    }

    public function testRegisterRejectsDuplicateUsername(): void
    {
        $username = $this->uniqueName();
        $this->service->register($username, 'secret-password');

        $this->expectException(ConflictException::class);
        $this->service->register($username, 'another-password');
    }

    public function testRegisterRejectsInvalidInput(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->register('no', 'secret-password');
    }

    public function testRegisteredAccountPassesAccountLogin(): void
    {
        $username = $this->uniqueName();
        $this->service->register($username, 'round-trip-pw');

        $accept = $this->db->run(
            'gf_ms',
            "SELECT (account_login(:u, :p, '127.0.0.1')).nRet",
            [':u' => $username, ':p' => 'round-trip-pw'],
        )->fetchColumn();
        $this->assertSame(1, (int) $accept, 'correct password is accepted (nRet=1)');

        $reject = $this->db->run(
            'gf_ms',
            "SELECT (account_login(:u, :p, '127.0.0.1')).nRet",
            [':u' => $username, ':p' => 'wrong-password'],
        )->fetchColumn();
        $this->assertSame(3, (int) $reject, 'wrong password is rejected (nRet=3)');
    }

    public function testChangePasswordUpdatesHashAndStillLogsIn(): void
    {
        $username = $this->uniqueName();
        $this->service->register($username, 'first-password');
        $this->service->changePassword($username, 'second-password');

        $accept = $this->db->run(
            'gf_ms',
            "SELECT (account_login(:u, :p, '127.0.0.1')).nRet",
            [':u' => $username, ':p' => 'second-password'],
        )->fetchColumn();
        $this->assertSame(1, (int) $accept, 'new password works');

        $reject = $this->db->run(
            'gf_ms',
            "SELECT (account_login(:u, :p, '127.0.0.1')).nRet",
            [':u' => $username, ':p' => 'first-password'],
        )->fetchColumn();
        $this->assertSame(3, (int) $reject, 'old password no longer works');
    }

    public function testChangePasswordOnMissingAccountThrows(): void
    {
        $this->expectException(ConflictException::class);
        $this->service->changePassword('tnosuchaccount', 'whatever-password');
    }
}
```

- [ ] **Step 2: Write `web/lib/AccountService.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer;

/**
 * Account registration and password management.
 *
 * Usernames are stored lowercase: account_login() looks them up via
 * lower(input), so a non-lowercase mid would never authenticate.
 *
 * Registration spans two databases (gf_ms.tb_user and gf_ls.accounts) and
 * therefore cannot be a single transaction. tb_user is written first (its
 * primary key on `mid` enforces username uniqueness); if the accounts insert
 * then fails, the tb_user row is removed so no orphan remains.
 */
final class AccountService
{
    private const BCRYPT_COST = 12;

    public function __construct(private readonly Database $db)
    {
    }

    /** Register a new account. Returns the new accounts.id. */
    public function register(string $username, string $password): int
    {
        $username = strtolower(trim($username));
        Validation::username($username);
        Validation::password($password);

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);

        $taken = $this->db->run(
            'gf_ms',
            'SELECT 1 FROM tb_user WHERE mid = :m',
            [':m' => $username],
        )->fetchColumn();
        if ($taken !== false) {
            throw new ConflictException("Username '{$username}' is already taken.");
        }

        // 1) gf_ms.tb_user — the PK on mid makes this the uniqueness gate.
        try {
            $this->db->run(
                'gf_ms',
                'INSERT INTO tb_user (mid, password, pwd, pvalues) '
                . 'VALUES (:m, :pw, :pw, 99999)',
                [':m' => $username, ':pw' => $hash],
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new ConflictException("Username '{$username}' is already taken.");
            }
            throw new DatabaseException('Failed to create account.', 0, $e);
        }

        // 2) gf_ls.accounts — allocate id under an exclusive lock to avoid the
        //    COUNT()-based race the legacy PHP had. Compensate on failure.
        try {
            return $this->db->transaction('gf_ls', function (\PDO $pdo) use ($username): int {
                $pdo->exec('LOCK TABLE accounts IN EXCLUSIVE MODE');
                $nextId = (int) $pdo
                    ->query('SELECT COALESCE(MAX(id), 0) + 1 FROM accounts')
                    ->fetchColumn();
                $stmt = $pdo->prepare(
                    'INSERT INTO accounts (id, username, password, realname, worldserver) '
                    . "VALUES (:id, :u, '', :u, 0)"
                );
                $stmt->execute([':id' => $nextId, ':u' => $username]);

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

    /** Set a new password for an existing account. */
    public function changePassword(string $username, string $newPassword): void
    {
        $username = strtolower(trim($username));
        Validation::password($newPassword);

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);

        $stmt = $this->db->run(
            'gf_ms',
            'UPDATE tb_user SET pwd = :pw, password = :pw WHERE mid = :m',
            [':pw' => $hash, ':m' => $username],
        );
        if ($stmt->rowCount() === 0) {
            throw new ConflictException("Account '{$username}' not found.");
        }
    }
}
```

- [ ] **Step 3: Verify**

Confirm both files exist with LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/lib/AccountService.php web/tests/AccountServiceTest.php
git commit -m "feat(web): add AccountService (register, changePassword)"
```

---

## Task 11: `AdminService` + AdminServiceTest

**Files:**
- Create: `web/lib/AdminService.php`, `web/tests/AdminServiceTest.php`

**Scope note:** `setGmPrivilege` updates the two authoritative in-game GM flags — `gf_gs.player_characters.privilege` and `gf_ms.tb_user.byauthority`. It does **not** touch `gf_ls.gm_tool_accounts`: that table is the login store for the separate external "GM Tool" program, and the legacy code populated its password by copying the account's plaintext password — which no longer exists once passwords are bcrypt-hashed. Provisioning a GM-Tool login needs its own small design and belongs to the Block ③ admin panel. This is recorded in the spec's open items.

- [ ] **Step 1: Write `web/tests/AdminServiceTest.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\AdminService;
use GfServer\ConflictException;

final class AdminServiceTest extends DbTestCase
{
    private AdminService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AdminService($this->db);
    }

    public function testSetGmPrivilegeOnMissingPlayerThrows(): void
    {
        $this->expectException(ConflictException::class);
        $this->service->setGmPrivilege('tno_such_player_x', true);
    }

    public function testRenamePlayerOnMissingPlayerThrows(): void
    {
        $this->expectException(ConflictException::class);
        $this->service->renamePlayer('tno_such_player_x', 'NewName');
    }

    public function testRenameSpriteOnMissingPlayerThrows(): void
    {
        $this->expectException(ConflictException::class);
        $this->service->renameSprite('tno_such_player_x', 'NewSprite');
    }

    public function testSetGmPrivilegeUpdatesAnExistingCharacter(): void
    {
        $admin = $this->adminPdo('gf_gs');
        $admin->exec(
            "INSERT INTO player_characters (id, account_id, given_name, privilege) "
            . "VALUES (2000001, 2000001, 'TestGmChar', 0)"
        );
        $msAdmin = $this->adminPdo('gf_ms');
        $msAdmin->exec(
            "INSERT INTO tb_user (mid, password, pwd, pvalues) "
            . "VALUES ('tgmchar', '', '', 0)"
        );

        try {
            $this->service->setGmPrivilege('TestGmChar', true);
            $privilege = $admin
                ->query("SELECT privilege FROM player_characters WHERE given_name = 'TestGmChar'")
                ->fetchColumn();
            $this->assertSame(5, (int) $privilege);
        } finally {
            $admin->exec("DELETE FROM player_characters WHERE id = 2000001");
            $msAdmin->exec("DELETE FROM tb_user WHERE mid = 'tgmchar'");
        }
    }
}
```

- [ ] **Step 2: Write `web/lib/AdminService.php`**

```php
<?php

declare(strict_types=1);

namespace GfServer;

/**
 * Administrative operations on existing characters: granting GM status and
 * renaming. All queries are parameterised.
 */
final class AdminService
{
    private const GM_PRIVILEGE = 5;
    private const PLAYER_PRIVILEGE = 0;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Grant or revoke in-game GM status for the character named $playerName.
     * Updates player_characters.privilege (gf_gs) and tb_user.byauthority
     * (gf_ms) for the owning account.
     */
    public function setGmPrivilege(string $playerName, bool $grant): void
    {
        $privilege = $grant ? self::GM_PRIVILEGE : self::PLAYER_PRIVILEGE;

        $character = $this->db->run(
            'gf_gs',
            'SELECT account_id FROM player_characters WHERE given_name = :n',
            [':n' => $playerName],
        )->fetch();
        if ($character === false) {
            throw new ConflictException("Player '{$playerName}' not found.");
        }

        $this->db->run(
            'gf_gs',
            'UPDATE player_characters SET privilege = :p WHERE given_name = :n',
            [':p' => $privilege, ':n' => $playerName],
        );

        // tb_user is keyed by account name; player_characters has the numeric
        // account_id. The account username equals the lowercase login name,
        // looked up via accounts.id in gf_ls.
        $account = $this->db->run(
            'gf_ls',
            'SELECT username FROM accounts WHERE id = :id',
            [':id' => (int) $character['account_id']],
        )->fetch();
        if ($account !== false) {
            $this->db->run(
                'gf_ms',
                'UPDATE tb_user SET byauthority = :p WHERE mid = :m',
                [':p' => $privilege, ':m' => strtolower((string) $account['username'])],
            );
        }
    }

    /** Rename a player character. */
    public function renamePlayer(string $oldName, string $newName): void
    {
        Validation::characterName($newName);

        $exists = $this->db->run(
            'gf_gs',
            'SELECT 1 FROM player_characters WHERE given_name = :n',
            [':n' => $oldName],
        )->fetchColumn();
        if ($exists === false) {
            throw new ConflictException("Player '{$oldName}' not found.");
        }

        $taken = $this->db->run(
            'gf_gs',
            'SELECT 1 FROM player_characters WHERE given_name = :n',
            [':n' => $newName],
        )->fetchColumn();
        if ($taken !== false) {
            throw new ConflictException("Name '{$newName}' is already in use.");
        }

        $this->db->run(
            'gf_gs',
            'UPDATE player_characters SET given_name = :new WHERE given_name = :old',
            [':new' => $newName, ':old' => $oldName],
        );
    }

    /** Rename the sprite (elf) belonging to the character named $playerName. */
    public function renameSprite(string $playerName, string $spriteName): void
    {
        Validation::characterName($spriteName);

        $character = $this->db->run(
            'gf_gs',
            'SELECT id FROM player_characters WHERE given_name = :n',
            [':n' => $playerName],
        )->fetch();
        if ($character === false) {
            throw new ConflictException("Player '{$playerName}' not found.");
        }

        $this->db->run(
            'gf_gs',
            'UPDATE elf1 SET name = :name WHERE player_id = :pid',
            [':name' => $spriteName, ':pid' => (int) $character['id']],
        );
    }
}
```

- [ ] **Step 3: Verify**

Confirm both files exist with LF endings.

- [ ] **Step 4: Commit**

```bash
git add web/lib/AdminService.php web/tests/AdminServiceTest.php
git commit -m "feat(web): add AdminService (GM privilege, renaming)"
```

---

## Task 12: CI `web-backend` job

**Files:**
- Modify: `.github/workflows/ci.yml`

- [ ] **Step 1: Append the `web-backend` job to `.github/workflows/ci.yml`**

Add the following at the end of the file (it becomes a second job under the existing `jobs:` key — same indentation level as `deploy-checks:`):

```yaml

  web-backend:
    name: Web backend tests
    runs-on: ubuntu-24.04
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_PASSWORD: postgres
        ports:
          - 5432:5432
        options: >-
          --health-cmd "pg_isready -U postgres"
          --health-interval 5s
          --health-timeout 5s
          --health-retries 10
    env:
      PGHOST: localhost
      PGUSER: postgres
      PGPASSWORD: postgres
    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Install psql client
        run: sudo apt-get update && sudo apt-get install -y postgresql-client

      - name: Create databases
        run: |
          for db in gf_gs gf_ls gf_ms; do
            psql -h localhost -U postgres -c "CREATE DATABASE ${db} ENCODING 'UTF8' TEMPLATE template0;"
          done

      - name: Load game schema
        run: |
          for db in gf_gs gf_ls gf_ms; do
            psql -h localhost -U postgres -v ON_ERROR_STOP=1 -q -d "${db}" -f "_utils/db/${db}.sql"
          done

      - name: Create gf_web role
        run: psql -h localhost -U postgres -c "CREATE ROLE gf_web LOGIN PASSWORD 'testpw';"

      - name: Apply migrations
        run: GF_PSQL="psql -h localhost -U postgres" GF_ROOT="$PWD" bash deploy/migrate.sh

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo, pdo_pgsql
          tools: composer

      - name: Install Composer dependencies
        working-directory: web
        run: composer install --no-interaction --no-progress

      - name: Run PHPUnit
        working-directory: web
        env:
          GF_DB_HOST: localhost
          GF_DB_PORT: '5432'
          GF_DB_USER: gf_web
          GF_DB_PASSWORD: testpw
          GF_ADMIN_HOST: localhost
          GF_ADMIN_PORT: '5432'
          GF_ADMIN_USER: postgres
          GF_ADMIN_PASSWORD: postgres
        run: vendor/bin/phpunit
```

- [ ] **Step 2: Verify**

Confirm `.github/workflows/ci.yml` now contains both `deploy-checks:` and `web-backend:` jobs, and that the YAML indentation of `web-backend:` matches `deploy-checks:`.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: add web-backend job (PostgreSQL + PHPUnit)"
```

---

## Task 13: Remove legacy PHP, self-review

**Files:**
- Delete: `_utils/web/change.php`, `_utils/web/config.php`, `_utils/web/gm.php`, `_utils/web/index.php`, `_utils/web/sprite.php`, `_utils/web/status.php`

- [ ] **Step 1: Remove the legacy SQL-injectable PHP files**

The new `web/lib/` library replaces their logic; their UI is rebuilt in Block ③. They must not linger as a security liability.

```bash
git rm _utils/web/change.php _utils/web/config.php _utils/web/gm.php _utils/web/index.php _utils/web/sprite.php _utils/web/status.php
```

- [ ] **Step 2: Verify removal**

Run: `git status --short`
Expected: six `D _utils/web/*.php` entries. Confirm `_utils/web/` no longer contains `.php` files.

- [ ] **Step 3: Self-review against the spec**

Confirm each spec section maps to a deliverable:
- bcrypt hashing / `account_login` rewrite → Task 2. Column widening → Task 2.
- `gf_web` role → Task 5 (`setup_web_role`); grants → Task 3.
- Migration runner / `gfctl migrate` / install integration → Tasks 4, 5.
- `Config`, `Database`, `Validation`, exceptions, `AccountService`, `AdminService` → Tasks 6-11.
- Cross-DB registration with compensation; id allocation under lock → Task 10.
- PHPUnit tests incl. auth roundtrip → Tasks 8-11; CI → Task 12.
- Legacy PHP removed → Task 13.

Confirm no file under `web/lib/` builds SQL by concatenating input, and no remaining `.php` file in `_utils/`.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "refactor(web): remove legacy SQL-injectable PHP files"
```

---

## Verification Summary

- **Per task (Windows dev machine):** file creation, LF line endings, `bash -n` for shell scripts. No PHP/psql locally.
- **CI (`web-backend` job):** loads the game schema into PostgreSQL 16, applies all migrations, runs PHPUnit as the `gf_web` role — including the `account_login` bcrypt auth roundtrip. This is the real gate for Block ②.
- **Deferred to the deployment phase:** confirming the actual game client still logs in after the bcrypt switch (runbook acceptance gate; deployment testing is deferred until all blocks are done).

## Open Items Carried From the Spec

1. **Client login after bcrypt:** verified end-to-end only in the deferred deployment-test phase. CI fully covers the server side (`AccountService` ↔ `account_login`).
2. **`gm_tool_accounts` / external GM Tool:** `setGmPrivilege` deliberately does not populate `gm_tool_accounts` — the legacy code copied the account's plaintext password there, which no longer exists. Provisioning a GM-Tool login needs its own design in the Block ③ admin panel.
3. **`charpassword` (secondary character password):** out of scope for Block ②.
