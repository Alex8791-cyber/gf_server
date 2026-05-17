# Block ③b Forum Integration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Integrate phpBB 3.3 as the community forum on a `forum.<domain>` subdomain, with single sign-on against the game accounts via a custom phpBB auth-provider extension.

**Architecture:** Our code is a phpBB extension (`forum/ext/gfserver/sso/`) providing an `auth.provider` service whose `login()` reuses the portal's tested `GfServer\AccountService::authenticate()` and auto-creates the phpBB user on first login. `install.sh` gains a `setup_forum` step that downloads phpBB, creates the `gf_forum` database, runs the phpBB CLI installer, deploys + enables the extension, and writes the forum virtual host.

**Tech Stack:** phpBB 3.3 (PHP), PostgreSQL 16, Apache 2 + PHP-FPM, bash.

**Spec:** `docs/superpowers/specs/2026-05-17-block3b-forum-integration-design.md`.

---

## Environment note

No PHP/Composer/psql on the Windows dev machine. Per-task verification = file created, LF endings (no CR bytes), `bash -n` for shell, `php -l` is NOT available locally. The phpBB framework is NOT committed — only our extension under `forum/ext/`. **Block ③b is deployment-heavy:** the phpBB install, the SSO login flow and the forum vhost/HTTPS are verified on a real VPS in the deferred deployment phase, not in CI (per the spec).

All work happens on branch `block3b-forum-integration` (already created). Each task ends with a commit; end every commit body with a blank line then `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

## File Structure

```
forum/ext/gfserver/sso/        # NEW — our phpBB extension (committed)
  composer.json
  ext.php
  config/services.yml
  auth/provider/gfserver.php
deploy/
  install.sh                   # MODIFIED — setup_forum step
  gfserver.env.example         # MODIFIED — forum variables
web/public/index.php           # MODIFIED — share forumUrl
web/templates/layout.php       # MODIFIED — "Forum" nav link
docs/deployment-runbook.md     # MODIFIED — forum section
.github/workflows/ci.yml       # MODIFIED — php -l the extension
```

---

## Task 1: SSO extension scaffolding

**Files:** Create `forum/ext/gfserver/sso/composer.json`, `forum/ext/gfserver/sso/ext.php`, `forum/ext/gfserver/sso/config/services.yml`

- [ ] **Step 1: Create `forum/ext/gfserver/sso/composer.json`**

```json
{
    "name": "gfserver/sso",
    "type": "phpbb-extension",
    "description": "Single sign-on for phpBB against the gf_server game accounts.",
    "license": "GPL-2.0-only",
    "require": {
        "php": ">=7.4"
    },
    "extra": {
        "display-name": "gf_server SSO",
        "soft-require": {
            "phpbb/phpbb": ">=3.3.0,<4.0.0@dev"
        }
    }
}
```

- [ ] **Step 2: Create `forum/ext/gfserver/sso/ext.php`**

```php
<?php

declare(strict_types=1);

namespace gfserver\sso;

/** phpBB extension lifecycle class for the gf_server SSO provider. */
class ext extends \phpbb\extension\base
{
}
```

- [ ] **Step 3: Create `forum/ext/gfserver/sso/config/services.yml`**

```yaml
services:
    auth.provider.gfserver:
        class: gfserver\sso\auth\provider\gfserver
        arguments:
            - '@config'
            - '@dbal.conn'
            - '@user'
            - '%core.root_path%'
            - '%core.php_ext%'
        tags:
            - { name: auth.provider }
```

- [ ] **Step 4: Verify** — confirm the three files exist with LF endings; confirm `composer.json` is valid JSON (`python -c "import json; json.load(open('forum/ext/gfserver/sso/composer.json'))"`).

- [ ] **Step 5: Commit**

```bash
git add forum/ext/gfserver/sso/composer.json forum/ext/gfserver/sso/ext.php forum/ext/gfserver/sso/config/services.yml
git commit -m "feat(forum): scaffold the phpBB SSO extension"
```

---

## Task 2: SSO auth provider

**Files:** Create `forum/ext/gfserver/sso/auth/provider/gfserver.php`

**Background:** This class is modelled on phpBB 3.3's bundled `phpbb/auth/provider/apache.php` (an external-identity provider that auto-creates the phpBB user). It extends `\phpbb\auth\provider\base`, so only `init()` and `login()` need bodies. `login()` returns `['status' => …, 'error_msg' => …, 'user_row' => …]`. Instead of trusting a web-server header, it validates the submitted credentials through the portal's `GfServer\AccountService` (loaded from the portal's Composer autoloader on the same server).

- [ ] **Step 1: Create `forum/ext/gfserver/sso/auth/provider/gfserver.php`**

```php
<?php

