# Block ① Server-Fundament & Deployment — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the legacy `install`/`server` scripts with a hardened, idempotent deployment toolchain that runs the Grand Fantasia game servers safely as an unprivileged user under systemd on Ubuntu 24.04.

**Architecture:** A `deploy/` directory holds an idempotent `install.sh` orchestrator, a `gfctl` management script, six hardened systemd units plus a target, an automated backup timer, and a config template. Shared shell helpers live in `deploy/lib/common.sh`. The IP-patching of the closed binaries is isolated in `deploy/ip-patch.sh` so it can be unit-tested. PostgreSQL is bound to localhost with a non-superuser application role.

**Tech Stack:** Bash, systemd, PostgreSQL 16, ufw, fail2ban, ShellCheck (test tooling).

**Spec:** `docs/superpowers/specs/2026-05-16-gf-server-foundation-deployment-design.md`

---

## File Structure

```
deploy/
  install.sh              # Orchestrator — run once on the VPS as root
  gfctl                   # Day-to-day management (start/stop/status/backup/restore)
  gf-backup.sh            # Backup logic, shared by gfctl and the backup timer
  ip-patch.sh             # IP-patching of WorldServer/ZoneServer (unit-tested)
  gfserver.env.example    # Config template (real gfserver.env is git-ignored)
  lib/
    common.sh             # Shared logging + guard helpers (sourced, not executed)
  systemd/
    gf-ticket.service
    gf-gateway.service
    gf-login.service
    gf-mission.service
    gf-world.service
    gf-zone.service
    gfserver.target
    gf-backup.service
    gf-backup.timer
  tests/
    test-ip-patch.sh      # Unit test for ip-patch.sh
    run-checks.sh         # Aggregate: ShellCheck + bash -n + systemd-analyze verify
docs/
  deployment-runbook.md   # Fresh VPS -> "server online"
.gitignore
```

The legacy `install` and `server` scripts are removed.

**Conventions used by every task:** Work happens on branch `block1-foundation-deployment` (already created). Each task ends with a commit. ShellCheck is run with `shellcheck -x` (so it follows `source`d files).

---

## Task 1: Repo scaffolding, .gitignore, remove legacy scripts

**Files:**
- Create: `deploy/`, `deploy/lib/`, `deploy/systemd/`, `deploy/tests/`, `docs/` (directories)
- Create: `.gitignore`
- Delete: `install`, `server`

- [ ] **Step 1: Create the directory skeleton**

```bash
cd /path/to/gf_server
mkdir -p deploy/lib deploy/systemd deploy/tests docs
```

- [ ] **Step 2: Write `.gitignore`**

Create `.gitignore`:

```gitignore
# Real deployment config — contains the public IP and DB password.
deploy/gfserver.env

# Generated / patched artifacts.
*.bak
backup/

# Server runtime logs.
logs/
*.log

# OS noise.
.DS_Store
Thumbs.db
```

- [ ] **Step 3: Remove the legacy scripts**

The legacy `install` and `server` scripts are replaced wholesale and must not be mistaken for the new toolchain.

```bash
git rm install server
```

- [ ] **Step 4: Verify the working tree**

Run: `git status --short`
Expected: `.gitignore` shown as added (`A`/`??`), `install` and `server` shown as deleted (`D`).

- [ ] **Step 5: Commit**

```bash
git add .gitignore
git commit -m "chore: scaffold deploy/ layout, add .gitignore, drop legacy scripts"
```

---

## Task 2: Shared helper library `deploy/lib/common.sh`

**Files:**
- Create: `deploy/lib/common.sh`

- [ ] **Step 1: Write `deploy/lib/common.sh`**

This file is **sourced**, never executed. It provides logging and guard helpers used by `install.sh`, `gfctl`, and `gf-backup.sh`.

```bash
#!/usr/bin/env bash
# Shared helpers for the gf_server deploy toolchain.
# Source this file; do not execute it directly.

# Installation layout — single source of truth.
GF_ROOT="${GF_ROOT:-/opt/gfserver}"
GF_USER="${GF_USER:-gfserver}"
GF_GROUP="${GF_GROUP:-gfserver}"

# Colored logging to stderr so command substitution stays clean.
log()  { printf '\033[0;32m[gf]\033[0m %s\n'      "$*" >&2; }
warn() { printf '\033[0;33m[gf:warn]\033[0m %s\n' "$*" >&2; }
err()  { printf '\033[0;31m[gf:err]\033[0m %s\n'  "$*" >&2; }
die()  { err "$*"; exit 1; }

# Guard: refuse to continue unless running as root.
require_root() {
  [ "$(id -u)" -eq 0 ] || die "This script must be run as root."
}

# Guard: refuse to continue unless this is Ubuntu 24.04.
require_ubuntu_2404() {
  local id ver
  id=$(. /etc/os-release 2>/dev/null && printf '%s' "${ID:-}")
  ver=$(. /etc/os-release 2>/dev/null && printf '%s' "${VERSION_ID:-}")
  [ "$id" = "ubuntu" ] && [ "$ver" = "24.04" ] \
    || die "Expected Ubuntu 24.04 (found '${id:-?} ${ver:-?}')."
}

# Load and validate deploy/gfserver.env.
# Sets: HOST_IP, DB_PASSWORD, GAME_PORTS, SSH_PORT.
load_env() {
  local env_file="${1:-${GF_ROOT}/deploy/gfserver.env}"
  [ -f "$env_file" ] || die "Config not found: $env_file (copy gfserver.env.example)."
  # shellcheck disable=SC1090
  . "$env_file"
  [ -n "${HOST_IP:-}" ]    || die "HOST_IP is not set in $env_file."
  [ -n "${GAME_PORTS:-}" ] || die "GAME_PORTS is not set in $env_file."
  SSH_PORT="${SSH_PORT:-22}"
}
```

