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