declare(strict_types=1);

namespace gfserver\sso\auth\provider;

use phpbb\auth\provider\base;
use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\user;

/**
 * phpBB auth provider: authenticates against the gf_server game accounts and
 * auto-provisions the phpBB user on first login. The credential check is
 * delegated to the portal's already-tested GfServer\AccountService, loaded
 * from the portal install on the same server.
 */
class gfserver extends base
{
    /** Portal install root — fixed by deploy/install.sh (Block 1). */
    private const PORTAL_AUTOLOAD = '/opt/gfserver/web/vendor/autoload.php';

    /** @var config */
    protected $config;

    /** @var driver_interface */
    protected $db;

    /** @var user */
    protected $user;

    /** @var string */
    protected $phpbb_root_path;

    /** @var string */
    protected $php_ext;

    public function __construct(config $config, driver_interface $db, user $user, $phpbb_root_path, $php_ext)
    {
        $this->config = $config;
        $this->db = $db;
        $this->user = $user;
        $this->phpbb_root_path = $phpbb_root_path;
        $this->php_ext = $php_ext;
    }

    /**
     * Credential-based provider — there is no ambient session identity to
     * verify at init time.
     */
    public function init()
    {
        return false;
    }

    /**
     * Authenticate $username/$password against the game accounts.
     *
     * @param string $username
     * @param string $password
     * @return array{status: int, error_msg: string|false, user_row: array}
     */
    public function login($username, $password)
    {
        if (!$username)
        {
            return array(
                'status'    => LOGIN_ERROR_USERNAME,
                'error_msg' => 'LOGIN_ERROR_USERNAME',
                'user_row'  => array('user_id' => ANONYMOUS),
            );
        }

        if (!$password)
        {
            return array(
                'status'    => LOGIN_ERROR_PASSWORD,
                'error_msg' => 'NO_PASSWORD_SUPPLIED',
                'user_row'  => array('user_id' => ANONYMOUS),
            );
        }

        $account_id = $this->game_account_id((string) $username, (string) $password);
        if ($account_id === null)
        {
            return array(
                'status'    => LOGIN_ERROR_PASSWORD,
                'error_msg' => 'LOGIN_ERROR_PASSWORD',
                'user_row'  => array('user_id' => ANONYMOUS),
            );
        }

        $row = $this->phpbb_user_row((string) $username);
        if ($row)
        {
            return array(
                'status'    => LOGIN_SUCCESS,
                'error_msg' => false,
                'user_row'  => $row,
            );
        }

        // First forum login for this game account — create the phpBB user.
        if (!function_exists('user_add'))
        {
            include $this->phpbb_root_path . 'includes/functions_user.' . $this->php_ext;
        }
        user_add($this->new_user_row((string) $username, $account_id));

        return array(
            'status'    => LOGIN_SUCCESS,
            'error_msg' => false,
            'user_row'  => $this->phpbb_user_row((string) $username),
        );
    }

    /**
     * Validate credentials via the portal's AccountService.
     * Returns the accounts.id on success, or null on failure.
     */
    private function game_account_id(string $username, string $password): ?int
    {
        $accounts = $this->account_service();

        return $accounts->authenticate($username, $password);
    }

    /** Build a GfServer\AccountService bound to the game databases. */
    private function account_service(): \GfServer\AccountService
    {
        require_once self::PORTAL_AUTOLOAD;

        return new \GfServer\AccountService(
            new \GfServer\Database(\GfServer\Config::fromEnv())
        );
    }

    /** Fetch the phpbb_users row for $username, or false if there is none. */
    private function phpbb_user_row(string $username)
    {
        $sql = 'SELECT *
            FROM ' . USERS_TABLE . "
            WHERE username_clean = '" . $this->db->sql_escape(utf8_clean_string($username)) . "'";
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row;
    }

    /**
     * Build the phpbb_users row for a newly auto-provisioned account.
     *
     * @return array<string, mixed>
     */
    private function new_user_row(string $username, int $accountId): array
    {
        $sql = 'SELECT group_id
            FROM ' . GROUPS_TABLE . "
            WHERE group_name = 'REGISTERED'
                AND group_type = " . GROUP_SPECIAL;
        $result = $this->db->sql_query($sql);
        $group_row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$group_row)
        {
            trigger_error('NO_GROUP');
        }

