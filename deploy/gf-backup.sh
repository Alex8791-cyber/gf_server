#!/usr/bin/env bash
# Dump the three game databases to a dated folder and prune old backups.
# Runs as root (via gf-backup.service) and dumps as the postgres DB superuser
# through `runuser` -- PostgreSQL peer authentication, no password needed.
# Invoked by gf-backup.timer and by `gfctl backup`.
set -euo pipefail

GF_ROOT="${GF_ROOT:-/opt/gfserver}"
BACKUP_KEEP="${BACKUP_KEEP:-14}"

log() { printf '[gf-backup] %s
' "$*"; }

backup_root="${GF_ROOT}/backup"
stamp="$(date +%Y-%m-%d_%H-%M-%S)"
dest="${backup_root}/${stamp}"
mkdir -p "$dest"

for db in gf_gs gf_ls gf_ms; do
  log "Dumping ${db}..."
  runuser -u postgres -- pg_dump -Fp "$db" > "${dest}/${db}.sql"
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