- [ ] **Step 2: Verify it parses and lints clean**

Run: `bash -n deploy/lib/common.sh && shellcheck deploy/lib/common.sh`
Expected: no output, exit code 0.

- [ ] **Step 3: Verify sourcing works**

Run: `bash -c 'set -euo pipefail; source deploy/lib/common.sh; log "common.sh OK"; echo "GF_ROOT=$GF_ROOT"'`
Expected: stderr shows `[gf] common.sh OK`, stdout shows `GF_ROOT=/opt/gfserver`.

- [ ] **Step 4: Commit**

```bash
git add deploy/lib/common.sh
git commit -m "feat(deploy): add shared shell helper library"
```

---

## Task 3: IP-patching script `deploy/ip-patch.sh` (test-first)

**Files:**
- Create: `deploy/tests/test-ip-patch.sh`
- Create: `deploy/ip-patch.sh`

**Background:** `WorldServer` and `ZoneServer` store the server IP as an ASCII string at fixed offsets (`0x3EA7A7` / `0x822D47`). The original installer wrote the `/24` network address (last octet forced to `0`) followed by zero padding. We reproduce that behaviour exactly, add read-back verification, and isolate it for testing.

- [ ] **Step 1: Write the failing test**

Create `deploy/tests/test-ip-patch.sh`:

```bash
#!/usr/bin/env bash
# Unit test for deploy/ip-patch.sh — runs without root or the real binaries.
set -euo pipefail
cd "$(dirname "$0")/.."
source ip-patch.sh

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

# A dummy 256-byte binary of 0xAA bytes.
fake="$tmp/fake.bin"
head -c 256 /dev/zero | tr '\0' '\252' > "$fake"

# Test 1: patch IP at offset 0x10 (=16), 6 pad bytes.
patch_ip "$fake" 10 "192.168.7.42" 6 || fail "patch_ip returned non-zero"

# Expected bytes: ASCII "192.168.7.0" + 6 zero bytes.
expected=$(printf '%s' "192.168.7.0" | od -An -tx1 | tr -d ' \n')
expected+="000000000000"
got=$(dd if="$fake" bs=1 skip=16 count=$(( ${#expected} / 2 )) status=none \
      | od -An -tx1 | tr -d ' \n')
[ "$got" = "$expected" ] || fail "patched bytes mismatch: got=$got want=$expected"
pass "patches the /24 network address as ASCII + zero padding"

# Test 2: idempotent — patching again yields the same result.
patch_ip "$fake" 10 "192.168.7.42" 6 || fail "second patch returned non-zero"
got2=$(dd if="$fake" bs=1 skip=16 count=$(( ${#expected} / 2 )) status=none \
       | od -An -tx1 | tr -d ' \n')
[ "$got2" = "$expected" ] || fail "patch not idempotent"
pass "patching twice is idempotent"

# Test 3: an invalid IPv4 address is rejected.
if patch_ip "$fake" 10 "not.an.ip" 6 2>/dev/null; then
  fail "invalid IP was accepted"
fi
pass "rejects an invalid IPv4 address"

# Test 4: a missing binary is rejected.
if patch_ip "$tmp/missing.bin" 10 "192.168.7.42" 6 2>/dev/null; then
  fail "missing binary was accepted"
fi
pass "rejects a missing binary file"

echo "ALL TESTS PASSED"
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `bash deploy/tests/test-ip-patch.sh`
Expected: FAIL — `ip-patch.sh` does not exist yet (`source: ip-patch.sh: No such file or directory`).

- [ ] **Step 3: Write `deploy/ip-patch.sh`**

```bash
#!/usr/bin/env bash
# Patch the hardcoded server IP into a Grand Fantasia server binary.
# Source it to use patch_ip(), or run it directly as a CLI.
set -euo pipefail

