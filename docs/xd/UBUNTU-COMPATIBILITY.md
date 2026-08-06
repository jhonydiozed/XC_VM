# XD Stream — Ubuntu Compatibility

## Support target

XD Stream targets clean, supported installations on:

| Ubuntu release | Codename | XD target |
| --- | --- | --- |
| 20.04 LTS | Focal | Supported compatibility baseline |
| 22.04 LTS | Jammy | Primary supported release |
| 24.04 LTS | Noble | Primary supported release |

Ubuntu 18.04 is not part of the XD production support target.

## Compatibility principles

1. Installation must detect the exact OS release using `/etc/os-release`.
2. Package names must be validated independently for each release.
3. Runtime binaries must match the target OS ABI or be built to avoid incompatible dynamic dependencies.
4. No installation may rely on an End-of-Life third-party APT repository.
5. Existing MariaDB installations must not be replaced without an explicit migration plan and backup.
6. Installer reruns must be idempotent where practical.
7. The installer must stop on missing mandatory dependencies instead of silently continuing.

## Current installer observations

The upstream installer contains dedicated package groups for Ubuntu 20.04, 22.04, and 24.04. This is a useful base, but package presence alone does not prove that the full runtime is compatible.

Items requiring validation include:

- PHP binary dynamic library requirements;
- Nginx and Nginx-RTMP binary requirements;
- FFmpeg binary requirements;
- KeyDB compatibility;
- MariaDB version and schema behavior;
- Certbot package behavior;
- iptables versus nftables behavior;
- systemd unit behavior;
- OpenSSL compatibility;
- GeoIP library availability;
- `libcurl`, `libssh2`, `libsodium`, and Oniguruma package differences.

## Required test matrix

Each supported OS must pass the following sequence on a clean VM:

1. system update;
2. XD Stream installation;
3. service start and restart;
4. PHP runtime test;
5. Nginx configuration test;
6. MariaDB connectivity test;
7. KeyDB connectivity test;
8. FFmpeg execution test;
9. panel login test;
10. client API authentication test;
11. playlist generation test;
12. one live stream test;
13. one VOD test;
14. EPG import test;
15. load-balancer registration test;
16. backup test;
17. update and rollback test;
18. uninstall or cleanup validation.

## Evidence to retain

For every test image, retain:

```text
artifacts/compatibility/<release>/
├── os-release.txt
├── packages.txt
├── installer.log
├── systemd-status.txt
├── listening-ports.txt
├── outbound-connections.txt
├── php-version.txt
├── nginx-version.txt
├── ffmpeg-version.txt
├── mariadb-version.txt
├── keydb-version.txt
└── test-results.md
```

## Release gate

An Ubuntu version is marked supported only when:

- installation succeeds from a clean image;
- all core services start after reboot;
- API and streaming smoke tests pass;
- no mandatory package is fetched from an unsupported repository;
- the exact runtime artifact hashes are recorded;
- rollback has been tested;
- known limitations are documented.