        $web = $this->account_service()->webAccount($accountId);
        $email = ($web !== null && $web['email'] !== null && $web['email'] !== '')
            ? $web['email']
            : $username . '@accounts.invalid';

        return array(
            'username'      => $username,
            'user_password' => '',
            'user_email'    => $email,
            'group_id'      => (int) $group_row['group_id'],
            'user_type'     => USER_NORMAL,
            'user_ip'       => $this->user->ip,
            'user_new'      => $this->config['new_member_post_limit'] ? 1 : 0,
        );
    }
}
```

- [ ] **Step 2: Verify** — confirm the file exists with LF endings; confirm the class is `gfserver\sso\auth\provider\gfserver` extending `base`, with `init()` and `login()` implemented.

- [ ] **Step 3: Commit**

```bash
git add forum/ext/gfserver/sso/auth/provider/gfserver.php
git commit -m "feat(forum): add the gf_server SSO auth provider"
```

---

## Task 3: install.sh — phpBB deployment (`setup_forum`)

**Files:** Modify `deploy/install.sh`

**Notes:** phpBB 3.3 supports a headless CLI install via `install/phpbbcli.php install <config.yml>`; after a successful install the `install/` directory must be removed, and the general `bin/phpbbcli.php` is used for `extension:enable` and `config:set`. The implementer downloads phpBB in this task and **must confirm the install-config YAML keys against the bundled `install/install-config.yml.all` sample** in the downloaded archive before finalising the generator — phpBB's install-config schema is the one external-tool contract here. Adjust the YAML in Step 1 to match that sample if it differs.

- [ ] **Step 1: Add a `setup_forum` function to `deploy/install.sh`**

Locate the line `# --- 6. Render component setup.ini files` and insert the following function on the lines immediately BEFORE it:

```bash
# --- 5e. phpBB community forum ----------------------------------------------
PHPBB_VERSION="${PHPBB_VERSION:-3.3.14}"

setup_forum() {
  # Deployed phpBB framework lives at ${GF_ROOT}/phpbb; the repo keeps our
  # committed extension source separately at ${GF_ROOT}/forum/ext/...
  local forum_dir="${GF_ROOT}/phpbb"
  local ext_src="${GF_ROOT}/forum/ext/gfserver/sso"
  local archive="/tmp/phpbb-${PHPBB_VERSION}.zip"

  log "Installing phpBB ${PHPBB_VERSION} dependencies..."
  apt-get install -y curl unzip php-gd php-xml php-zip

  if [ ! -f "${forum_dir}/config.php" ]; then
    log "Downloading phpBB ${PHPBB_VERSION}..."
    curl -fsSL -o "$archive" \
      "https://download.phpbb.com/pub/release/3.3/${PHPBB_VERSION}/phpBB-${PHPBB_VERSION}.zip"
    rm -rf "$forum_dir"
    unzip -q "$archive" -d /tmp/phpbb-extract
    mv /tmp/phpbb-extract/phpBB "$forum_dir"
    rm -rf /tmp/phpbb-extract "$archive"

    log "Creating the gf_forum database and role..."
    sudo -u postgres psql -v ON_ERROR_STOP=1 -q <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gf_forum') THEN
    CREATE ROLE gf_forum LOGIN PASSWORD '${FORUM_DB_PASSWORD}';
  ELSE
    ALTER ROLE gf_forum LOGIN PASSWORD '${FORUM_DB_PASSWORD}';
  END IF;
END
\$\$;
SQL
    if ! sudo -u postgres psql -tAc \
         "SELECT 1 FROM pg_database WHERE datname='gf_forum'" | grep -q 1; then
      sudo -u postgres psql -v ON_ERROR_STOP=1 -q \
        -c "CREATE DATABASE gf_forum OWNER gf_forum ENCODING 'UTF8' TEMPLATE template0;"
    fi

    log "Running the phpBB CLI installer..."
    cat > /tmp/phpbb-install.yml <<YML
installer:
  admin:
    name: ${FORUM_ADMIN_USERNAME}
    password: ${FORUM_ADMIN_PASSWORD}
    email: ${FORUM_ADMIN_EMAIL}
  board:
    lang: en
    name: Grand Fantasia
    description: Grand Fantasia community forum
  database:
    dbms: phpbb\\db\\driver\\postgres
    dbhost: 127.0.0.1
    dbport: 5432
    dbuser: gf_forum
    dbpasswd: ${FORUM_DB_PASSWORD}
    dbname: gf_forum
    table_prefix: phpbb_
  email:
    enabled: true
  server:
    cookie_secure: true
    server_protocol: https://
    force_server_vars: true
    server_name: ${FORUM_DOMAIN}
    server_port: 443
    script_path: /
YML
    php "${forum_dir}/install/phpbbcli.php" install /tmp/phpbb-install.yml
    rm -f /tmp/phpbb-install.yml
    rm -rf "${forum_dir}/install"
  else
    log "phpBB already installed — skipping download and install."
  fi

  log "Deploying and enabling the SSO extension..."
  mkdir -p "${forum_dir}/ext/gfserver"
  rm -rf "${forum_dir}/ext/gfserver/sso"
  cp -r "$ext_src" "${forum_dir}/ext/gfserver/sso"
  php "${forum_dir}/bin/phpbbcli.php" extension:enable gfserver/sso || true
  php "${forum_dir}/bin/phpbbcli.php" config:set auth_method gfserver
  php "${forum_dir}/bin/phpbbcli.php" config:set require_activation 0
  php "${forum_dir}/bin/phpbbcli.php" config:set allow_password_reset 0

  chown -R www-data:www-data "$forum_dir"

  log "Writing the forum Apache virtual host..."
  cat > /etc/apache2/sites-available/gfforum.conf <<APACHE
<VirtualHost *:80>
    ServerName ${FORUM_DOMAIN}
    DocumentRoot ${forum_dir}
    DirectoryIndex index.php

    <Directory ${forum_dir}>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>

    <FilesMatch \.php\$>
        SetHandler "proxy:unix:/run/php/php-fpm-gfserver.sock|fcgi://localhost"
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/gfforum-error.log
    CustomLog \${APACHE_LOG_DIR}/gfforum-access.log combined
</VirtualHost>
APACHE

  a2ensite gfforum >/dev/null
  systemctl reload apache2
  log "Forum ready. Run 'certbot --apache' for ${FORUM_DOMAIN} (see runbook)."
}
```

