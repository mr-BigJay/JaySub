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


## Online vs offline install mode (strictly separate)

In the migration wizard, unchecked **offline** checkbox means **online only**:
the destination runs the pinned upstream `install.sh` for v3.4.2 and needs access
to `raw.githubusercontent.com` and the official release download endpoint.

With the checkbox checked, **offline only**: JaySub transfers the
SHA-256 checked v3.4.2 archive from its manager-side cache using SCP.
The target does not contact GitHub. SQLite integrity checks, WAL-safe snapshot,
restore, and DB SSL-path checks use the sqlite3 CLI when available, or the
standard Python 3 sqlite3 module as a drop-in fallback. No permanent CLI shim
is installed, no database verification is bypassed, and online mode stays separate.

If neither sqlite3 CLI nor Python 3 with sqlite3 is available, prepare the
target (if OS package repositories are reachable):

```bash
sudo apt-get update && sudo apt-get install -y sqlite3 file openssl tar gzip ca-certificates coreutils
```

If the target is fully isolated, stage these OS packages via your own
approved package mirror or offline media before starting migration.
Offline 3x-ui install does not run apt, use curl, or automatically switch
from online. A failed online job stays failed with the GitHub error and
does not execute offline prerequisites. Start a **new job** after changing
the install mode.

The migration job keeps `xui_install_mode` and
`xui_install_mode_effective` equal to the selected mode. An already-healthy
x-ui target is detected and skipped without running either installer.

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
