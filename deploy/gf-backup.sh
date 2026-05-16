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
