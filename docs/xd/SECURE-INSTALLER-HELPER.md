# XD Stream Secure Installer Helper

`xd/scripts/install-binaries-secure.py` provides a fail-closed staging workflow for binary releases.

## Requirements

- Root access
- Python 3.8 or newer
- Ubuntu 20.04, 22.04, or 24.04; or Debian 11, 12, or 13
- A fixed release tag in the binaries repository
- A release asset named for the detected OS, such as `ubuntu_20.tar.gz`
- A mandatory `hashes.sha256` release asset

## Usage

```bash
sudo python3 xd/scripts/install-binaries-secure.py \
  --release v2026.08.1 \
  --output /root/xd-binaries-v2026.08.1
```

The helper downloads over HTTPS, verifies SHA-256, rejects unsafe archive paths and unsafe link targets, and extracts into the requested staging directory.

It does not stop services or replace `/home/xc_vm/bin`. After reviewing the staged files, use the rollback-aware updater with the same fixed tag:

```bash
sudo /home/xc_vm/bin/install/update_binaries.sh \
  Vateron-Media XC_VM_Binaries /home/xc_vm/bin v2026.08.1
```

## Production rule

Do not use `latest`. Production deployments must record the chosen release tag and SHA-256 digest in change-management records before replacing runtime binaries.

## Ubuntu 20.04 compatibility

The helper is intentionally restricted to Python features available in Python 3.8. CI compiles and exercises it on Python 3.8, 3.10, and 3.12.
