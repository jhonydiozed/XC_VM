# XD Stream systemd hardening

## Current risk

The legacy XC_VM unit runs `/home/xc_vm/service` as root, maps reload to restart, grants a very high file-descriptor limit, and does not declare common systemd sandboxing controls.

Changing these values blindly can break startup because the service wrapper may bind privileged ports, change users, create files, and launch multiple runtime components. XD Stream therefore applies a staged hardening process.

## Stage 1: audit only

Run:

```bash
sudo bash xd/scripts/audit-systemd-unit.sh xc_vm.service
```

The audit reports:

- explicit root execution;
- reload behavior;
- presence of common sandboxing directives;
- unusually high `LimitNOFILE` values;
- `systemd-analyze security` output when available.

Warnings do not change the host and do not automatically mean a directive is safe to enable.

## Stage 2: privilege inventory

Before changing the unit, record which operations actually require root:

- binding ports below 1024;
- creating or changing runtime users/groups;
- changing ownership under `/home/xc_vm`;
- applying sysctl values;
- changing firewall rules;
- controlling MariaDB or other system services;
- raising process resource limits.

Privileged installation and maintenance work must be separated from normal runtime work.

## Stage 3: split services

The target architecture is:

- `xd-stream.service`: unprivileged application/runtime supervisor;
- `xd-stream-maintenance.service`: short-lived privileged maintenance operations;
- native service units for Nginx/PHP-FPM/worker processes where practical.

## Candidate controls

Controls are introduced individually and tested on Ubuntu 20.04, 22.04, and 24.04:

```ini
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=full
ProtectHome=read-only
RestrictSUIDSGID=yes
LockPersonality=yes
RestrictRealtime=yes
```

`ProtectHome` requires explicit writable paths because XC_VM currently lives under `/home/xc_vm`. `NoNewPrivileges` cannot be enabled until all child-process privilege transitions are understood.

## Release gate

A hardened unit is not promoted until these tests pass:

1. clean boot;
2. start and stop;
3. configuration reload;
4. Live, VOD, series, EPG, and API smoke tests;
5. load-balancer communication;
6. log rotation;
7. binary update rollback;
8. reboot persistence.
