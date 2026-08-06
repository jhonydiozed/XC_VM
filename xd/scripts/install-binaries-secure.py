#!/usr/bin/env python3
"""Stage a pinned and SHA-256 verified XD Stream binaries release.

Compatible with Python 3.8 shipped by Ubuntu 20.04. This helper never
replaces live binaries; use the rollback-aware update_binaries.sh after
verification and staging.
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

SAFE_NAME = re.compile(r"^[A-Za-z0-9._-]+$")
SAFE_SHA256 = re.compile(r"^[A-Fa-f0-9]{64}$")
SUPPORTED = {"ubuntu": {"20", "22", "24"}, "debian": {"11", "12", "13"}}


def fail(message):
    raise SystemExit("ERROR: " + message)


def detect_os():
    values = {}
    try:
        for raw in Path("/etc/os-release").read_text(encoding="utf-8").splitlines():
            if "=" in raw:
                key, value = raw.split("=", 1)
                values[key] = value.strip().strip('"')
    except OSError as exc:
        fail("cannot read /etc/os-release: %s" % exc)

    distro = values.get("ID", "").lower()
    version = values.get("VERSION_ID", "")
    major = version.split(".", 1)[0]
    if distro not in SUPPORTED or major not in SUPPORTED[distro]:
        fail("unsupported operating system: %s %s" % (distro, version))
    return distro, major


def download(url, timeout):
    if not url.startswith("https://"):
        fail("refusing non-HTTPS URL: " + url)
    request = urllib.request.Request(
        url,
        headers={"User-Agent": "XD-Stream-Secure-Installer/1.0"},
    )
    context = ssl.create_default_context()
    if hasattr(ssl, "TLSVersion"):
        context.minimum_version = ssl.TLSVersion.TLSv1_2
    try:
        with urllib.request.urlopen(request, timeout=timeout, context=context) as response:
            return response.read()
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        fail("download failed for %s: %s" % (url, exc))


def manifest_hash(text, asset):
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        parts = line.split()
        if len(parts) < 2:
            continue
        digest = parts[0]
        filename = parts[-1].lstrip("*")
        if filename.startswith("./"):
            filename = filename[2:]
        if filename == asset:
            if not SAFE_SHA256.fullmatch(digest):
                fail("invalid SHA-256 value for " + asset)
            return digest.lower()
    fail(asset + " is absent from hashes.sha256")


def sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while True:
            block = handle.read(1024 * 1024)
            if not block:
                break
            digest.update(block)
    return digest.hexdigest()


def validated_members(bundle):
    for member in bundle.getmembers():
        name = PurePosixPath(member.name)
        if name.is_absolute() or ".." in name.parts:
            fail("unsafe archive member: " + member.name)
        if member.isdev() or member.isfifo():
            fail("unsupported special archive member: " + member.name)
        if member.issym() or member.islnk():
            target = PurePosixPath(member.linkname)
            if target.is_absolute() or ".." in target.parts:
                fail("unsafe link target: %s -> %s" % (member.name, member.linkname))
        yield member


def extract(archive, destination):
    destination.mkdir(mode=0o700, parents=True, exist_ok=False)
    with tarfile.open(str(archive), "r:gz") as bundle:
        for member in validated_members(bundle):
            bundle.extract(member, str(destination), set_attrs=False)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--release", required=True)
    parser.add_argument("--owner", default="Vateron-Media")
    parser.add_argument("--repository", default="XC_VM_Binaries")
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()

    if os.geteuid() != 0:
        fail("run as root")
    for label, value in (("release", args.release), ("owner", args.owner), ("repository", args.repository)):
        if not SAFE_NAME.fullmatch(value):
            fail("unsafe %s: %s" % (label, value))
    if args.release == "latest":
        fail("a fixed release tag is required")
    if args.output.exists():
        fail("output already exists: " + str(args.output))

    distro, major = detect_os()
    asset = "%s_%s.tar.gz" % (distro, major)
    base = "https://github.com/%s/%s/releases/download/%s" % (
        args.owner,
        args.repository,
        args.release,
    )

    root = Path(tempfile.mkdtemp(prefix="xd-stream-binaries-", dir="/tmp"))
    os.chmod(str(root), 0o700)
    archive = root / asset
    try:
        expected = manifest_hash(download(base + "/hashes.sha256", 30).decode("utf-8"), asset)
        archive.write_bytes(download(base + "/" + asset, 900))
        actual = sha256(archive)
        if actual != expected:
            fail("SHA-256 mismatch for %s: expected %s, got %s" % (asset, expected, actual))
        extract(archive, args.output)
        print("release=" + args.release)
        print("asset=" + asset)
        print("sha256=" + actual)
        print("staging=" + str(args.output))
        return 0
    finally:
        shutil.rmtree(str(root), ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
