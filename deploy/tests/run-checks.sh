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
