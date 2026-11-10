# JaySub server migration — deploy & recovery

## Safe deploy (management server `/var/www/vpn-panel`)

```bash
cd /var/www/vpn-panel
git fetch origin
git merge origin/cursor/migration-hardening-5b28
sudo systemctl reload php8.3-fpm
```

Existing offline cache under `storage/xui-offline/v3.4.2/` is reused; do not re-download unless files are missing.

## Target VPS prerequisites

- Ubuntu/Debian, systemd, root SSH
- `sqlite3`, `openssl`, `file`, `tar`, `gzip` (and `unzip` if SSL backup is zip)

## Already-healthy x-ui on target (manual install)

If `/usr/local/x-ui/x-ui` and `systemctl is-active x-ui` are healthy, JaySub **skips** install (no `install.sh`, no SCP overwrite). SSL transfer and DB restore run **only** when the wizard checkbox for explicit overwrite is checked; otherwise steps 3–4 are skipped and existing certs/DB stay untouched.

## Half-installed target recovery

1. Inspect: `systemctl status x-ui`, `ls -la /usr/local/x-ui/x-ui`, `journalctl -u x-ui -n 50`
2. If binary missing but unit exists: re-run migration with **offline** mode on a **fresh** VPS, or manually remove partial install:
   ```bash
   systemctl stop x-ui; systemctl disable x-ui
   rm -rf /usr/local/x-ui /etc/x-ui/x-ui.db
   rm -f /etc/systemd/system/x-ui.service
   systemctl daemon-reload
   ```
3. Start a **new** migration job in JaySub (do not reuse failed job id).

## DNS

Changing DNS is manual; JaySub does not modify DNS records.


## GitHub timeout during online 3x-ui installation

JaySub now performs a short reachability check from the target VPS for
the pinned upstream install script and official v3.4.2 release. When either
cannot be accessed, it automatically uses the SHA-256 checked archive in the
**management server's** `storage/xui-offline/v3.4.2/` directory. An additional
fallback is allowed if the pinned install.sh could not be downloaded **before
it was executed**. Other install failures remain fail-closed to avoid modifying
a partially installed or existing x-ui instance.

The migration log records requested `xui_install_mode` and effective
`xui_install_mode_effective`. If the archive is not yet cached, stage the
verified release on the manager with `php scripts/cache-xui-offline.php amd64`
(architecture of destination) or the existing admin upload form; the VPN
destination itself never needs GitHub for offline installation.

## SSH known_hosts permission warning

Run **on the management server**, as root, after updating the code:

```bash
cd /var/www/vpn-panel
sudo bash scripts/repair-ssh-known-hosts.sh www-data
```

If the PHP-FPM and migration job user is not `www-data`, pass that user instead.
The repair preserves existing known host keys. It fixes owner and restrictive
permissions only for `storage/ssl-ssh` and `known_hosts`. Never set
`StrictHostKeyChecking=no`, use world-writable SSH state, or clear host key
pinning indiscriminately.

Re-run migration as a **new job**. A failed job is not resumed in place.
