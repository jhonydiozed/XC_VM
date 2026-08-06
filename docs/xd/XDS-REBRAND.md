# XD Stream (XDS) Rebrand

## Runtime identity

The XD Stream distribution uses these runtime identifiers:

- Product: `XD Stream`
- Short name: `XDS`
- Linux user/group: `xds`
- Runtime home: `/home/xds`
- Service: `xds.service`
- Primary database: `xds`
- Migration database: `xds_migrate`
- Configuration root: `/etc/xds`
- Persistent state: `/var/lib/xds`
- Logs: `/var/log/xds`

Examples:

```text
/home/xds/
├── backups/
├── bin/
├── config/
├── content/
├── logs/
├── tmp/
└── www/
```

## Controlled transformation

Run from a clean checkout:

```bash
python3 xd/scripts/rebrand-xds.py --check
sudo bash install-xds
```

The rebrand tool changes application/runtime identifiers but deliberately preserves:

- `LICENSE`
- upstream copyright and contributor records
- upstream repository coordinates needed by the current binary supply chain
- XD architecture and security documentation that discusses the upstream project

This is required both for legal attribution and to prevent the current installer from referencing release repositories that do not exist yet.

## Existing XC_VM installation

Do not rename a running `/home/xc_vm` installation in place. The service, database, crons, configuration, permissions, absolute paths, and cached data are coupled to the original identity.

For the laboratory transition:

1. Keep the existing XC_VM installation as evidence.
2. Take a VPS snapshot.
3. Reinstall the operating system or use a second clean VPS.
4. Checkout the XDS branch.
5. Run `sudo bash install-xds`.
6. Execute the preflight and post-install health checks.

A supported in-place migration tool will be developed separately after the clean XDS installation is validated.

## Brand artwork

Artwork must be original. It may use a high-energy retro television aesthetic, angular geometry, lime/green, black, white, and metallic gray, but it must not reproduce Disney typography, the Disney wordmark, the Disney XD silhouette, or protected character/iconography.