- [ ] **Step 2: Wire `setup_forum` into `main()`**

In `deploy/install.sh`, replace this exact block:

```
  bootstrap_admin
  setup_web_server
```

with:

```
  bootstrap_admin
  setup_web_server
  setup_forum
```

- [ ] **Step 3: Verify**

Run: `bash -n deploy/install.sh`
Expected: no output, exit 0.

Download phpBB once to confirm the install-config schema: `curl -fsSL -o /tmp/p.zip "https://download.phpbb.com/pub/release/3.3/3.3.14/phpBB-3.3.14.zip" && unzip -p /tmp/p.zip phpBB/install/install-config.yml.all` — read the sample and, if the YAML keys in Step 1 differ, correct the heredoc to match. (If the download is unavailable in the dev environment, leave the YAML as written and note that it must be confirmed on the VPS.)

- [ ] **Step 4: Commit**

```bash
git add deploy/install.sh
git commit -m "feat(deploy): install phpBB and the SSO extension"
```

---

## Task 4: Forum environment variables

**Files:** Modify `deploy/gfserver.env.example`, `deploy/install.sh`

- [ ] **Step 1: Add the forum variables to `deploy/gfserver.env.example`**

Replace this exact block:

```
# Full URL of the game client download (leave empty until available).
GF_DOWNLOAD_URL=
```

with:

```
# Full URL of the game client download (leave empty until available).
GF_DOWNLOAD_URL=

# --- Forum (Block 3b) ------------------------------------------------------
# Domain the phpBB forum is served on.
FORUM_DOMAIN=forum.gf.example.com

# Password for the PostgreSQL role 'gf_forum' (owns the phpBB database).
FORUM_DB_PASSWORD=

# First phpBB administrator, created by the headless installer.
FORUM_ADMIN_USERNAME=admin
FORUM_ADMIN_PASSWORD=
FORUM_ADMIN_EMAIL=admin@gf.example.com
```

- [ ] **Step 2: Validate FORUM_DB_PASSWORD in `preflight`**

In `deploy/install.sh`, replace this exact block:

