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
  # A non-empty operator-supplied password must avoid characters that would
  # break the sed/SQL substitutions below.
  if [ -n "${DB_PASSWORD:-}" ] && printf '%s' "$DB_PASSWORD" | grep -q '[^A-Za-z0-9._-]'; then
    die "DB_PASSWORD may only contain A-Z a-z 0-9 . _ - (edit gfserver.env)."
  fi
  if [ -n "${WEB_DB_PASSWORD:-}" ] && printf '%s' "$WEB_DB_PASSWORD" | grep -q '[^A-Za-z0-9._-]'; then
    die "WEB_DB_PASSWORD may only contain A-Z a-z 0-9 . _ - (edit gfserver.env)."
  fi
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
    openssl coreutils file
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
  # The binaries are statically linked 32-bit ELF; a 64-bit kernel runs them
  # directly. We only report the architecture here — if a component later
  # fails with an exec/format error, the runbook documents the i386 fallback.
  if file "$probe" | grep -q 'ELF 32-bit'; then
    log "Server binaries are 32-bit ELF (statically linked) — OK on a 64-bit kernel."
    log "On an exec/format error, see the 32-bit note in docs/deployment-runbook.md."
  else
    log "32-bit check: binary architecture not recognised — review manually."
  fi
}

# --- 5. PostgreSQL hardening + databases -----------------------------------
configure_postgres() {
  local pg_conf="/etc/postgresql/16/main/postgresql.conf"

  log "Hardening PostgreSQL (localhost-only)..."
  sed -i "s/^#*listen_addresses.*/listen_addresses = 'localhost'/" "$pg_conf"
  # pg_hba.conf is left at the PostgreSQL 16 default: TCP (host) connections
  # use scram-sha-256 and local socket connections use peer auth — both are
  # what we want. Rewriting the local 'peer' lines would break the
  # `sudo -u postgres psql` calls this installer relies on.
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
env[PORTAL_DOMAIN] = ${PORTAL_DOMAIN:-localhost}
POOL
  # The pool file holds the gf_web DB password — keep it off world-read.
  chmod 640 "/etc/php/${php_ver}/fpm/pool.d/gfserver.conf"
  systemctl restart "php${php_ver}-fpm"

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
        SetHandler "proxy:unix:${pool_sock}|fcgi://localhost"
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/gfserver-error.log
    CustomLog \${APACHE_LOG_DIR}/gfserver-access.log combined
</VirtualHost>
APACHE

  a2enmod proxy_fcgi setenvif >/dev/null
  a2dissite 000-default >/dev/null 2>&1 || true
  a2ensite gfserver >/dev/null
  systemctl restart apache2
  log "Web server ready. Run 'certbot --apache' once DNS points at this host (see runbook)."
}

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
    mv /tmp/phpbb-extract/phpBB3 "$forum_dir"
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

# --- 6. Render component setup.ini files -----------------------------------
render_configs() {
  log "Writing database credentials into component setup.ini files..."
  # Only the top-level setup.ini and GatewayServer/setup.ini carry DB
  # credential fields; the other components' setup.ini files hold none.
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
  # Apply the operator's backup retention setting to the installed unit.
  sed -i "s/^Environment=BACKUP_KEEP=.*/Environment=BACKUP_KEEP=${BACKUP_KEEP:-14}/" \
    /etc/systemd/system/gf-backup.service
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
  ufw allow 80/tcp comment 'HTTP'
  ufw allow 443/tcp comment 'HTTPS'
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
  setup_web_role
  run_migrations
  bootstrap_admin
  setup_web_server
  setup_forum
  render_configs
  patch_binaries
  install_systemd
  configure_firewall
  configure_unattended_upgrades
  log "Installation complete. Start the server with:  deploy/gfctl start"
}

main "$@"