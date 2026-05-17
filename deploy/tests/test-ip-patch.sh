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