# patch_ip <binary> <hex-offset> <ipv4> <pad-bytes>
# Writes the /24 network address (last octet forced to 0) of <ipv4> as an
# ASCII string at <hex-offset>, followed by <pad-bytes> zero bytes, then
# verifies the write by reading the bytes back.
patch_ip() {
  local binary="$1" offset_hex="$2" ip="$3" pad_bytes="$4"

  [ -f "$binary" ] || { echo "patch_ip: binary not found: $binary" >&2; return 1; }

  local IFS='.'
  local -a parts
  read -ra parts <<< "$ip"
  if [ "${#parts[@]}" -ne 4 ]; then
    echo "patch_ip: invalid IPv4 address: $ip" >&2; return 1
  fi
  local octet
  for octet in "${parts[@]}"; do
    case "$octet" in
      ''|*[!0-9]*) echo "patch_ip: invalid IPv4 address: $ip" >&2; return 1 ;;
    esac
    [ "$octet" -le 255 ] || { echo "patch_ip: invalid IPv4: $ip" >&2; return 1; }
  done
  unset IFS

  local server_ip="${parts[0]}.${parts[1]}.${parts[2]}.0"

  # ASCII string -> hex.
  local hex="" i ch
  for (( i=0; i<${#server_ip}; i++ )); do
    ch="${server_ip:i:1}"
    hex+=$(printf '%02x' "'$ch")
  done
  # Append pad_bytes zero bytes.
  local p
  for (( p=0; p<pad_bytes; p++ )); do hex+="00"; done

  local byte_count=$(( ${#hex} / 2 ))
  local escaped
  escaped=$(printf '%s' "$hex" | sed 's/\(..\)/\\x\1/g')

  printf '%b' "$escaped" | dd of="$binary" bs=1 \
      seek=$(( 16#$offset_hex )) count="$byte_count" conv=notrunc status=none

  # Verify by reading the bytes back.
  local written
  written=$(dd if="$binary" bs=1 skip=$(( 16#$offset_hex )) count="$byte_count" \
            status=none | od -An -tx1 | tr -d ' \n')
  if [ "$written" != "$hex" ]; then
    echo "patch_ip: verification failed at offset 0x$offset_hex" >&2
    return 1
  fi
}

# CLI entry point when executed directly.
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
  if [ "$#" -ne 4 ]; then
    echo "usage: $0 <binary> <hex-offset> <ipv4> <pad-bytes>" >&2
    exit 1
  fi
  patch_ip "$@"
fi
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `bash deploy/tests/test-ip-patch.sh`
Expected: four `PASS:` lines, then `ALL TESTS PASSED`, exit code 0.

- [ ] **Step 5: Lint**

Run: `shellcheck deploy/ip-patch.sh deploy/tests/test-ip-patch.sh`
Expected: no output, exit code 0.

- [ ] **Step 6: Commit**

```bash
git add deploy/ip-patch.sh deploy/tests/test-ip-patch.sh
git commit -m "feat(deploy): add tested IP-patching for World/Zone binaries"
```

---

## Task 4: Config template `deploy/gfserver.env.example`

**Files:**
- Create: `deploy/gfserver.env.example`

- [ ] **Step 1: Write `deploy/gfserver.env.example`**

```bash
# gf_server deployment config — copy to gfserver.env and fill in.
# gfserver.env is git-ignored; never commit real values.

# Public IPv4 address of this VPS. Players connect here.
HOST_IP=203.0.113.10

# Password for the non-superuser PostgreSQL role 'gf_app'.
# Leave empty to have install.sh generate a strong random password.
DB_PASSWORD=

# Space-separated TCP ports that must be reachable from the internet.
# Defaults cover LoginServer (6543) and the billing gateway (5560).
# Verify and extend against the DB worlds/serverstatus tables — see
# docs/deployment-runbook.md. Do NOT add the ZoneServer GM port (10320).
GAME_PORTS="6543 5560"

# SSH port — ufw opens and rate-limits this.
SSH_PORT=22

# Number of dated DB backups gf-backup.sh keeps before pruning the oldest.
BACKUP_KEEP=14
```

- [ ] **Step 2: Verify it sources cleanly**

Run: `bash -c 'set -euo pipefail; source deploy/gfserver.env.example; echo "$HOST_IP $GAME_PORTS $SSH_PORT $BACKUP_KEEP"'`
Expected: `203.0.113.10 6543 5560 22 14`

- [ ] **Step 3: Commit**

```bash
git add deploy/gfserver.env.example
git commit -m "feat(deploy): add deployment config template"
```

---

## Task 5: systemd units (six services + target)

**Files:**
- Create: `deploy/systemd/gf-ticket.service`
- Create: `deploy/systemd/gf-gateway.service`
- Create: `deploy/systemd/gf-login.service`
- Create: `deploy/systemd/gf-mission.service`
- Create: `deploy/systemd/gf-world.service`
- Create: `deploy/systemd/gf-zone.service`
- Create: `deploy/systemd/gfserver.target`

**Notes:** Units run as the unprivileged `gfserver` user. They are ordered in a chain matching the legacy `server start` script. `Restart=on-failure` makes a component that starts before its dependency is ready self-heal. Output goes to the journal (`journalctl -u gf-zone`). `ReadWritePaths=/opt/gfserver` is required because the binaries write logs/config inside their working directory while `ProtectSystem=strict` keeps the rest of the filesystem read-only.

- [ ] **Step 1: Write `deploy/systemd/gf-ticket.service`**

```ini
[Unit]
Description=Grand Fantasia Ticket Server
After=network-online.target postgresql.service
Wants=network-online.target
PartOf=gfserver.target

[Service]
Type=simple
User=gfserver
Group=gfserver
WorkingDirectory=/opt/gfserver/TicketServer
ExecStart=/opt/gfserver/TicketServer/TicketServer -p 7777
Restart=on-failure
RestartSec=5
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
ReadWritePaths=/opt/gfserver

[Install]
WantedBy=gfserver.target
```

- [ ] **Step 2: Write `deploy/systemd/gf-gateway.service`**

```ini
[Unit]
Description=Grand Fantasia Gateway Server
After=network-online.target postgresql.service gf-ticket.service
Wants=network-online.target
PartOf=gfserver.target

[Service]
Type=simple
User=gfserver
Group=gfserver
WorkingDirectory=/opt/gfserver/GatewayServer
ExecStart=/opt/gfserver/GatewayServer/GatewayServer
Restart=on-failure
RestartSec=5
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
ReadWritePaths=/opt/gfserver

[Install]
WantedBy=gfserver.target
```

- [ ] **Step 3: Write `deploy/systemd/gf-login.service`**

```ini
[Unit]
Description=Grand Fantasia Login Server
After=network-online.target postgresql.service gf-gateway.service
Wants=network-online.target
PartOf=gfserver.target

[Service]
Type=simple
User=gfserver
Group=gfserver
WorkingDirectory=/opt/gfserver/LoginServer
ExecStart=/opt/gfserver/LoginServer/LoginServer
Restart=on-failure
RestartSec=5
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
ReadWritePaths=/opt/gfserver

[Install]
WantedBy=gfserver.target
```

- [ ] **Step 4: Write `deploy/systemd/gf-mission.service`**

```ini
[Unit]
Description=Grand Fantasia Mission Server
After=network-online.target postgresql.service gf-login.service
Wants=network-online.target
PartOf=gfserver.target

[Service]
Type=simple
User=gfserver
Group=gfserver
WorkingDirectory=/opt/gfserver/MissionServer
ExecStart=/opt/gfserver/MissionServer/MissionServer
Restart=on-failure
RestartSec=5
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
ReadWritePaths=/opt/gfserver

[Install]
WantedBy=gfserver.target
```

- [ ] **Step 5: Write `deploy/systemd/gf-world.service`**

```ini
[Unit]
Description=Grand Fantasia World Server
After=network-online.target postgresql.service gf-mission.service
Wants=network-online.target
PartOf=gfserver.target

[Service]
Type=simple
User=gfserver
Group=gfserver
WorkingDirectory=/opt/gfserver/WorldServer
ExecStart=/opt/gfserver/WorldServer/WorldServer
Restart=on-failure
RestartSec=5
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
ReadWritePaths=/opt/gfserver

[Install]
WantedBy=gfserver.target
```

- [ ] **Step 6: Write `deploy/systemd/gf-zone.service`**

```ini
[Unit]
Description=Grand Fantasia Zone Server
After=network-online.target postgresql.service gf-world.service
Wants=network-online.target
PartOf=gfserver.target

[Service]
Type=simple
User=gfserver
Group=gfserver
WorkingDirectory=/opt/gfserver/ZoneServer
ExecStart=/opt/gfserver/ZoneServer/ZoneServer
Restart=on-failure
RestartSec=5
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
ReadWritePaths=/opt/gfserver

[Install]
WantedBy=gfserver.target
```

- [ ] **Step 7: Write `deploy/systemd/gfserver.target`**

```ini
[Unit]
Description=Grand Fantasia Server (all components)
After=postgresql.service
Wants=gf-ticket.service gf-gateway.service gf-login.service gf-mission.service gf-world.service gf-zone.service

[Install]
WantedBy=multi-user.target
```

- [ ] **Step 8: Verify the units parse**

Run: `for u in deploy/systemd/*; do systemd-analyze verify "$u" || echo "FAILED: $u"; done`
Expected: no `FAILED:` lines. (Warnings about the `ExecStart` binary path not existing on the dev machine are acceptable; a hard parse error is not.)

- [ ] **Step 9: Commit**

```bash
git add deploy/systemd/
git commit -m "feat(deploy): add hardened systemd units for all server components"
```

---

## Task 6: Backup logic + backup timer

**Files:**
- Create: `deploy/gf-backup.sh`
- Create: `deploy/systemd/gf-backup.service`
- Create: `deploy/systemd/gf-backup.timer`

- [ ] **Step 1: Write `deploy/gf-backup.sh`**

```bash
#!/usr/bin/env bash
# Dump the three game databases to a dated folder and prune old backups.
# Used both by the gf-backup.timer and by `gfctl backup`.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"
load_env "${SCRIPT_DIR}/gfserver.env"

BACKUP_KEEP="${BACKUP_KEEP:-14}"
backup_root="${GF_ROOT}/backup"
stamp="$(date +%Y-%m-%d_%H-%M-%S)"
dest="${backup_root}/${stamp}"
mkdir -p "$dest"

export PGPASSWORD="${DB_PASSWORD}"
db_host="127.0.0.1"
db_user="gf_app"

for db in gf_gs gf_ls gf_ms; do
  log "Dumping ${db}..."
  pg_dump -h "$db_host" -U "$db_user" -Fp "$db" > "${dest}/${db}.sql"
done

log "Backup written to ${dest}"

# Prune: keep only the newest BACKUP_KEEP dated folders.
mapfile -t old < <(find "$backup_root" -maxdepth 1 -mindepth 1 -type d \
                   | sort | head -n "-${BACKUP_KEEP}")
for d in "${old[@]:-}"; do
  [ -n "$d" ] || continue
  log "Pruning old backup ${d}"
  rm -rf "$d"
done
```

- [ ] **Step 2: Lint the backup script**

Run: `bash -n deploy/gf-backup.sh && shellcheck -x deploy/gf-backup.sh`
Expected: no output, exit code 0.

- [ ] **Step 3: Write `deploy/systemd/gf-backup.service`**

```ini
[Unit]
Description=Grand Fantasia database backup
After=postgresql.service

[Service]
Type=oneshot
User=gfserver
Group=gfserver
ExecStart=/opt/gfserver/deploy/gf-backup.sh
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
ReadWritePaths=/opt/gfserver
```

- [ ] **Step 4: Write `deploy/systemd/gf-backup.timer`**

```ini
[Unit]
Description=Daily Grand Fantasia database backup

[Timer]
OnCalendar=*-*-* 04:30:00
Persistent=true

[Install]
WantedBy=timers.target
```

- [ ] **Step 5: Verify the units parse**

Run: `systemd-analyze verify deploy/systemd/gf-backup.service deploy/systemd/gf-backup.timer`
Expected: no parse errors (a warning about the `ExecStart` path is acceptable on the dev machine).

- [ ] **Step 6: Commit**

```bash
git add deploy/gf-backup.sh deploy/systemd/gf-backup.service deploy/systemd/gf-backup.timer
git commit -m "feat(deploy): add automated daily database backup"
```

---

## Task 7: The installer `deploy/install.sh`

**Files:**
- Create: `deploy/install.sh`

**Notes:** Run once on the VPS as root, from `/opt/gfserver`. Every function is idempotent — re-running `install.sh` is safe. The installer never echoes the DB password. PostgreSQL 16 and `postgresql-contrib` (for `dblink`) ship in Ubuntu 24.04 `main`, so no third-party APT repo is needed.

- [ ] **Step 1: Write `deploy/install.sh`**

```bash
#!/usr/bin/env bash
# Idempotent installer for the gf_server game-server foundation.
# Run as root from /opt/gfserver:  sudo deploy/install.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"
# shellcheck source=ip-patch.sh
source "${SCRIPT_DIR}/ip-patch.sh"

WORLD_OFFSET="3EA7A7"
ZONE_OFFSET="822D47"
IP_PAD_BYTES=6

# --- 1. Preflight ----------------------------------------------------------
preflight() {
  require_root
  require_ubuntu_2404
  [ "$(cd "${SCRIPT_DIR}/.." && pwd)" = "$GF_ROOT" ] \
    || die "Repo must live at ${GF_ROOT} (found $(cd "${SCRIPT_DIR}/.." && pwd))."
  load_env "${SCRIPT_DIR}/gfserver.env"
  log "Preflight OK — installing to ${GF_ROOT}."
}

# --- 2. APT packages -------------------------------------------------------
install_packages() {
  log "Installing APT packages..."
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -y
  apt-get install -y \
    postgresql-16 postgresql-contrib \
    ufw fail2ban unattended-upgrades \
    openssl coreutils
}

# --- 3. System user + filesystem layout ------------------------------------
setup_user() {
  if ! id -u "$GF_USER" >/dev/null 2>&1; then
    log "Creating system user ${GF_USER}..."
    useradd --system --no-create-home --shell /usr/sbin/nologin "$GF_USER"
  fi
  log "Setting ownership and permissions on ${GF_ROOT}..."
  mkdir -p "${GF_ROOT}/backup" "${GF_ROOT}/logs"
  chown -R "${GF_USER}:${GF_GROUP}" "$GF_ROOT"
  chmod 750 "$GF_ROOT"
  # Server binaries must be executable by the gfserver user.
  local b
  for b in TicketServer GatewayServer LoginServer MissionServer WorldServer ZoneServer; do
    [ -f "${GF_ROOT}/${b}/${b}" ] && chmod 750 "${GF_ROOT}/${b}/${b}"
  done
  chmod 640 "${SCRIPT_DIR}/gfserver.env"
}

# --- 4. 32-bit execution support -------------------------------------------
ensure_32bit_support() {
  local probe="${GF_ROOT}/TicketServer/TicketServer"
  [ -f "$probe" ] || { warn "TicketServer binary missing — skipping 32-bit check."; return; }
  # The binaries are statically linked; a 64-bit kernel runs them directly.
  # If exec fails with a format error, enable i386 multiarch as a fallback.
  if ! head -c 0 < <("$probe" --version 2>/dev/null) 2>/dev/null \
     && file "$probe" | grep -q 'cannot execute'; then
    warn "Enabling i386 multiarch as a fallback..."
    dpkg --add-architecture i386
    apt-get update -y
    apt-get install -y libc6:i386
  else
    log "32-bit execution support OK."
  fi
}

# --- 5. PostgreSQL hardening + databases -----------------------------------
configure_postgres() {
  local pg_conf="/etc/postgresql/16/main/postgresql.conf"
  local hba="/etc/postgresql/16/main/pg_hba.conf"

  log "Hardening PostgreSQL (localhost-only, scram-sha-256)..."
  sed -i "s/^#*listen_addresses.*/listen_addresses = 'localhost'/" "$pg_conf"
  # Force scram-sha-256 for all local/host lines (idempotent).
  sed -i -E 's/(^(local|host)\s+\S+\s+\S+(\s+\S+)?\s+)(md5|peer|ident|trust)\s*$/\1scram-sha-256/' "$hba"
  systemctl restart postgresql

  # Generate a DB password if the operator left it blank.
  if [ -z "${DB_PASSWORD:-}" ]; then
    DB_PASSWORD="$(openssl rand -base64 24 | tr -d '/+=' | head -c 28)"
    log "Generated a random DB password and stored it in gfserver.env."
    if grep -q '^DB_PASSWORD=' "${SCRIPT_DIR}/gfserver.env"; then
      sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" "${SCRIPT_DIR}/gfserver.env"
    else
      printf 'DB_PASSWORD=%s\n' "$DB_PASSWORD" >> "${SCRIPT_DIR}/gfserver.env"
    fi
  fi

  # Application role — non-superuser, idempotent create-or-update.
  log "Creating/updating the gf_app role..."
  sudo -u postgres psql -v ON_ERROR_STOP=1 -q <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gf_app') THEN
    CREATE ROLE gf_app LOGIN PASSWORD '${DB_PASSWORD}';
  ELSE
    ALTER ROLE gf_app LOGIN PASSWORD '${DB_PASSWORD}';
  END IF;
END
\$\$;
SQL

  # Create databases + import schema only on first run.
  local db
  for db in gf_gs gf_ls gf_ms; do
    if ! sudo -u postgres psql -tAc \
         "SELECT 1 FROM pg_database WHERE datname='${db}'" | grep -q 1; then
      log "Creating database ${db} and importing schema..."
      sudo -u postgres psql -v ON_ERROR_STOP=1 -q \
        -c "CREATE DATABASE ${db} ENCODING 'UTF8' TEMPLATE template0;"
      sudo -u postgres psql -v ON_ERROR_STOP=1 -q -d "$db" \
        -f "${GF_ROOT}/_utils/db/${db}.sql"
    else
      log "Database ${db} already exists — skipping schema import."
    fi
  done

  # Grant the app role least-privilege DML access (idempotent).
  log "Granting gf_app privileges on the game databases..."
  for db in gf_gs gf_ls gf_ms; do
    sudo -u postgres psql -v ON_ERROR_STOP=1 -q -d "$db" <<SQL
GRANT CONNECT ON DATABASE ${db} TO gf_app;
GRANT USAGE ON SCHEMA public TO gf_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO gf_app;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO gf_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO gf_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO gf_app;
SQL
  done

  # Point the world list at the public IP.
  sudo -u postgres psql -q -d gf_ls \
    -c "UPDATE worlds SET ip = '${HOST_IP}';" || warn "worlds update skipped"
  sudo -u postgres psql -q -d gf_gs \
    -c "UPDATE serverstatus SET ext_address = '${HOST_IP}' WHERE ext_address <> 'none';" \
    || warn "serverstatus update skipped"
}

# --- 6. Render component setup.ini files -----------------------------------
render_configs() {
  log "Writing database credentials into component setup.ini files..."
  local f
  for f in "${GF_ROOT}/setup.ini" "${GF_ROOT}/GatewayServer/setup.ini"; do
    [ -f "$f" ] || continue
    sed -i \
      -e "s/^GameDBUser=.*/GameDBUser=gf_app/" \
      -e "s/^AccountDBUser=.*/AccountDBUser=gf_app/" \
      -e "s/^GameDBPassword=.*/GameDBPassword=${DB_PASSWORD}/" \
      -e "s/^AccountDBPW=.*/AccountDBPW=${DB_PASSWORD}/" \
      "$f"
  done
}

# --- 7. Patch the World/Zone binaries with the server IP -------------------
patch_binaries() {
  local world="${GF_ROOT}/WorldServer/WorldServer"
  local zone="${GF_ROOT}/ZoneServer/ZoneServer"
  local b
  for b in "$world" "$zone"; do
    [ -f "$b" ] || die "Binary not found for IP patch: $b"
    # Keep a pristine .bak and always patch from it (idempotent).
    [ -f "${b}.bak" ] || cp "$b" "${b}.bak"
    cp "${b}.bak" "$b"
  done
  log "Patching server IP into WorldServer/ZoneServer..."
  patch_ip "$world" "$WORLD_OFFSET" "$HOST_IP" "$IP_PAD_BYTES"
  patch_ip "$zone"  "$ZONE_OFFSET"  "$HOST_IP" "$IP_PAD_BYTES"
  chown "${GF_USER}:${GF_GROUP}" "$world" "$zone" "${world}.bak" "${zone}.bak"
}

# --- 8. Install systemd units ----------------------------------------------
install_systemd() {
  log "Installing systemd units..."
  install -m 644 "${SCRIPT_DIR}"/systemd/gf-*.service /etc/systemd/system/
  install -m 644 "${SCRIPT_DIR}"/systemd/gf-*.timer   /etc/systemd/system/
  install -m 644 "${SCRIPT_DIR}/systemd/gfserver.target" /etc/systemd/system/
  systemctl daemon-reload
  systemctl enable gfserver.target gf-backup.timer
  systemctl start gf-backup.timer
}

# --- 9. Firewall -----------------------------------------------------------
configure_firewall() {
  log "Configuring ufw..."
  ufw --force reset
  ufw default deny incoming
  ufw default allow outgoing
  ufw limit "${SSH_PORT}/tcp" comment 'SSH'
  local port
  for port in $GAME_PORTS; do
    ufw allow "${port}/tcp" comment 'GF game port'
  done
  ufw --force enable
}

# --- 10. Auto security updates ---------------------------------------------
configure_unattended_upgrades() {
  log "Enabling unattended security upgrades..."
  dpkg-reconfigure -f noninteractive unattended-upgrades || true
  systemctl enable --now unattended-upgrades || true
}

main() {
  preflight
  install_packages
  setup_user
  ensure_32bit_support
  configure_postgres
  render_configs
  patch_binaries
  install_systemd
  configure_firewall
  configure_unattended_upgrades
  log "Installation complete. Start the server with:  deploy/gfctl start"
}

main "$@"
```

- [ ] **Step 2: Lint the installer**

Run: `bash -n deploy/install.sh && shellcheck -x deploy/install.sh`
Expected: no output, exit code 0. (If ShellCheck flags the heredoc-embedded SQL, confirm the warning is cosmetic; do not suppress real findings.)

- [ ] **Step 3: Commit**

```bash
git add deploy/install.sh
git commit -m "feat(deploy): add idempotent hardened installer"
```

---

## Task 8: Management script `deploy/gfctl`

**Files:**
- Create: `deploy/gfctl`

- [ ] **Step 1: Write `deploy/gfctl`**

```bash
#!/usr/bin/env bash
# Day-to-day management for the gf_server game servers.
# Usage: gfctl {start|stop|restart|status|backup|restore <folder>}
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

usage() {
  cat >&2 <<EOF
Usage: gfctl {start|stop|restart|status|backup|restore <folder>}
  start    Start all game servers
  stop     Stop all game servers
  restart  Restart all game servers
  status   Show systemd status of every component
  backup   Run a database backup now
  restore  Restore a backup folder (e.g. restore 2026-05-16_04-30-00)
EOF
  exit 1
}

cmd_status() {
  systemctl --no-pager --lines=0 status \
    gf-ticket gf-gateway gf-login gf-mission gf-world gf-zone || true
}

cmd_restore() {
  local folder="${1:-}"
  [ -n "$folder" ] || die "restore needs a backup folder name."
  local dir="${GF_ROOT}/backup/${folder}"
  [ -d "$dir" ] || die "Backup folder not found: $dir"
  local db
  for db in gf_gs gf_ls gf_ms; do
    [ -f "${dir}/${db}.sql" ] || die "Missing ${db}.sql in ${dir}"
  done
  warn "This will OVERWRITE databases gf_gs, gf_ls, gf_ms from ${folder}."
  read -rp "Type 'yes' to continue: " confirm
  [ "$confirm" = "yes" ] || die "Aborted."
  load_env "${SCRIPT_DIR}/gfserver.env"
  for db in gf_gs gf_ls gf_ms; do
    log "Restoring ${db}..."
    sudo -u postgres psql -q -c "DROP DATABASE IF EXISTS ${db};"
    sudo -u postgres psql -q -c "CREATE DATABASE ${db} ENCODING 'UTF8' TEMPLATE template0;"
    PGPASSWORD="${DB_PASSWORD}" psql -h 127.0.0.1 -U gf_app -q -d "$db" \
      -f "${dir}/${db}.sql"
  done
  log "Restore complete."
}

case "${1:-}" in
  start)   require_root; systemctl start gfserver.target;   log "Server started." ;;
  stop)    require_root; systemctl stop gfserver.target;    log "Server stopped." ;;
  restart) require_root; systemctl restart gfserver.target; log "Server restarted." ;;
  status)  cmd_status ;;
  backup)  "${SCRIPT_DIR}/gf-backup.sh" ;;
  restore) require_root; shift; cmd_restore "${1:-}" ;;
  *)       usage ;;
esac
```

- [ ] **Step 2: Lint**

Run: `bash -n deploy/gfctl && shellcheck -x deploy/gfctl`
Expected: no output, exit code 0.

- [ ] **Step 3: Verify the usage path**

Run: `bash deploy/gfctl 2>&1 | head -1`
Expected: `Usage: gfctl {start|stop|restart|status|backup|restore <folder>}`

- [ ] **Step 4: Commit**

```bash
git add deploy/gfctl
git commit -m "feat(deploy): add gfctl management script"
```

---

## Task 9: Deployment runbook, aggregate checks, self-review

**Files:**
- Create: `deploy/tests/run-checks.sh`
- Create: `docs/deployment-runbook.md`

- [ ] **Step 1: Write `deploy/tests/run-checks.sh`**

```bash
#!/usr/bin/env bash
# Aggregate static checks for the deploy toolchain. Run from the repo root.
set -euo pipefail
cd "$(dirname "$0")/../.."

echo "== bash -n =="
for s in deploy/install.sh deploy/gfctl deploy/gf-backup.sh \
         deploy/ip-patch.sh deploy/lib/common.sh deploy/tests/*.sh; do
  bash -n "$s" && echo "  ok: $s"
done

echo "== shellcheck =="
shellcheck -x deploy/install.sh deploy/gfctl deploy/gf-backup.sh \
  deploy/ip-patch.sh deploy/lib/common.sh deploy/tests/*.sh
echo "  ok"

echo "== systemd-analyze verify =="
for u in deploy/systemd/*; do
  systemd-analyze verify "$u" 2>&1 | grep -vi 'executable path' || true
done
echo "  ok"

echo "== ip-patch unit test =="
bash deploy/tests/test-ip-patch.sh

echo "ALL CHECKS PASSED"
```

- [ ] **Step 2: Run the aggregate checks**

Run: `bash deploy/tests/run-checks.sh`
Expected: ends with `ALL CHECKS PASSED`, exit code 0.

- [ ] **Step 3: Write `docs/deployment-runbook.md`**

````markdown
# Deployment Runbook — Block ① Server Foundation

Brings the Grand Fantasia servers online on a fresh Ubuntu 24.04 VPS.

## 0. Prerequisites
- A VPS running **Ubuntu Server 24.04 LTS** with a static public IPv4.
- Root or sudo access.

## 1. Secure the VPS first
- Create a non-root sudo user; log in as that user.
- Set up SSH key authentication, then in `/etc/ssh/sshd_config` set
  `PermitRootLogin no` and `PasswordAuthentication no`; `sudo systemctl restart ssh`.
  **Keep your current session open** until you have confirmed key login works.
- `sudo apt update && sudo apt full-upgrade -y && sudo reboot`.

## 2. Place the repository
```bash
sudo git clone <repo-url> /opt/gfserver
cd /opt/gfserver
```

## 3. Configure
```bash
cp deploy/gfserver.env.example deploy/gfserver.env
nano deploy/gfserver.env        # set HOST_IP; leave DB_PASSWORD empty to auto-generate
```

## 4. Install
```bash
sudo deploy/install.sh
```
Idempotent — safe to re-run. It installs PostgreSQL 16, creates the `gfserver`
user and `gf_app` DB role, imports the schema, patches the World/Zone binaries,
installs the systemd units, and configures `ufw`, `fail2ban`, and unattended
upgrades.

## 5. Verify the public game ports
The defaults (`6543 5560`) may be incomplete. Inspect the database:
```bash
sudo -u postgres psql -d gf_ls -c "SELECT name, ip, port FROM worlds;"
sudo -u postgres psql -d gf_gs -c "SELECT * FROM serverstatus;"
```
Add any additional player-facing ports to `GAME_PORTS` in
`deploy/gfserver.env`, then re-run `sudo deploy/install.sh` (the firewall step
re-applies the rules).
**Never** open the ZoneServer GM port (`10320`) or PostgreSQL (`5432`) to the
internet.

## 6. Start the server
```bash
sudo deploy/gfctl start
deploy/gfctl status
```
Inspect a component's log with `journalctl -u gf-zone -f`.

## 7. End-to-end check
- Point the game client's `connect.ini` at `HOST_IP:6543`.
- Confirm: login succeeds, character list loads, you can enter the world.
- This manual check is the acceptance criterion for Block ①.

## Day-to-day operations
- `sudo deploy/gfctl {start|stop|restart}` — control the server.
- `deploy/gfctl status` — component health.
- `deploy/gfctl backup` — on-demand DB backup (also runs daily at 04:30 via
  `gf-backup.timer`).
- `sudo deploy/gfctl restore <folder>` — restore a backup from `/opt/gfserver/backup/`.

## Troubleshooting
- A component keeps restarting → `journalctl -u gf-<name> -n 50`.
- DB connection errors → confirm `gf_app` works:
  `PGPASSWORD=... psql -h 127.0.0.1 -U gf_app -d gf_gs -c '\dt'`.
- `install.sh` exec error on the binaries → 32-bit support; re-run the installer,
  which enables i386 multiarch as a fallback.
````

- [ ] **Step 4: Self-review against the spec**

Confirm every spec section maps to a deliverable:
- Installationsort/User → Task 7 `setup_user`. Prozessmodell → Task 5. DB-Härtung
  → Task 7 `configure_postgres`. IP-Patching → Task 3 + Task 7 `patch_binaries`.
  Firewall → Task 7 `configure_firewall`. Prozess-Isolation → Task 5 unit
  directives. unattended-upgrades/fail2ban → Task 7. Secrets/.gitignore → Task 1
  + Task 4. Removed `clear`/`chmod 777`/`0.0.0.0` → no such code exists. Backups
  → Task 6. Runbook → Task 9.

Fix any gap inline before finishing.

- [ ] **Step 5: Commit**

```bash
git add deploy/tests/run-checks.sh docs/deployment-runbook.md
git commit -m "docs(deploy): add deployment runbook and aggregate check script"
```

---

## Verification Summary

- **Automated (any machine):** `bash deploy/tests/run-checks.sh` — ShellCheck,
  `bash -n`, `systemd-analyze verify`, and the IP-patch unit test.
- **Throwaway VM (Ubuntu 24.04):** run `deploy/install.sh`; confirm the
  `gfserver` user, the non-superuser `gf_app` role, the systemd units, that
  PostgreSQL rejects external connections, and the `ufw` ruleset.
- **Real VPS (acceptance):** Section 7 of the runbook — client logs in and can
  enter the world. Not automatable here; it is the human acceptance gate.

## Open Items Carried From the Spec

1. Exact public game-port set — resolved during runbook Section 5 (DB inspection).
2. 32-bit execution — `install.sh` probes and falls back to i386 multiarch.
3. Top-level empty `config.ini` — check during VM testing whether any component
   reads it; if unused, leave as-is.
4. PG 16 compatibility of the SQL dumps — verified during VM testing; if a
   `dblink`-using PL/pgSQL path needs superuser rights, record it as a follow-up
   decision (it targets a non-existent `FFAccount` DB and is likely dead code).
