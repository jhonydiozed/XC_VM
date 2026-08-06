#!/usr/bin/env bash
set -euo pipefail

UNIT="${1:-xc_vm.service}"
FAILURES=0
WARNINGS=0

ok() { printf '[ OK ] %s\n' "$*"; }
warn() { printf '[WARN] %s\n' "$*"; WARNINGS=$((WARNINGS + 1)); }
fail() { printf '[FAIL] %s\n' "$*"; FAILURES=$((FAILURES + 1)); }

command -v systemctl >/dev/null 2>&1 || { echo "systemctl not found" >&2; exit 2; }

if ! systemctl cat "$UNIT" >/dev/null 2>&1; then
    fail "unit not found: $UNIT"
    exit 1
fi

UNIT_TEXT="$(systemctl cat "$UNIT")"
SECURITY_TEXT="$(systemd-analyze security "$UNIT" 2>/dev/null || true)"

printf '%s\n' "=== Unit ===" "$UNIT_TEXT"
printf '%s\n' "=== Security analysis ===" "$SECURITY_TEXT"

if grep -Eq '^[[:space:]]*User=root([[:space:]]|$)' <<<"$UNIT_TEXT"; then
    warn "service runs as root; retain only while privileged startup is required"
else
    ok "service does not explicitly run as root"
fi

if grep -Eq '^[[:space:]]*ExecReload=.*restart' <<<"$UNIT_TEXT"; then
    warn "ExecReload performs a restart instead of a configuration reload"
else
    ok "ExecReload does not map directly to restart"
fi

for directive in 'NoNewPrivileges=yes' 'PrivateTmp=yes' 'ProtectSystem=' 'ProtectHome=' 'RestrictSUIDSGID=yes'; do
    key="${directive%%=*}"
    if grep -Eq "^[[:space:]]*${key}=" <<<"$UNIT_TEXT"; then
        ok "directive present: $key"
    else
        warn "directive absent: $key"
    fi
done

if grep -Eq '^[[:space:]]*LimitNOFILE=([7-9][0-9]{6,}|[1-9][0-9]{7,})' <<<"$UNIT_TEXT"; then
    warn "LimitNOFILE is unusually high; validate against measured concurrency"
else
    ok "LimitNOFILE is within the audit threshold"
fi

printf '\nSummary: failures=%d warnings=%d\n' "$FAILURES" "$WARNINGS"
(( FAILURES == 0 ))
