# XD Stream Binary Supply-Chain Policy

## Objective

Runtime binaries are privileged production dependencies. An installation or update must fail closed when artifact identity or integrity cannot be established.

## Release requirements

Each release in the configured binaries repository must publish:

- one operating-system archive per supported target;
- `hashes.sha256` containing a SHA-256 digest for every archive;
- a fixed release tag suitable for pinning;
- release notes identifying component versions and build sources.

Example `hashes.sha256`:

```text
<64-character-sha256>  ubuntu_20.tar.gz
<64-character-sha256>  ubuntu_22.tar.gz
<64-character-sha256>  ubuntu_24.tar.gz
```

## Production behavior

Production updates must pass an explicit release tag as argument 4:

```bash
sudo /home/xc_vm/bin/install/update_binaries.sh \
  Vateron-Media \
  XC_VM_Binaries \
  /home/xc_vm/bin \
  v2026.08.1
```

The updater must:

1. use HTTPS only;
2. download the selected operating-system archive;
3. download `hashes.sha256` from the same release;
4. require a valid 64-character digest for the selected archive;
5. compare the downloaded archive using `sha256sum`;
6. reject unsafe archive members before extraction;
7. extract without trusting archive ownership or permissions;
8. preserve rollback data until installation succeeds;
9. record release and checksum information in `bin_version.json`.

## Laboratory overrides

Dynamic `latest` resolution is disabled by default. It can be enabled only for laboratory testing:

```bash
XD_ALLOW_LATEST=1 sudo -E ./update_binaries.sh
```

Legacy MD5 verification is disabled by default. It exists only as an explicit temporary migration aid while the binaries repository is updated:

```bash
XD_ALLOW_LEGACY_MD5=1 sudo -E ./update_binaries.sh ... v2026.08.1
```

Legacy mode must not be used for production releases.

## Future controls

Planned improvements:

- signed manifests using a project-controlled signing key;
- reproducible build metadata;
- SBOM publication;
- CI validation of every release archive;
- malware and secret scanning;
- independent binary provenance records.
