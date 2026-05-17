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
