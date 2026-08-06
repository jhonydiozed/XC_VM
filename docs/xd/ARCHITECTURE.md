# XD Stream — Architecture Foundation

## Purpose

XD Stream is a maintained distribution built on the XC_VM codebase. The project preserves the XC_VM AGPL-3.0 license and attribution while adding a controlled compatibility, security, operations, and integration layer.

## Repository roles

### XC_VM fork

This repository remains the streaming engine and compatibility core:

- Xtream-compatible client endpoints
- line, bouquet, stream, VOD, series, and EPG management
- Nginx, PHP-FPM, FFmpeg, MariaDB, and KeyDB runtime integration
- load-balancer management
- installation and update workflows

### XD modules

New XD functionality should be isolated behind explicit interfaces whenever possible:

- XD Security: integrity verification, hardening, audit tooling
- XD Compatibility: Ubuntu 20.04, 22.04, and 24.04 support
- XD Control: external orchestration and administrative API
- XD Domains: domain allocation and Cloudflare integration
- XD Monitor: health, metrics, logs, and alerts
- XD Migration: controlled migration from XUI.one-compatible databases

## Design rules

1. Preserve AGPL-3.0 notices and upstream attribution.
2. Do not silently download or execute unverified artifacts.
3. Pin releases used by production installations.
4. Keep OS-specific package logic separated by distribution and release.
5. Prefer stable internal APIs over direct cross-module SQL access.
6. Treat binaries, installers, update scripts, and systemd units as security-sensitive code.
7. Require rollback paths for database, application, and binary updates.
8. Support clean installations on Ubuntu 20.04, 22.04, and 24.04.

## Target runtime layout

```text
/home/xc_vm/
├── bin/              # managed runtime binaries
├── config/           # application configuration
├── logs/             # service and application logs
├── tmp/              # transient runtime data
└── www/               # web application

/etc/xd-stream/       # XD-specific configuration
/var/lib/xd-stream/   # XD persistent state
/var/log/xd-stream/   # XD audit and service logs
```

## Integration boundary

The preferred long-term architecture separates XD Control from the XC_VM runtime:

```text
Clients -> Nginx/XC_VM -> MariaDB + KeyDB + FFmpeg
                    ^
                    |
             documented API/events
                    |
                XD Control
```

Direct database access by XD Control is allowed only through a dedicated adapter with documented schema expectations and migration tests.

## Initial milestones

1. Security inventory of installer, updater, external downloads, services, and privileged operations.
2. Reproducible Ubuntu compatibility matrix.
3. SHA-256 artifact manifest and mandatory verification.
4. Installation test harness for Ubuntu 20.04, 22.04, and 24.04.
5. XD Control service specification.
6. XUI.one migration analysis using a database copy, never the production database.
