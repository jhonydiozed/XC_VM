#!/usr/bin/env python3
"""Read-only XD Stream installation preflight for Ubuntu 20.04+ and Debian 11+."""

from __future__ import annotations

import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
from typing import Dict, List, Tuple

SUPPORTED = {
    "ubuntu": {"20.04", "22.04", "24.04"},
    "debian": {"11", "12", "13"},
}
REQUIRED_COMMANDS = ["bash", "curl", "tar", "sha256sum", "systemctl", "ip", "ss", "python3"]
REQUIRED_PORTS = [80, 443, 3306]
MIN_MEMORY_GIB = 4
MIN_DISK_GIB = 20


def read_os_release() -> Dict[str, str]:
    result: Dict[str, str] = {}
    for line in Path("/etc/os-release").read_text(encoding="utf-8").splitlines():
        if "=" not in line:
            continue
        key, value = line.split("=", 1)
        result[key] = value.strip().strip('"')
    return result


def command_output(args: List[str]) -> Tuple[int, str]:
    try:
        completed = subprocess.run(args, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, check=False)
        return completed.returncode, completed.stdout.strip()
    except OSError as exc:
        return 127, str(exc)


def port_in_use(port: int) -> bool:
    code, output = command_output(["ss", "-lntH", f"sport = :{port}"])
    return code == 0 and bool(output.strip())


def main() -> int:
    checks = []

    try:
        osr = read_os_release()
    except OSError as exc:
        print(json.dumps({"ok": False, "error": str(exc)}, indent=2))
        return 2

    distro = osr.get("ID", "").lower()
    version = osr.get("VERSION_ID", "")
    supported = distro in SUPPORTED and version in SUPPORTED[distro]
    checks.append({"name": "operating_system", "ok": supported, "detail": f"{distro} {version}"})

    checks.append({"name": "root", "ok": os.geteuid() == 0, "detail": f"euid={os.geteuid()}"})

    for command in REQUIRED_COMMANDS:
        path = shutil.which(command)
        checks.append({"name": f"command:{command}", "ok": path is not None, "detail": path or "missing"})

    memory_kib = 0
    try:
        for line in Path("/proc/meminfo").read_text(encoding="utf-8").splitlines():
            if line.startswith("MemTotal:"):
                memory_kib = int(line.split()[1])
                break
    except (OSError, ValueError):
        pass
    memory_gib = memory_kib / 1024 / 1024
    checks.append({"name": "memory", "ok": memory_gib >= MIN_MEMORY_GIB, "detail": f"{memory_gib:.1f} GiB"})

    disk = shutil.disk_usage("/")
    free_gib = disk.free / 1024 / 1024 / 1024
    checks.append({"name": "disk_free_root", "ok": free_gib >= MIN_DISK_GIB, "detail": f"{free_gib:.1f} GiB"})

    try:
        socket.getaddrinfo("github.com", 443)
        dns_ok = True
    except socket.gaierror:
        dns_ok = False
    checks.append({"name": "dns_github", "ok": dns_ok, "detail": "github.com"})

    for port in REQUIRED_PORTS:
        used = port_in_use(port)
        checks.append({"name": f"port:{port}", "ok": not used, "detail": "available" if not used else "already listening"})

    existing = Path("/home/xc_vm").exists()
    checks.append({"name": "clean_target", "ok": not existing, "detail": "/home/xc_vm exists" if existing else "available"})

    failed = [check for check in checks if not check["ok"]]
    result = {
        "ok": not failed,
        "platform": {"distribution": distro, "version": version},
        "checks": checks,
        "failed": [check["name"] for check in failed],
    }
    print(json.dumps(result, indent=2))
    return 0 if not failed else 1


if __name__ == "__main__":
    sys.exit(main())
