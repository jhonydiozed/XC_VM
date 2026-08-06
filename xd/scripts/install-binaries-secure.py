#!/usr/bin/env python3
"""Secure XD Stream binary installer helper.

Downloads one pinned XC_VM_Binaries release asset, requires a SHA-256
manifest, validates archive members, and extracts into a private staging
folder. This helper intentionally does not replace live binaries; the
existing rollback-aware updater performs that step.
"""

from __future__ import annotations

import argparse
import hashlib
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import ssl
import sys
import tarfile
import tempfile
import urllib.error
import urllib.request

ALLOWED_TAG = re.compile(r"^[A-Za-z0-9._-]+$")
SHA256 = re.compile(r"^[A-Fa-f0-9]{64}$")
SUPPORTED = {
    "ubuntu": {"20", "22", "24"},
    "debian": {"11", "12", "13"},
}


def fail(message: str) -> "NoReturn":
    raise SystemExit(f"ERROR: {message}")


def detect_os() -> tuple[str, str]:
    values: dict[str, str] = {}
    try:
        for raw in Path("/etc/os-release").read_text(encoding="utf-8").splitlines():
            if "=" not in raw:
                continue
            key, value = raw.split("=", 1)
            values[key] = value.strip().strip('"')
    except OSError as exc:
        fail(f"cannot read /etc/os-release: {exc}")

    distro = values.get("ID", "").lower()
    version = values.get("VERSION_ID", "")
    major = version.split(".", 1)[0]
    if distro not in SUPPORTED or major not in SUPPORTED[distro]:
        fail(f"unsupported operating system: {distro} {version}")
    return distro, major


def asset_for(distro: str, major: str) -> str:
    return f"{distro}_{major}.tar.gz"


def request_bytes(url: str, timeout: int = 60) -> bytes:
    if not url.startswith("https://"):
        fail(f"refusing non-HTTPS URL: {url}")
    request = urllib.request.Request(
        url,
        headers={
            "User-Agent": "XD-Stream-Secure-Installer/1.0",
            "Accept": "application/octet-stream",
        },
    )
    context = ssl.create_default_context()
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    try:
        with urllib.request.urlopen(request, timeout=timeout, context=context) as response:
            return response.read()
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        fail(f"download failed for {url}: {exc}")


def expected_sha256(manifest: str, asset: str) -> str:
    for raw in manifest.splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        parts = line.split()
        if len(parts) < 2:
            continue
        digest = parts[0]
        filename = parts[-1].lstrip("*").removeprefix("./")
        if filename == asset:
            if not SHA256.fullmatch(digest):
                fail(f"invalid SHA-256 value for {asset}")
            return digest.lower()
    fail(f"{asset} is absent from hashes.sha256")


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def validate_tar(archive: Path) -> None:
    with tarfile.open(archive, "r:gz") as bundle:
        for member in bundle.getmembers():
            name = PurePosixPath(member.name)
            if name.is_absolute() or ".." in name.parts:
                fail(f"unsafe archive member: {member.name}")
            if member.isdev() or member.isfifo():
                fail(f"unsupported special archive member: {member.name}")
            if member.issym() or member.islnk():
                target = PurePosixPath(member.linkname)
                if target.is_absolute() or ".." in target.parts:
                    fail(f"unsafe link target: {member.name} -> {member.linkname}")


def extract_tar(archive: Path, destination: Path) -> None:
    destination.mkdir(mode=0o700, parents=True, exist_ok=False)
    with tarfile.open(archive, "r:gz") as bundle:
        bundle.extractall(destination, filter="data")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--release", required=True, help="fixed binaries release tag")
    parser.add_argument("--owner", default="Vateron-Media")
    parser.add_argument("--repository", default="XC_VM_Binaries")
    parser.add_argument("--output", type=Path, help="persistent staging directory")
    args = parser.parse_args()

    if os.geteuid() != 0:
        fail("run as root so resulting files can be handed to the system updater")
    if not ALLOWED_TAG.fullmatch(args.release) or args.release == "latest":
        fail("release must be a fixed safe tag, not 'latest'")
    if not re.fullmatch(r"[A-Za-z0-9_.-]+", args.owner):
        fail("unsafe repository owner")
    if not re.fullmatch(r"[A-Za-z0-9_.-]+", args.repository):
        fail("unsafe repository name")

    distro, major = detect_os()
    asset = asset_for(distro, major)
    base = (
        f"https://github.com/{args.owner}/{args.repository}/releases/download/"
        f"{args.release}"
    )

    root = Path(tempfile.mkdtemp(prefix="xd-stream-binaries-", dir="/tmp"))
    os.chmod(root, 0o700)
    archive = root / asset
    try:
        manifest_bytes = request_bytes(f"{base}/hashes.sha256", timeout=30)
        expected = expected_sha256(manifest_bytes.decode("utf-8"), asset)
        archive.write_bytes(request_bytes(f"{base}/{asset}", timeout=900))
        actual = sha256_file(archive)
        if actual != expected:
            fail(f"SHA-256 mismatch for {asset}: expected {expected}, got {actual}")

        validate_tar(archive)
        staging = args.output or (root / "staging")
        if staging.exists():
            fail(f"output already exists: {staging}")
        extract_tar(archive, staging)

        print(f"release={args.release}")
        print(f"asset={asset}")
        print(f"sha256={actual}")
        print(f"staging={staging}")

        if args.output:
            archive.unlink(missing_ok=True)
            shutil.rmtree(root, ignore_errors=True)
        return 0
    except Exception:
        if not args.output:
            shutil.rmtree(root, ignore_errors=True)
        raise


if __name__ == "__main__":
    sys.exit(main())
