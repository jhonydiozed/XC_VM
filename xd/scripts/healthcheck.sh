#!/usr/bin/env bash
set -euo pipefail

SERVICE_NAME="${XD_SERVICE_NAME:-xc_vm}"
ROOT="${XD_ROOT:-/home/xc_vm}"
FAILURES=0

check() {
    local name="$1"
    shift
    if "$@" >/dev/null 2>&1; then
        printf '[ OK ] %s\n' "$name"
    else
        printf '[FAIL] %s\n' "$name"
        FAILURES=$((FAILURES + 1))
    fi
}

check_file_exec() {
    [[ -x "$1" ]]
}

check_file_read() {
    [[ -r "$1" ]]
}

check "systemd service active" systemctl is-active --quiet "$SERVICE_NAME"
check "systemd service enabled" systemctl is-enabled --quiet "$SERVICE_NAME"
check "PHP runtime" check_file_exec "$ROOT/bin/php/bin/php"
check "PHP-FPM runtime" check_file_exec "$ROOT/bin/php/sbin/php-fpm"
check "Nginx runtime" check_file_exec "$ROOT/bin/nginx/sbin/nginx"
check "application config" check_file_read "$ROOT/config/config.ini"
check "binary version metadata" check_file_read "$ROOT/bin/bin_version.json"

if [[ -x "$ROOT/bin/php/bin/php" ]]; then
    check "PHP executes" "$ROOT/bin/php/bin/php" -v
fi
if [[ -x "$ROOT/bin/nginx/sbin/nginx" ]]; then
    check "Nginx configuration" "$ROOT/bin/nginx/sbin/nginx" -t
fi

check "port 80 listening" bash -c "ss -lntH 'sport = :80' | grep -q ."
check "MariaDB reachable" bash -c "ss -lntH 'sport = :3306' | grep -q ."

printf '\nFailures: %d\n' "$FAILURES"
exit "$FAILURES"