```
  if [ -n "${WEB_DB_PASSWORD:-}" ] && printf '%s' "$WEB_DB_PASSWORD" | grep -q '[^A-Za-z0-9._-]'; then
    die "WEB_DB_PASSWORD may only contain A-Z a-z 0-9 . _ - (edit gfserver.env)."
  fi
```

with:

```
  if [ -n "${WEB_DB_PASSWORD:-}" ] && printf '%s' "$WEB_DB_PASSWORD" | grep -q '[^A-Za-z0-9._-]'; then
    die "WEB_DB_PASSWORD may only contain A-Z a-z 0-9 . _ - (edit gfserver.env)."
  fi
  if [ -n "${FORUM_DB_PASSWORD:-}" ] && printf '%s' "$FORUM_DB_PASSWORD" | grep -q '[^A-Za-z0-9._-]'; then
    die "FORUM_DB_PASSWORD may only contain A-Z a-z 0-9 . _ - (edit gfserver.env)."
  fi
```

- [ ] **Step 3: Export FORUM_DOMAIN to the PHP-FPM pool**

In `deploy/install.sh`, in `setup_web_server`, replace this exact line:

```
env[PORTAL_DOMAIN] = ${PORTAL_DOMAIN:-localhost}
```

with:

```
env[PORTAL_DOMAIN] = ${PORTAL_DOMAIN:-localhost}
env[FORUM_DOMAIN] = ${FORUM_DOMAIN:-localhost}
```

- [ ] **Step 4: Verify**

Run: `bash -n deploy/install.sh`
Expected: no output, exit 0.
Run: `bash -c 'set -euo pipefail; source deploy/gfserver.env.example; echo "$FORUM_DOMAIN $FORUM_ADMIN_USERNAME"'`
Expected: `forum.gf.example.com admin`

- [ ] **Step 5: Commit**

```bash
git add deploy/gfserver.env.example deploy/install.sh
git commit -m "feat(deploy): add forum environment variables"
```

---

## Task 5: Portal "Forum" navigation link

**Files:** Modify `web/public/index.php`, `web/templates/layout.php`

- [ ] **Step 1: Share the forum URL in `web/public/index.php`**

Replace this exact block:

```php
// Make the logged-in user's name available to every template (the nav).
$navUsername = $session->get(Auth::SESSION_USERNAME);
$view->share([
    'navLoggedIn' => is_string($navUsername) && $navUsername !== '',
    'navUsername' => is_string($navUsername) ? $navUsername : '',
]);
```

with:

```php
// Make the logged-in user's name and the forum URL available to every
// template (the nav).
$navUsername = $session->get(Auth::SESSION_USERNAME);
$forumDomain = getenv('FORUM_DOMAIN');
$view->share([
    'navLoggedIn' => is_string($navUsername) && $navUsername !== '',
    'navUsername' => is_string($navUsername) ? $navUsername : '',
    'forumUrl' => ($forumDomain !== false && $forumDomain !== '')
        ? 'https://' . $forumDomain
        : null,
]);
```

- [ ] **Step 2: Add the "Forum" link to `web/templates/layout.php`**

Replace this exact block:

```php
            <a href="/status">Status</a>
            <a href="/rankings">Rankings</a>
```

with:

```php
            <a href="/status">Status</a>
            <a href="/rankings">Rankings</a>
            <?php if (!empty($forumUrl)): ?>
                <a href="<?= e($forumUrl) ?>">Forum</a>
            <?php endif; ?>
```

- [ ] **Step 3: Verify** — confirm both files have LF endings; confirm `layout.php` renders the `Forum` link only when `$forumUrl` is set.

- [ ] **Step 4: Commit**

```bash
git add web/public/index.php web/templates/layout.php
git commit -m "feat(web): add a Forum link to the portal navigation"
```

---

## Task 6: Runbook — forum section

**Files:** Modify `docs/deployment-runbook.md`

- [ ] **Step 1: Append a forum section to `docs/deployment-runbook.md`**

Append this section to the end of the file (it is literal Markdown):

