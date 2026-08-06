#!/usr/bin/env python3
"""Convert the XC_VM distribution into the XD Stream (XDS) runtime layout.

The converter operates in two stages:

1. Source conversion: rewrites the installer and repository text files before the
   installer starts.
2. Runtime conversion: rewrites the application tree extracted from the upstream
   archive before any XDS service is started.

Upstream license, attribution and download coordinates are preserved. The runtime
identity becomes:

- home: /home/xds
- service: xds.service
- Linux user/group: xds
- primary database: xds
- migration database: xds_migrate

Examples:
    python3 xd/scripts/rebrand-xds.py --check
    python3 xd/scripts/rebrand-xds.py --apply
    python3 xd/scripts/rebrand-xds.py --runtime-root /home/xds --apply-runtime
    python3 xd/scripts/rebrand-xds.py --verify --root /home/xds
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import sys
from typing import Dict, Iterable, List, Sequence, Tuple

REPO_ROOT = Path(__file__).resolve().parents[2]
REPORT_PATH = REPO_ROOT / "xd" / "rebrand-report.json"

EXCLUDED_SOURCE_FILES = {
    "LICENSE",
    "CONTRIBUTORS.md",
    "CONTRIBUTING.md",
    "README.md",
    "CLAUDE.md",
}
EXCLUDED_SOURCE_PREFIXES = (
    ".git/",
    "docs/xd/",
    "xd/scripts/rebrand-xds.py",
)

TEXT_EXTENSIONS = {
    "", ".php", ".py", ".sh", ".conf", ".ini", ".json", ".md", ".txt",
    ".service", ".sql", ".yml", ".yaml", ".xml", ".html", ".css", ".js",
    ".ts", ".vue", ".twig", ".env", ".example", ".dist", ".neon", ".lock",
    ".inc", ".tpl", ".crt", ".key",
}

# Ordered from most specific to least specific.
REPLACEMENTS: List[Tuple[str, str]] = [
    ("/home/xc_vm", "/home/xds"),
    ("/etc/xc_vm", "/etc/xds"),
    ("/var/log/xc_vm", "/var/log/xds"),
    ("/var/lib/xc_vm", "/var/lib/xds"),
    ("/run/xc_vm", "/run/xds"),
    ("xc_vm.service", "xds.service"),
    ("XC_VM Service", "XDS Service"),
    ("XC_VM Configuration", "XDS Configuration"),
    ("XC_VM Multi-Distribution Installer", "XDS Multi-Distribution Installer"),
    ("XC_VM-Binaries-Updater", "XDS-Binaries-Updater"),
    ("XC_VM-Installer", "XDS-Installer"),
    ("xc_vm_migrate", "xds_migrate"),
    ("xc_vm", "xds"),
    ("XC VM", "XD Stream"),
]

# These coordinates remain upstream until XDS publishes its own signed source and
# binary release repositories. Preserving them does not preserve runtime paths.
PROTECTED_PATTERNS = (
    "Vateron-Media/XC_VM_Binaries",
    "Vateron-Media/XC_VM",
    "github.com/Vateron-Media",
    "api.github.com/repos/Vateron-Media",
    "XC_VM_Binaries",
    "XC_VM.zip",
)

# Operational references that must not remain after conversion. Historical,
# licensing and upstream download references are excluded from this validation.
FORBIDDEN_OPERATIONAL = (
    "/home/xc_vm",
    "/etc/xc_vm",
    "/var/log/xc_vm",
    "/var/lib/xc_vm",
    "/run/xc_vm",
    "xc_vm.service",
    "getent passwd xc_vm",
    "chown xc_vm:",
    "chown -R xc_vm:",
    "sudo -u xc_vm",
    "useradd -r -g xc_vm",
    "groupadd -r xc_vm",
    "adduser --system --shell /bin/false --no-create-home --home /nonexistent --group --disabled-login xc_vm",
    'database    =   "xc_vm"',
    'USE xc_vm;',
    'CREATE DATABASE IF NOT EXISTS xc_vm;',
)

INSTALLER_HOOK_MARKER = "# XDS_RUNTIME_REBRAND_HOOK"
INSTALLER_ANCHOR = """    else:\n        printc(\"XC_VM extracted successfully\", col.OKGREEN)\n\n    # Replace the shipped placeholder cert"""
INSTALLER_ANCHOR_REBRANDED = """    else:\n        printc(\"XDS extracted successfully\", col.OKGREEN)\n\n    # Replace the shipped placeholder cert"""
INSTALLER_HOOK = """    else:\n        printc(\"XDS extracted successfully\", col.OKGREEN)\n\n    # XDS_RUNTIME_REBRAND_HOOK\n    # The release archive is still produced upstream. Convert its extracted text\n    # tree before certificates, binaries, services, cron jobs or PHP workers start.\n    rebrand_tool = os.path.join(rPath, \"xd/scripts/rebrand-xds.py\")\n    if not os.path.exists(rebrand_tool):\n        printc(\"XDS runtime converter not found: \" + rebrand_tool, col.FAIL)\n        sys.exit(1)\n    rc, _, err = run_command(\n        f'{sys.executable} \"{rebrand_tool}\" --runtime-root /home/xds --apply-runtime',\n        capture_output=True,\n    )\n    if rc != 0:\n        printc(\"XDS runtime conversion failed: \" + str(err), col.FAIL)\n        sys.exit(1)\n    printc(\"Extracted runtime converted to /home/xds\", col.OKGREEN)\n\n    # Replace the shipped placeholder cert"""


def is_text_candidate(path: Path) -> bool:
    if not path.is_file() or path.is_symlink():
        return False
    if path.suffix.lower() not in TEXT_EXTENSIONS and path.name not in {
        "install", "install-xds", "service", "Makefile"
    }:
        return False
    try:
        return b"\x00" not in path.read_bytes()[:4096]
    except OSError:
        return False


def source_candidate(path: Path) -> bool:
    if not is_text_candidate(path):
        return False
    rel = path.relative_to(REPO_ROOT).as_posix()
    if rel in EXCLUDED_SOURCE_FILES:
        return False
    return not any(rel.startswith(prefix) for prefix in EXCLUDED_SOURCE_PREFIXES)


def protect_upstream(text: str) -> Tuple[str, Dict[str, str]]:
    placeholders: Dict[str, str] = {}
    for index, value in enumerate(PROTECTED_PATTERNS):
        token = "__XDS_UPSTREAM_PROTECTED_%d__" % index
        if value in text:
            text = text.replace(value, token)
            placeholders[token] = value
    return text, placeholders


def restore_upstream(text: str, placeholders: Dict[str, str]) -> str:
    for token, value in placeholders.items():
        text = text.replace(token, value)
    return text


def patch_installer_hook(text: str, relative_name: str) -> str:
    if relative_name != "install" or INSTALLER_HOOK_MARKER in text:
        return text
    if INSTALLER_ANCHOR_REBRANDED in text:
        return text.replace(INSTALLER_ANCHOR_REBRANDED, INSTALLER_HOOK, 1)
    if INSTALLER_ANCHOR in text:
        return text.replace(INSTALLER_ANCHOR, INSTALLER_HOOK, 1)
    raise RuntimeError("installer extraction anchor not found; refusing incomplete XDS conversion")


def transform(text: str, relative_name: str = "") -> str:
    protected, placeholders = protect_upstream(text)
    for source, destination in REPLACEMENTS:
        protected = protected.replace(source, destination)
    updated = restore_upstream(protected, placeholders)
    return patch_installer_hook(updated, relative_name)


def scan(root: Path, source_mode: bool) -> Iterable[Path]:
    for path in root.rglob("*"):
        if source_mode:
            if source_candidate(path):
                yield path
        elif is_text_candidate(path):
            yield path


def convert_tree(root: Path, apply_changes: bool, source_mode: bool) -> Dict[str, object]:
    changed: List[str] = []
    errors: List[str] = []
    replacements = 0

    for path in scan(root, source_mode):
        rel = path.relative_to(root).as_posix()
        try:
            original = path.read_text(encoding="utf-8")
            updated = transform(original, rel if source_mode else "")
        except (UnicodeDecodeError, OSError, RuntimeError) as exc:
            errors.append("%s: %s" % (rel, exc))
            continue

        if updated == original:
            continue

        replacements += sum(original.count(source) for source, _ in REPLACEMENTS)
        changed.append(rel)
        if apply_changes:
            path.write_text(updated, encoding="utf-8")

    return {
        "root": str(root),
        "files_changed": changed,
        "file_count": len(changed),
        "replacement_count": replacements,
        "errors": errors,
    }


def verify_tree(root: Path, source_mode: bool) -> Dict[str, object]:
    findings: List[Dict[str, object]] = []
    for path in scan(root, source_mode):
        rel = path.relative_to(root).as_posix()
        # Preserve explicit provenance documents and the converter's own mapping.
        if source_mode and (
            rel in EXCLUDED_SOURCE_FILES
            or rel.startswith("docs/xd/")
            or rel == "xd/scripts/rebrand-xds.py"
        ):
            continue
        try:
            lines = path.read_text(encoding="utf-8").splitlines()
        except (UnicodeDecodeError, OSError):
            continue
        for number, line in enumerate(lines, 1):
            # Upstream repository coordinates and binary metadata are permitted.
            if any(value in line for value in PROTECTED_PATTERNS):
                continue
            for token in FORBIDDEN_OPERATIONAL:
                if token in line:
                    findings.append({"file": rel, "line": number, "token": token})

    return {
        "root": str(root),
        "valid": not findings,
        "finding_count": len(findings),
        "findings": findings,
    }


def write_report(payload: Dict[str, object]) -> None:
    REPORT_PATH.parent.mkdir(parents=True, exist_ok=True)
    REPORT_PATH.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--check", action="store_true", help="show source files that would change")
    mode.add_argument("--apply", action="store_true", help="convert the repository source tree")
    mode.add_argument("--apply-runtime", action="store_true", help="convert an extracted runtime tree")
    mode.add_argument("--verify", action="store_true", help="fail on residual operational XC_VM paths")
    parser.add_argument("--runtime-root", type=Path, help="runtime tree, normally /home/xds")
    parser.add_argument("--root", type=Path, help="tree to verify")
    args = parser.parse_args()

    if args.apply_runtime:
        if not args.runtime_root:
            parser.error("--apply-runtime requires --runtime-root")
        root = args.runtime_root.resolve()
        if not root.is_dir():
            print("runtime root does not exist: %s" % root, file=sys.stderr)
            return 2
        converted = convert_tree(root, True, False)
        verified = verify_tree(root, False)
        payload = {
            "mode": "apply-runtime",
            "brand": "XD Stream",
            "runtime_home": "/home/xds",
            "service": "xds.service",
            "database": "xds",
            "conversion": converted,
            "verification": verified,
        }
        write_report(payload)
        print(json.dumps(payload, indent=2))
        return 0 if not converted["errors"] and verified["valid"] else 2

    if args.verify:
        root = (args.root or REPO_ROOT).resolve()
        source_mode = root == REPO_ROOT
        payload = {"mode": "verify", "verification": verify_tree(root, source_mode)}
        write_report(payload)
        print(json.dumps(payload, indent=2))
        return 0 if payload["verification"]["valid"] else 3

    converted = convert_tree(REPO_ROOT, args.apply, True)
    verification = verify_tree(REPO_ROOT, True) if args.apply else None
    payload = {
        "mode": "apply" if args.apply else "check",
        "brand": "XD Stream",
        "short_name": "XDS",
        "runtime_home": "/home/xds",
        "service": "xds.service",
        "linux_user": "xds",
        "linux_group": "xds",
        "database": "xds",
        "migration_database": "xds_migrate",
        "conversion": converted,
        "verification": verification,
        "upstream_coordinates_preserved": list(PROTECTED_PATTERNS),
    }
    write_report(payload)
    print(json.dumps(payload, indent=2))

    if converted["errors"]:
        return 2
    if verification and not verification["valid"]:
        return 3
    return 0


if __name__ == "__main__":
    sys.exit(main())
