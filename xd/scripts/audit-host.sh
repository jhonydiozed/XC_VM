#!/usr/bin/env bash
set -Eeuo pipefail

# XD Stream host audit
# Collects read-only system evidence. It does not change services, firewall,
# packages, users, permissions, or application data.

OUTPUT_DIR="${1:-./xd-audit-$(date -u +%Y%m%dT%H%M%SZ)}"
mkdir -p "$OUTPUT_DIR"
chmod 700 "$OUTPUT_DIR"

run_capture() {
    local output_file="$1"
    shift

    {
        printf '$'
        printf ' %q' "$@"
        printf '\n\n'
        "$@"
    } >"$OUTPUT_DIR/$output_file" 2>&1 || true
}

capture_shell() {
    local output_file="$1"
    local command="$2"

    {
        printf '$ %s\n\n' "$command"
        bash -o pipefail -c "$command"
    } >"$OUTPUT_DIR/$output_file" 2>&1 || true
}

{
    echo "XD Stream host audit"
    echo "UTC timestamp: $(date -u --iso-8601=seconds)"
    echo "Hostname: $(hostname 2>/dev/null || true)"
    echo "Kernel: $(uname -a 2>/dev/null || true)"
    echo "Effective UID: ${EUID}"
} >"$OUTPUT_DIR/summary.txt"

if [[ -r /etc/os-release ]]; then
    cp /etc/os-release "$OUTPUT_DIR/os-release.txt"
fi

run_capture uname.txt uname -a
run_capture uptime.txt uptime
run_capture filesystems.txt df -hT
run_capture memory.txt free -h
run_capture mounts.txt findmnt
run_capture block-devices.txt lsblk -o NAME,TYPE,SIZE,FSTYPE,MOUNTPOINTS,MODEL,SERIAL
run_capture users.txt getent passwd
run_capture groups.txt getent group
run_capture listening-ports.txt ss -lntup
run_capture sockets.txt ss -ntup
run_capture processes.txt ps auxf
run_capture systemd-services.txt systemctl list-units --type=service --all --no-pager
run_capture systemd-timers.txt systemctl list-timers --all --no-pager
run_capture failed-services.txt systemctl --failed --no-pager
run_capture enabled-services.txt systemctl list-unit-files --type=service --state=enabled --no-pager
run_capture cron-root.txt crontab -l
run_capture ip-addresses.txt ip address show
run_capture ip-routes.txt ip route show table all
run_capture ip-rules.txt ip rule show
run_capture resolv-conf.txt cat /etc/resolv.conf
run_capture hosts.txt cat /etc/hosts
run_capture sysctl.txt sysctl -a

if command -v dpkg-query >/dev/null 2>&1; then
    capture_shell packages.txt "dpkg-query -W -f='\${binary:Package}\t\${Version}\n' | sort"
fi

if command -v apt-cache >/dev/null 2>&1; then
    run_capture apt-policy.txt apt-cache policy
fi

if command -v iptables-save >/dev/null 2>&1; then
    run_capture iptables.txt iptables-save
fi

if command -v ip6tables-save >/dev/null 2>&1; then
    run_capture ip6tables.txt ip6tables-save
fi

if command -v nft >/dev/null 2>&1; then
    run_capture nftables.txt nft list ruleset
fi

if command -v ufw >/dev/null 2>&1; then
    run_capture ufw.txt ufw status verbose
fi

if command -v mariadb >/dev/null 2>&1; then
    run_capture mariadb-version.txt mariadb --version
elif command -v mysql >/dev/null 2>&1; then
    run_capture mariadb-version.txt mysql --version
fi

for binary in \
    /home/xc_vm/bin/php/bin/php \
    /home/xc_vm/bin/nginx/sbin/nginx \
    /home/xc_vm/bin/ffmpeg/bin/ffmpeg \
    /home/xc_vm/bin/keydb/bin/keydb-server; do
    if [[ -e "$binary" ]]; then
        safe_name="$(printf '%s' "$binary" | sed 's#^/##; s#[^A-Za-z0-9._-]#_#g')"
        run_capture "file-${safe_name}.txt" file "$binary"
        run_capture "sha256-${safe_name}.txt" sha256sum "$binary"
        if command -v ldd >/dev/null 2>&1; then
            run_capture "ldd-${safe_name}.txt" ldd "$binary"
        fi
    fi
done

capture_shell xc-vm-files.txt "if [ -d /home/xc_vm ]; then find /home/xc_vm -xdev -printf '%M %u %g %s %TY-%Tm-%TdT%TH:%TM:%TS %p\n' | sort; fi"
capture_shell etc-recent.txt "find /etc -xdev -type f -mtime -7 -printf '%TY-%Tm-%TdT%TH:%TM:%TS %u %g %m %p\n' 2>/dev/null | sort"
capture_shell root-authorized-keys-metadata.txt "if [ -e /root/.ssh/authorized_keys ]; then stat /root/.ssh/authorized_keys; sha256sum /root/.ssh/authorized_keys; else echo 'not present'; fi"
capture_shell sudoers-metadata.txt "find /etc/sudoers /etc/sudoers.d -maxdepth 1 -type f -exec stat -c '%n %U %G %a %y' {} + 2>/dev/null | sort"

if command -v sha256sum >/dev/null 2>&1; then
    (
        cd "$OUTPUT_DIR"
        find . -maxdepth 1 -type f ! -name SHA256SUMS -print0 \
            | sort -z \
            | xargs -0 sha256sum >SHA256SUMS
    )
fi

printf 'Audit written to: %s\n' "$OUTPUT_DIR"
printf 'This script collected read-only evidence and made no system changes.\n'
