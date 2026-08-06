#!/usr/bin/env python3
"""Apply the XD Stream (XDS) runtime rebrand to a clean source checkout.

This tool changes runtime identifiers, paths, service names, database names, and
operator-facing branding while deliberately preserving upstream copyright,
license, contributor, and provenance records.

Run from the repository root:
    python3 xd/scripts/rebrand-xds.py --check
    python3 xd/scripts/rebrand-xds.py --apply

The operation is idempotent and writes a JSON report to xd/rebrand-report.json.
"""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import sys
from typing import Dict, Iterable, List, Tuple

ROOT = Path(__file__).resolve().parents[2]
REPORT_PATH = ROOT / "xd" / "rebrand-report.json"

# Files that must retain upstream license/provenance terminology.
EXCLUDED_FILES = {
    "LICENSE",
    "CONTRIBUTORS.md",
    "CONTRIBUTING.md",
    "README.md",
    "CLAUDE.md",
}
EXCLUDED_PREFIXES = (
    ".git/",
    "docs/xd/",
    "xd/scripts/rebrand-xds.py",
)

TEXT_EXTENSIONS = {
    "", ".php", ".py", ".sh", ".conf", ".ini", ".json", ".md", ".txt",
    ".service", ".sql", ".yml", ".yaml", ".xml", ".html", ".css", ".js",
    ".ts", ".vue", ".twig", ".env", ".example", ".dist", ".neon", ".lock",
}

# Ordered from most specific to least specific.
REPLACEMENTS: List[Tuple[str, str]] = [
    ("/home/xc_vm", "/home/xds"),
    ("/etc/xc_vm", "/etc/xds"),
    ("/var/log/xc_vm", "/var/log/xds"),
    ("/var/lib/xc_vm", "/var/lib/xds"),
    ("xc_vm.service", "xds.service"),
    ("XC_VM Service", "XDS Service"),
    ("XC_VM Configuration", "XDS Configuration"),
    ("XC_VM Multi-Distribution Installer", "XDS Multi-Distribution Installer"),
    ("XC_VM-Binaries-Updater", "XDS-Binaries-Updater"),
    ("XC_VM-Installer", "XDS-Installer"),
    ("XC_VM_Binaries", "XDS_Binaries"),
    ("XC_VM.zip", "XDS.zip"),
    ("xc_vm_migrate", "xds_migrate"),
    ("xc_vm", "xds"),
    ("XC_VM", "XDS"),
    ("XC VM", "XD Stream"),
]

# Upstream download coordinates remain unchanged until the XDS binary and release
# repositories exist. Replacing them prematurely would break clean installs.
PROTECTED_PATTERNS = (
    "Vateron-Media/XC_VM",
    "Vateron-Media/XC_VM_Binaries",
    "github.com/Vateron-Media",
    "api.github.com/repos/Vateron-Media",
)


def is_candidate(path: Path) -> bool:
    rel = path.relative_to(ROOT).as_posix()
    if not path.is_file() or path.is_symlink():
        return False
    if rel in EXCLUDED_FILES or any(rel.startswith(prefix) for prefix in EXCLUDED_PREFIXES):
        return False
    if path.suffix.lower() not in TEXT_EXTENSIONS and path.name not in {"install", "service", "Makefile"}:
        return False
    try:
        chunk = path.read_bytes()[:4096]
    except OSError:
        return False
    return b"\x00" not in chunk


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


def transform(text: str) -> str:
    protected, placeholders = protect_upstream(text)
    for source, destination in REPLACEMENTS:
        protected = protected.replace(source, destination)
    return restore_upstream(protected, placeholders)


def scan() -> Iterable[Path]:
    for path in ROOT.rglob("*"):
        if is_candidate(path):
            yield path


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--check", action="store_true", help="report files that would change")
    mode.add_argument("--apply", action="store_true", help="apply the controlled rebrand")
    args = parser.parse_args()

    changed: List[str] = []
    errors: List[str] = []
    total_replacements = 0

    for path in scan():
        rel = path.relative_to(ROOT).as_posix()
        try:
            original = path.read_text(encoding="utf-8")
        except (UnicodeDecodeError, OSError) as exc:
            errors.append("%s: %s" % (rel, exc))
            continue

        updated = transform(original)
        if updated == original:
            continue

        count = sum(original.count(source) for source, _ in REPLACEMENTS)
        total_replacements += count
        changed.append(rel)
        if args.apply:
            path.write_text(updated, encoding="utf-8")

    report = {
        "mode": "apply" if args.apply else "check",
        "root": str(ROOT),
        "brand": "XD Stream",
        "short_name": "XDS",
        "runtime_home": "/home/xds",
        "service": "xds.service",
        "database": "xds",
        "files_changed": changed,
        "file_count": len(changed),
        "replacement_count": total_replacements,
        "errors": errors,
        "upstream_coordinates_preserved": list(PROTECTED_PATTERNS),
    }
    REPORT_PATH.parent.mkdir(parents=True, exist_ok=True)
    REPORT_PATH.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")

    print(json.dumps(report, indent=2))
    if errors:
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
