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
