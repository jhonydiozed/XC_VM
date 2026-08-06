# XD Stream — Security Audit

## Scope

This audit covers the privileged installation and update paths first, followed by application code, services, scheduled tasks, external communication, and bundled binaries.

The absence of a finding is not proof that a component is safe. Production approval requires both source review and runtime observation on an isolated server.

## Current status

### Confirmed observations

1. The main installer is a Python 3 program intended to run with elevated privileges.
2. Ubuntu 20.04, 22.04, and 24.04 have explicit package lists in the installer.
3. Runtime binaries may be downloaded from the separate `Vateron-Media/XC_VM_Binaries` repository.
4. The binary updater stops the `xc_vm` service, stages an update, backs up replaced components, and attempts rollback on failure.
5. The binary updater resolves the latest release dynamically unless a release tag is provided.
6. Archive verification currently uses `hashes.md5` when available.
7. The updater continues with only a warning when no matching MD5 entry is found.

### Initial risk classification

| ID | Finding | Severity | Status |
| --- | --- | --- | --- |
| XD-SA-001 | Binary update may continue without a verified hash | High | Open |
| XD-SA-002 | MD5 is used instead of SHA-256 or signatures | Medium | Open |
| XD-SA-003 | Production update defaults to a moving `latest` release | High | Open |
| XD-SA-004 | External binary repository requires independent audit | High | Open |
| XD-SA-005 | Installer executes privileged package and filesystem operations | Expected/High impact | Review required |

## Mandatory controls

Before production use, XD Stream must enforce:

- pinned application and binary release versions;
- mandatory SHA-256 verification;
- failure when a checksum is absent;
- HTTPS-only downloads;
- an allowlist of external domains;
- a complete manifest of downloaded artifacts;
- rollback for binaries, application code, and database migrations;
- audit logs for installation and update operations;
- no modification of SSH authorized keys, sudoers, or firewall rules without explicit documented configuration;
- no remote script execution through `curl | sh`, `wget | sh`, or equivalent patterns.

## Audit checklist

### Installer

- [ ] Enumerate package repositories added or modified.
- [ ] Enumerate packages installed per supported OS.
- [ ] Enumerate files and directories created outside `/home/xc_vm`.
- [ ] Review database initialization and generated credentials.
- [ ] Review systemd units.
- [ ] Review firewall changes.
- [ ] Review cron jobs and timers.
- [ ] Review ownership and permission changes.
- [ ] Review any use of shell commands assembled from variables.

### Updater

- [x] Identify external release source.
- [x] Identify checksum mechanism.
- [x] Confirm rollback logic exists for binary replacement.
- [ ] Replace MD5 with mandatory SHA-256.
- [ ] Require an explicit release tag in production mode.
- [ ] Validate extracted archive paths against traversal.
- [ ] Validate ownership and permissions after installation.
- [ ] Record installed artifact versions and hashes.

### Application

- [ ] Inventory `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, and backticks.
- [ ] Inventory outbound HTTP requests.
- [ ] Inventory dynamically included PHP files.
- [ ] Review authentication and session management.
- [ ] Review upload and archive extraction paths.
- [ ] Review command construction for FFmpeg and system utilities.
- [ ] Review SQL construction and prepared statement coverage.
- [ ] Review administrative endpoints and authorization.

### Runtime

- [ ] Capture listening ports after clean installation.
- [ ] Capture outbound connections during idle operation.
- [ ] Capture processes and process trees.
- [ ] Capture systemd services and timers.
- [ ] Capture user and group changes.
- [ ] Capture cron tables.
- [ ] Capture modified files under `/etc`, `/usr/local`, and `/root`.

## Production gate

XD Stream must not be described as audited or backdoor-free until:

1. the source audit is complete;
2. external binaries are reproduced or independently verified;
3. runtime monitoring shows no unexplained communication or persistence;
4. installation succeeds on clean Ubuntu 20.04, 22.04, and 24.04 systems;
5. all High findings are closed or explicitly accepted and documented.
