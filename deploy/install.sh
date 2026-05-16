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