```
## Forum (Block 3b)

`install.sh` also installs phpBB 3.3 under `/opt/gfserver/phpbb`, served on
`FORUM_DOMAIN`. Single sign-on is provided by the `gfserver/sso` extension —
players log in with their game account.

1. Before running `install.sh`, set `FORUM_DOMAIN`, `FORUM_DB_PASSWORD` and
   the `FORUM_ADMIN_*` values in `deploy/gfserver.env`, and point the forum
   subdomain's DNS A record at the VPS.
2. Confirm `PHPBB_VERSION` in `install.sh` is the current phpBB 3.3.x security
   release before installing.
3. After install, enable HTTPS for the forum:
   `sudo certbot --apache -d <your-forum-domain>`
4. Verify SSO: open the forum, log in with an existing game account — the
   phpBB user is created automatically on first login.

**phpBB updates:** the phpBB framework under `/opt/gfserver/phpbb` is not part
of this repository. Apply phpBB security releases manually (download the new
version, follow phpBB's update procedure); the `gfserver/sso` extension is
re-deployed from the repo by re-running `install.sh`.
```

- [ ] **Step 2: Verify** — confirm `docs/deployment-runbook.md` ends with the new `## Forum (Block 3b)` section.

- [ ] **Step 3: Commit**

```bash
git add docs/deployment-runbook.md
git commit -m "docs: add the forum section to the deployment runbook"
```

---

## Task 7: CI syntax check, self-review

**Files:** Modify `.github/workflows/ci.yml`

- [ ] **Step 1: Add a phpBB-extension syntax check to the `web-backend` job**

In `.github/workflows/ci.yml`, in the `web-backend` job, replace this exact block:

```yaml
      - name: Run PHPUnit
        working-directory: web
```

with:

```yaml
      - name: Syntax-check the phpBB SSO extension
        run: |
          for f in $(find forum/ext -name '*.php'); do
            php -l "$f"
          done

      - name: Run PHPUnit
        working-directory: web
```

- [ ] **Step 2: Verify** — confirm `.github/workflows/ci.yml` still parses as YAML (`python -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))"` if available) and the new step sits inside the `web-backend` job's `steps:` list at the correct indentation.

- [ ] **Step 3: Self-review against the Plan ③b scope**

Confirm each deliverable exists:
- The SSO extension — `composer.json`, `ext.php`, `config/services.yml`, `auth/provider/gfserver.php` under `forum/ext/gfserver/sso/` (Tasks 1-2).
- `install.sh` has `setup_forum` (deployed phpBB at `/opt/gfserver/phpbb`, `gf_forum` DB owned by the `gf_forum` role, CLI install, extension deploy + enable, `auth_method=gfserver`, registration/password-reset disabled, the forum vhost) and `main()` calls it (Tasks 3-4).
- Forum env variables + `FORUM_DB_PASSWORD` validation + `FORUM_DOMAIN` in the FPM pool (Task 4).
- Portal "Forum" nav link (Task 5); runbook section (Task 6); CI `php -l` (Task 7).

Confirm the `setup_forum` install path is `/opt/gfserver/phpbb` (not `/opt/gfserver/forum`, which is the repo's extension source) and the extension `cp` uses `$ext_src`.

Report findings; fix any genuine gap inline, otherwise this is just the review.

- [ ] **Step 4: Commit (only if Step 3 changed anything)**

```bash
git add -A
git commit -m "ci: syntax-check the phpBB extension; Plan 3b self-review"
```

If Step 3 found nothing to change, still commit the CI change from Step 1:

```bash
git add .github/workflows/ci.yml
git commit -m "ci: syntax-check the phpBB SSO extension"
```

---

## Verification Summary

- **Per task (Windows):** file creation, LF endings, `bash -n` for `install.sh`, JSON/YAML parse checks.
- **CI (`web-backend` job):** `php -l` syntax-checks the extension PHP. The SSO credential logic is Block ②'s already-tested `AccountService::authenticate()` — no new auth logic to unit-test.
- **Deferred to the deployment phase (per the spec):** the phpBB CLI install, the `forum.<domain>` vhost + HTTPS, and the end-to-end SSO login (a game account logging into the forum, auto-provisioning). This is manual VPS acceptance.

## Open Items

1. `PHPBB_VERSION` is pinned to `3.3.14`; the runbook instructs confirming the current 3.3.x security release before installing.
2. The phpBB install-config YAML schema is the one external-tool contract — Task 3 Step 3 confirms it against phpBB's bundled `install-config.yml.all` sample.
3. The SSO auth provider is modelled on phpBB 3.3's bundled `apache.php`; it is verified against the pinned phpBB during the deployment phase.
4. Block ③b completes the community portal. Two further large project topics remain — to be raised with the user next.
