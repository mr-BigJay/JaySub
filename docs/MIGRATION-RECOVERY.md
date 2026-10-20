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

If `/usr/local/x-ui/x-ui` and `systemctl is-active x-ui` are healthy, JaySub **skips** install (no `install.sh`, no SCP overwrite). Migration continues with SSL transfer and DB restore only.

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
