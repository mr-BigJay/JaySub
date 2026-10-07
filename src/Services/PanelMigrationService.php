<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Encryption;

final class PanelMigrationService
{
    /** @param array<string, mixed> $config */
    public static function execute(string $jobId, array $config, Encryption $encryption): void
    {
        $job = MigrationJobStore::load($config, $jobId);
        if ($job === null) {
            return;
        }

        $job['status'] = 'running';
        self::touch($job, $config);

        try {
            self::runSteps($job, $config, $encryption);
            $job['status'] = 'done';
            self::log($job, 'انتقال با موفقیت تمام شد.');
        } catch (\Throwable $e) {
            $job['status'] = 'error';
            self::log($job, 'خطا: ' . $e->getMessage());
            self::setStep($job, (string) ($job['current_step'] ?? ''), 'error', $e->getMessage());
        }

        MigrationJobStore::save($config, $job);
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $config
     */
    private static function runSteps(array &$job, array $config, Encryption $encryption): void
    {
        $panelId = (int) ($job['panel_id'] ?? 0);
        $sourceSslId = (int) ($job['source_ssl_server_id'] ?? 0);
        $target = self::targetServerFromJob($job, $encryption);
        $certPath = trim((string) ($job['target_cert_path'] ?? '/root/cert'));
        if ($certPath === '') {
            $certPath = '/root/cert';
        }

        if ($panelId <= 0 || $sourceSslId <= 0) {
            throw new \RuntimeException('پنل XUI و سرور SSL مبدأ الزامی است.');
        }

        $source = SslServerService::findById($sourceSslId);
        if ($source === null) {
            throw new \RuntimeException('سرور SSL مبدأ یافت نشد.');
        }

        $backupPath = BackupService::latestBackupPath($config, $panelId);
        if ($backupPath === null) {
            throw new \RuntimeException('بک‌آپی برای این پنل روی JaySub نیست — یک‌بار از منوی بک‌آپ یا worker بک‌آپ بگیرید.');
        }

        // 1) SSH به VPS جدید
        self::beginStep($job, 'ssh_target', $config);
        $ping = SshRemoteZip::runRemoteShell(
            $target,
            $encryption,
            'echo jaysub_ok && uname -a',
            60,
            self::storageBase($config),
        );
        if (!$ping['ok']) {
            throw new \RuntimeException('SSH به سرور جدید: ' . ($ping['error'] ?? 'ناموفق'));
        }
        self::log($job, trim((string) ($ping['stdout'] ?? '')));
        self::finishStep($job, 'ssh_target', $config);

        // 2) نصب 3x-ui v3.4.2
        self::beginStep($job, 'install_xui', $config);
        $installCmd = 'bash -lc ' . escapeshellarg(self::remoteXuiInstallScript('v3.4.2'));
        $install = SshRemoteZip::runRemoteShell($target, $encryption, $installCmd, 1200, self::storageBase($config));
        if (!$install['ok']) {
            throw new \RuntimeException('نصب 3x-ui: ' . ($install['error'] ?? 'ناموفق'));
        }
        $out = trim((string) ($install['stdout'] ?? ''));
        if ($out !== '') {
            foreach (preg_split('/\r?\n/', $out) ?: [] as $line) {
                if (trim($line) !== '') {
                    self::log($job, $line);
                }
            }
        }
        if (self::remoteOutputIndicatesXuiInstallFailure($out)) {
            throw new \RuntimeException(
                'نصب 3x-ui روی سرور مقصد کامل نشد (احتمالاً قفل apt یا قطع دانلود). لاگ را ببینید و دوباره انتقال را اجر کنید.'
            );
        }
        self::finishStep($job, 'install_xui', $config);

        // 3) کپی گواهی از VPS فعلی
        self::beginStep($job, 'ssl_copy', $config);
        $archive = SshRemoteZip::zipCertDirectory($source, $encryption, self::storageBase($config));
        if (!$archive['ok'] || empty($archive['body'])) {
            throw new \RuntimeException('خواندن /root/cert از سرور مبدأ: ' . ($archive['error'] ?? 'ناموفق'));
        }
        $ext = (string) ($archive['extension'] ?? '.zip');
        $tmp = tempnam(sys_get_temp_dir(), 'jscert_');
        if ($tmp === false) {
            throw new \RuntimeException('فایل موقت ایجاد نشد.');
        }
        $localArchive = $tmp . $ext;
        rename($tmp, $localArchive);
        file_put_contents($localArchive, $archive['body']);

        $remoteArchive = '/tmp/jaysub-certs' . $ext;
        $up = SshRemoteZip::uploadLocalFile($target, $encryption, $localArchive, $remoteArchive, self::storageBase($config));
        @unlink($localArchive);
        if (!$up['ok']) {
            throw new \RuntimeException('آپلود گواهی به سرور جدید: ' . ($up['error'] ?? 'ناموفق'));
        }

        $folder = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($certPath)) ?: 'cert';
        $certEsc = escapeshellarg($certPath);
        $archEsc = escapeshellarg($remoteArchive);
        $extract = 'bash -lc ' . escapeshellarg(
            "set -e; mkdir -p {$certEsc}; "
            . "if [ -f {$archEsc} ] && command -v unzip >/dev/null 2>&1 && [[ {$archEsc} == *.zip ]]; then "
            . "  d=\$(mktemp -d); unzip -o {$archEsc} -d \"\$d\"; "
            . "  if [ -d \"\$d/{$folder}\" ]; then cp -a \"\$d/{$folder}/.\" {$certEsc}/; "
            . "  else cp -a \"\$d/.\" {$certEsc}/; fi; rm -rf \"\$d\"; "
            . "elif command -v tar >/dev/null 2>&1; then "
            . "  tar -xzf {$archEsc} -C /tmp; "
            . "  if [ -d /tmp/{$folder} ]; then cp -a /tmp/{$folder}/. {$certEsc}/; fi; "
            . "else echo 'unzip or tar required on target VPS' >&2; exit 1; fi; "
            . "rm -f {$archEsc}"
        );
        $ex = SshRemoteZip::runRemoteShell($target, $encryption, $extract, 180, self::storageBase($config));
        if (!$ex['ok']) {
            throw new \RuntimeException('استخراج گواهی روی سرور جدید: ' . ($ex['error'] ?? 'ناموفق'));
        }
        self::log($job, 'گواهی‌ها در ' . $certPath . ' قرار گرفتند.');
        self::finishStep($job, 'ssl_copy', $config);

        // 4) آخرین بک‌آپ JaySub → جایگزینی دیتابیس x-ui روی VPS جدید (همان تنظیمات پنل قدیم)
        self::beginStep($job, 'restore_backup', $config);
        $remoteBackup = '/tmp/jaysub-restore-' . basename($backupPath);
        $upDb = SshRemoteZip::uploadLocalFile(
            $target,
            $encryption,
            $backupPath,
            $remoteBackup,
            self::storageBase($config),
        );
        if (!$upDb['ok']) {
            throw new \RuntimeException('آپلود بک‌آپ به سرور جدید: ' . ($upDb['error'] ?? 'ناموفق'));
        }
        self::log($job, 'بک‌آپ JaySub: ' . basename($backupPath));

        $restoreEsc = escapeshellarg($remoteBackup);
        $restoreCmd = 'bash -lc ' . escapeshellarg(
            'set -e; RESTORE=' . $restoreEsc . '; '
            . 'mkdir -p /etc/x-ui; '
            . 'DB=""; for c in /etc/x-ui/x-ui.db /usr/local/x-ui/x-ui.db; do [ -f "$c" ] && DB="$c" && break; done; '
            . 'if [ -z "$DB" ]; then DB="/etc/x-ui/x-ui.db"; fi; '
            . 'systemctl stop x-ui 2>/dev/null || systemctl stop 3x-ui 2>/dev/null || true; '
            . 'sleep 2; '
            . 'if [ -f "$DB" ]; then cp -a "$DB" "${DB}.jaysub.bak.$(date +%s)"; fi; '
            . 'cp -f "$RESTORE" "$DB"; chmod 600 "$DB"; '
            . 'systemctl start x-ui 2>/dev/null || systemctl start 3x-ui 2>/dev/null || (command -v x-ui >/dev/null && x-ui restart); '
            . 'sleep 3; systemctl is-active x-ui 2>/dev/null || systemctl is-active 3x-ui 2>/dev/null || echo x-ui restarted; '
            . 'rm -f "$RESTORE"'
        );
        $rest = SshRemoteZip::runRemoteShell($target, $encryption, $restoreCmd, 180, self::storageBase($config));
        if (!$rest['ok']) {
            throw new \RuntimeException('بازگردانی بک‌آپ روی x-ui: ' . ($rest['error'] ?? 'ناموفق'));
        }
        $rout = trim((string) ($rest['stdout'] ?? ''));
        if ($rout !== '') {
            self::log($job, $rout);
        }
        self::log($job, 'تنظیمات پنل از بک‌آپ اعمال شد — همان مسیر/کاربر پنل قبلی (DNS را به IP جدید بزنید).');
        self::finishStep($job, 'restore_backup', $config);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private static function targetServerFromJob(array $job, Encryption $encryption): array
    {
        $secretEnc = (string) ($job['target_secret_encrypted'] ?? '');
        if ($secretEnc === '') {
            throw new \RuntimeException('رمز SSH سرور جدید ذخیره نشده.');
        }

        return [
            'host' => trim((string) ($job['target_host'] ?? '')),
            'ssh_port' => (int) ($job['target_port'] ?? 22),
            'ssh_username' => trim((string) ($job['target_user'] ?? 'root')),
            'auth_type' => ($job['target_auth_type'] ?? 'password') === 'key' ? 'key' : 'password',
            'ssh_secret_encrypted' => $secretEnc,
            'cert_path' => '/root/cert',
        ];
    }

    /** @param array<string, mixed> $config */
    private static function storageBase(array $config): string
    {
        return (string) ($config['paths']['storage'] ?? dirname(__DIR__, 2) . '/storage');
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $config
     */
    private static function beginStep(array &$job, string $id, array $config): void
    {
        $job['current_step'] = $id;
        self::setStep($job, $id, 'running', null);
        self::touch($job, $config);
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $config
     */
    private static function finishStep(array &$job, string $id, array $config): void
    {
        self::setStep($job, $id, 'ok', null);
        self::touch($job, $config);
    }

    /**
     * @param array<string, mixed> $job
     */
    private static function setStep(array &$job, string $id, string $status, ?string $message): void
    {
        foreach ($job['steps'] as &$step) {
            if (($step['id'] ?? '') === $id) {
                $step['status'] = $status;
                if ($message !== null && $message !== '') {
                    $step['message'] = $message;
                }
                break;
            }
        }
        unset($step);
    }

    /**
     * @param array<string, mixed> $job
     */
    private static function log(array &$job, string $line): void
    {
        $job['log'][] = [
            'at' => date('c'),
            'line' => mb_substr($line, 0, 4000),
        ];
        if (count($job['log']) > 500) {
            $job['log'] = array_slice($job['log'], -400);
        }
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $config
     */
    private static function touch(array &$job, array $config): void
    {
        $job['updated_at'] = date('c');
        MigrationJobStore::save($config, $job);
    }

    /** Shell script run on the destination VPS to install 3x-ui (visible for tests). */
    public static function remoteXuiInstallScript(string $version = 'v3.4.2'): string
    {
        $ver = preg_replace('/[^a-zA-Z0-9._-]/', '', $version) ?: 'v3.4.2';
        $installSh = 'https://raw.githubusercontent.com/mhsanaei/3x-ui/' . $ver . '/install.sh';

        return 'set -euo pipefail; '
            . 'export DEBIAN_FRONTEND=noninteractive XUI_NONINTERACTIVE=1 XUI_DB_TYPE=sqlite XUI_SSL_MODE=none XUI_ENABLE_FAIL2BAN=false; '
            . 'wait_apt() { local max=600 w=0; '
            . 'while fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 '
            . '|| fuser /var/lib/apt/lists/lock >/dev/null 2>&1 '
            . '|| pgrep -x apt-get >/dev/null 2>&1 || pgrep -x apt >/dev/null 2>&1; do '
            . 'if [ "$w" -ge "$max" ]; then echo "apt lock held after ${max}s" >&2; return 1; fi; '
            . 'echo "Waiting for apt lock (${w}s)..." >&2; sleep 5; w=$((w+5)); done; }; '
            . 'LOG=/tmp/jaysub-xui-install.log; '
            . 'xui_binary_ready() { [ -x /usr/local/x-ui/x-ui ] || [ -x /usr/bin/x-ui ]; }; '
            . 'xui_db_ready() { for c in /etc/x-ui/x-ui.db /usr/local/x-ui/x-ui.db; do [ -f "$c" ] && return 0; done; return 1; }; '
            . 'xui_post_install() { systemctl daemon-reload 2>/dev/null || true; '
            . 'systemctl enable x-ui 2>/dev/null || true; '
            . 'systemctl start x-ui 2>/dev/null || /usr/bin/x-ui start 2>/dev/null || true; sleep 5; '
            . 'if [ -x /usr/local/x-ui/x-ui ]; then /usr/local/x-ui/x-ui migrate 2>/dev/null || true; fi; '
            . 'if [ -x /usr/bin/x-ui ]; then /usr/bin/x-ui migrate 2>/dev/null || true; fi; sleep 2; }; '
            . 'run_install() { wait_apt; rm -f "$LOG"; '
            . 'bash <(curl -fsSL ' . escapeshellarg($installSh) . ') '
            . escapeshellarg($ver) . ' >>"$LOG" 2>&1; }; '
            . 'attempt=1; while [ "$attempt" -le 2 ]; do '
            . 'if run_install && xui_binary_ready; then xui_post_install; '
            . 'if xui_binary_ready; then tail -n 180 "$LOG" 2>/dev/null || true; '
            . 'xui_db_ready || echo "Note: x-ui.db will be created from JaySub backup on restore step." >&2; exit 0; fi; fi; '
            . 'if [ "$attempt" -eq 2 ]; then break; fi; '
            . 'echo "Retrying x-ui install (attempt 2/2)..." >&2; sleep 15; attempt=2; '
            . 'done; '
            . 'tail -n 180 "$LOG" 2>/dev/null || true; '
            . 'if grep -q "Failed to extract the x-ui release archive" "$LOG" 2>/dev/null; then '
            . 'echo "x-ui install failed (archive extract). See log above." >&2; exit 1; fi; '
            . 'echo "x-ui binary not found after install" >&2; exit 1';
    }

    public static function remoteOutputIndicatesXuiInstallFailure(string $output): bool
    {
        if ($output === '') {
            return false;
        }

        $needles = [
            'Failed to extract the x-ui release archive',
            'x-ui binary not found after install',
            'x-ui install failed (archive extract)',
            'Download x-ui',
            ' failed, please check if the version exists',
            'Downloading x-ui failed',
            'apt lock held after',
        ];
        foreach ($needles as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{id:string,label:string,status:string,message?:string}> */
    public static function defaultSteps(): array
    {
        return [
            ['id' => 'ssh_target', 'label' => '۱. اتصال SSH به VPS جدید', 'status' => 'pending'],
            ['id' => 'install_xui', 'label' => '۲. نصب 3x-ui نسخه v3.4.2', 'status' => 'pending'],
            ['id' => 'ssl_copy', 'label' => '۳. انتقال گواهی SSL (/root/cert)', 'status' => 'pending'],
            ['id' => 'restore_backup', 'label' => '۴. بازگردانی آخرین بک‌آپ JaySub روی x-ui', 'status' => 'pending'],
        ];
    }
}
