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
        $mode = self::normalizeInstallMode((string) ($job['xui_install_mode'] ?? 'online'));
        $job['xui_install_mode'] = $mode;
        self::log($job, 'روش نصب 3x-ui (از Job): ' . ($mode === 'offline' ? 'آفلاین' : 'آنلاین'));
        self::touch($job, $config);
        if ($mode === 'offline') {
            self::log($job, 'نصب آفلاین v3.4.2؛ بسته از cache JaySub (بدون GitHub روی مقصد).');
            self::touch($job, $config);
            $archProbe = SshRemoteZip::runRemoteShell(
                $target, $encryption, 'uname -m', 30, self::storageBase($config)
            );
            if (!$archProbe['ok']) {
                throw new \RuntimeException('تشخیص معماری VPS مقصد: ' . ($archProbe['error'] ?? 'ناموفق'));
            }
            $arch = OfflineXuiInstaller::normalizeArchitecture((string) ($archProbe['stdout'] ?? ''));
            if ($arch === null) {
                throw new \RuntimeException('نصب آفلاین فقط برای amd64 و arm64 پشتیبانی می‌شود.');
            }
            $archive = OfflineXuiInstaller::ensureArchive($config, $arch);
            $remoteArchive = OfflineXuiInstaller::remotePackagePath((string) ($job['id'] ?? ''), $arch);
            self::log($job, 'SHA-256 تأیید شد؛ SCP به مقصد...');
            self::touch($job, $config);
            $transfer = SshRemoteZip::uploadLocalFile(
                $target, $encryption, $archive['path'], $remoteArchive, self::storageBase($config)
            );
            if (!$transfer['ok']) {
                throw new \RuntimeException('انتقال بسته آفلاین: ' . ($transfer['error'] ?? 'ناموفق'));
            }
            $installCmd = 'bash -lc ' . escapeshellarg(
                OfflineXuiInstaller::remoteInstallScript($arch, $remoteArchive)
            );
        } else {
            $installCmd = 'bash -lc ' . escapeshellarg(self::remoteXuiInstallScript('v3.4.2'));
        }
        $install = self::runRemoteChecked(
            $job,
            $target,
            $encryption,
            $installCmd,
            1200,
            $config,
            'نصب 3x-ui'
        );
        self::logRemoteOutput($job, $install);
        self::assertRemoteShellSuccess($install, 'نصب 3x-ui');
        $installOut = self::remoteCombinedOutput($install);
        if (self::remoteOutputIndicatesXuiInstallFailure($installOut)) {
            throw new \RuntimeException(
                'نصب 3x-ui روی سرور مقصد کامل نشد (خروجی خطا در لاگ). دوباره با روش نصب صحیح (آنلاین/آفلاین) تلاش کنید.'
            );
        }
        $verifyCmd = 'bash -lc ' . escapeshellarg(MigrationPostInstallVerify::remoteVerifyScript());
        $verify = self::runRemoteChecked($job, $target, $encryption, $verifyCmd, 300, $config, 'تأیید سلامت x-ui');
        self::logRemoteOutput($job, $verify);
        self::assertRemoteShellSuccess($verify, 'تأیید نصب 3x-ui');
        self::finishStep($job, 'install_xui', $config);

        // 3) کپی گواهی از VPS فعلی
        self::beginStep($job, 'ssl_copy', $config);
        $sourceCertPath = trim((string) ($source['cert_path'] ?? '/root/cert'));
        if ($sourceCertPath === '') {
            $sourceCertPath = '/root/cert';
        }
        $archive = SshRemoteZip::zipCertDirectory($source, $encryption, self::storageBase($config));
        $localArchive = '';
        $ext = '.zip';
        if ($archive['ok'] && !empty($archive['body'])) {
            $ext = (string) ($archive['extension'] ?? '.zip');
            $tmp = tempnam(sys_get_temp_dir(), 'jscert_');
            if ($tmp === false) {
                throw new \RuntimeException('فایل موقت ایجاد نشد.');
            }
            $localArchive = $tmp . $ext;
            rename($tmp, $localArchive);
            file_put_contents($localArchive, $archive['body']);
        } else {
            $storedSsl = SslBackupService::latestBackupFilePath($config, $sourceSslId);
            if ($storedSsl === null) {
                throw new \RuntimeException(
                    'خواندن گواهی از سرور مبدأ (' . $sourceCertPath . '): '
                    . ($archive['error'] ?? 'ناموفق')
                    . ' — روی VPS مبدأ مسیر را بررسی کنید یا از منوی بکاپ SSL یک‌بار بکاپ بگیرید.'
                );
            }
            $localArchive = $storedSsl;
            $ext = str_ends_with(strtolower($storedSsl), '.tar.gz') ? '.tar.gz' : '.zip';
            self::log(
                $job,
                'پوشه گواهی روی سرور مبدأ در دسترس نبود؛ استفاده از آخرین بکاپ SSL JaySub: ' . basename($storedSsl),
            );
        }

        $remoteArchive = '/tmp/jaysub-certs' . $ext;
        $up = SshRemoteZip::uploadLocalFile($target, $encryption, $localArchive, $remoteArchive, self::storageBase($config));
        if ($archive['ok'] ?? false) {
            @unlink($localArchive);
        }
        if (!$up['ok']) {
            throw new \RuntimeException('آپلود گواهی به سرور جدید: ' . ($up['error'] ?? 'ناموفق'));
        }

        $sslScript = SslMigrationTransfer::remoteExtractAndVerifyScript($certPath, $remoteArchive, $ext);
        $extract = 'bash -lc ' . escapeshellarg($sslScript);
        $ex = self::runRemoteChecked($job, $target, $encryption, $extract, 300, $config, 'انتقال SSL');
        self::logRemoteOutput($job, $ex);
        self::assertRemoteShellSuccess($ex, 'انتقال و اعتبارسنجی SSL');
        self::log($job, 'گواهی‌ها در ' . $certPath . ' منتقل و با openssl بررسی شدند.');
        self::finishStep($job, 'ssl_copy', $config);

        // 4) آخرین بک‌آپ JaySub → جایگزینی دیتابیس x-ui روی VPS جدید (همان تنظیمات پنل قدیم)
        self::beginStep($job, 'restore_backup', $config);
        XuiMigrationRestore::assertLocalSqliteBackup($backupPath);
        $remoteBackup = XuiMigrationRestore::remoteBackupPath(basename($backupPath));
        self::log($job, 'بک‌آپ JaySub: ' . basename($backupPath));
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

        $restoreCmd = 'bash -lc ' . escapeshellarg(
            XuiMigrationRestore::remoteRestoreScript($remoteBackup)
        );
        $rest = self::runRemoteChecked($job, $target, $encryption, $restoreCmd, 300, $config, 'بازگردانی DB');
        self::logRemoteOutput($job, $rest);
        self::assertRemoteShellSuccess($rest, 'بازگردانی بک‌آپ روی x-ui');
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

        $installer = '/tmp/jaysub-xui-installer.sh';

        return 'set -euo pipefail; '
            . 'export DEBIAN_FRONTEND=noninteractive XUI_NONINTERACTIVE=1 XUI_DB_TYPE=sqlite XUI_SSL_MODE=none XUI_ENABLE_FAIL2BAN=false; '
            . 'wait_apt() { local max=600 w=0; '
            . 'while fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 '
            . '|| fuser /var/lib/apt/lists/lock >/dev/null 2>&1 '
            . '|| pgrep -x apt-get >/dev/null 2>&1 || pgrep -x apt >/dev/null 2>&1; do '
            . 'if [ "$w" -ge "$max" ]; then echo "apt lock held after ${max}s" >&2; return 1; fi; '
            . 'echo "Waiting for apt lock (${w}s)..." >&2; sleep 5; w=$((w+5)); done; }; '
            . 'LOG=/tmp/jaysub-xui-install.log; '
            . 'xui_binary_ready() { [ -x /usr/local/x-ui/x-ui ] && file /usr/local/x-ui/x-ui 2>/dev/null | grep -q ELF; }; '
            . 'xui_unit_ready() { [ -f /etc/systemd/system/x-ui.service ] || systemctl cat x-ui >/dev/null 2>&1; }; '
            . 'xui_db_ready() { for c in /etc/x-ui/x-ui.db /usr/local/x-ui/x-ui.db; do [ -f "$c" ] && return 0; done; return 1; }; '
            . 'xui_post_install() { systemctl daemon-reload 2>/dev/null || true; '
            . 'systemctl enable x-ui 2>/dev/null || true; '
            . 'systemctl restart x-ui 2>/dev/null || true; '
            . 'if ! systemctl is-active --quiet x-ui 2>/dev/null; then '
            . 'command -v x-ui >/dev/null && x-ui start 2>/dev/null || true; sleep 6; fi; '
            . 'if [ -x /usr/local/x-ui/x-ui ]; then /usr/local/x-ui/x-ui migrate 2>/dev/null || true; fi; '
            . 'if [ -x /usr/bin/x-ui ]; then /usr/bin/x-ui migrate 2>/dev/null || true; fi; sleep 2; }; '
            . 'run_install() { wait_apt; rm -f "$LOG"; '
            . 'curl -fsSL ' . escapeshellarg($installSh) . ' -o ' . escapeshellarg($installer) . ' || return 1; '
            . 'bash ' . escapeshellarg($installer) . ' '
            . escapeshellarg($ver) . ' >>"$LOG" 2>&1; }; '
            . 'attempt=1; while [ "$attempt" -le 2 ]; do '
            . 'if run_install && xui_binary_ready && xui_unit_ready; then xui_post_install; '
            . 'if xui_binary_ready && xui_unit_ready && systemctl is-active --quiet x-ui 2>/dev/null; then '
            . 'tail -n 180 "$LOG" 2>/dev/null || true; '
            . 'xui_db_ready || echo "Note: x-ui.db will be created from JaySub backup on restore step." >&2; exit 0; fi; fi; '
            . 'if [ "$attempt" -eq 2 ]; then break; fi; '
            . 'echo "Retrying x-ui install (attempt 2/2)..." >&2; sleep 15; attempt=2; '
            . 'done; '
            . 'tail -n 180 "$LOG" 2>/dev/null || true; '
            . 'if grep -qiE "Failed to extract|invalid compressed data|Error is not recoverable|cannot access .x-ui" "$LOG" 2>/dev/null; then '
            . 'echo "x-ui install failed (archive extract). See log above." >&2; exit 1; fi; '
            . 'if ! xui_binary_ready; then echo "x-ui binary not found after install" >&2; exit 1; fi; '
            . 'echo "x-ui systemd unit missing — install incomplete (see log)" >&2; exit 1';
    }

    public static function normalizeInstallMode(string $mode): string
    {
        return strtolower(trim($mode)) === 'offline' ? 'offline' : 'online';
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $config
     * @return array{ok:bool, stdout?:string, stderr?:string, error?:string, exit_code?:int}
     */
    private static function runRemoteChecked(
        array &$job,
        array $target,
        Encryption $encryption,
        string $cmd,
        int $timeout,
        array $config,
        string $label,
    ): array {
        self::log($job, '[SSH] ' . $label . '…');
        self::touch($job, $config);

        return SshRemoteZip::runRemoteShell($target, $encryption, $cmd, $timeout, self::storageBase($config));
    }

    /** @param array{ok?:bool, stdout?:string, stderr?:string, error?:string, exit_code?:int} $result */
    private static function assertRemoteShellSuccess(array $result, string $label): void
    {
        if ($result['ok'] ?? false) {
            return;
        }
        $detail = trim((string) ($result['error'] ?? ''));
        if ($detail === '') {
            $detail = self::remoteCombinedOutput($result);
        }
        $code = (int) ($result['exit_code'] ?? -1);
        throw new \RuntimeException(
            $label . ' ناموفق (exit ' . $code . '): ' . mb_substr($detail, 0, 2000)
        );
    }

    /** @param array{stdout?:string, stderr?:string} $result */
    private static function remoteCombinedOutput(array $result): string
    {
        return trim(((string) ($result['stdout'] ?? '')) . "\n" . ((string) ($result['stderr'] ?? '')));
    }

    /**
     * @param array<string, mixed> $job
     * @param array{stdout?:string, stderr?:string} $result
     */
    private static function logRemoteOutput(array &$job, array $result): void
    {
        $out = self::remoteCombinedOutput($result);
        if ($out === '') {
            return;
        }
        foreach (preg_split('/\r?\n/', $out) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                self::log($job, $line);
            }
        }
    }

    /**
     * Secondary guard when remote bash exited 0 but printed a known failure line.
     * Must not match offline/online success messages (e.g. MainPID verification text).
     */
    public static function remoteOutputIndicatesXuiInstallFailure(string $output): bool
    {
        if ($output === '') {
            return false;
        }

        if (str_contains($output, 'Offline 3x-ui v3.4.2 installation verified')) {
            return false;
        }
        if (str_contains($output, 'JaySub post-install verify: binary')) {
            return false;
        }

        $needles = [
            'Failed to extract the x-ui release archive',
            'x-ui binary not found after install',
            'x-ui systemd unit missing',
            'Unexpected remote x-ui package path',
            'x-ui install failed (archive extract)',
            'Transferred archive missing',
            'Invalid x-ui release layout',
            'x-ui service or database failed after offline install',
            'x-ui service failed stability check',
            'Download x-ui',
            ' failed, please check if the version exists',
            'Downloading x-ui failed',
            'apt lock held after',
            'invalid compressed data',
            'Error is not recoverable',
            'cannot access \'x-ui\'',
            'Only management script /usr/bin/x-ui present',
            'x-ui service health check failed after install',
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
