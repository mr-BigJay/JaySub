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
            if (($job['migration_outcome'] ?? '') === 'verified_only') {
                $job['status'] = 'partial';
                $summary = (string) ($job['result_summary'] ?? 'فقط بررسی شد؛ SSL و Restore انجام نشد.');
                self::log($job, $summary);
            } else {
                $job['status'] = 'done';
                self::log($job, 'انتقال با موفقیت تمام شد.');
            }
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

        $probeCmd = 'bash -lc ' . escapeshellarg(MigrationExistingInstallProbe::remoteProbeScript());
        $probe = self::runRemoteChecked($job, $target, $encryption, $probeCmd, 120, $config, 'بررسی نصب موجود x-ui');
        self::logRemoteOutput($job, $probe);
        $probeOut = self::remoteCombinedOutput($probe);
        if (!$probe['ok']) {
            if (MigrationExistingInstallProbe::outputIndicatesPartial($probeOut)) {
                throw new \RuntimeException(
                    'نصب ناقص x-ui روی مقصد — JaySub چیزی را حذف یا بازنویسی نمی‌کند. راهنما: docs/MIGRATION-RECOVERY.md'
                );
            }

            throw new \RuntimeException(
                'x-ui روی مقصد ناسالم است — نصب/بازنویسی خودکار انجام نمی‌شود. وضعیت سرویس و باینری را بررسی کنید.'
            );
        }
        $skipInstall = MigrationExistingInstallProbe::outputIndicatesHealthyExisting($probeOut);
        $job['target_had_healthy_xui'] = $skipInstall;
        if ($skipInstall) {
            self::log($job, 'نصب رد شد: x-ui سالم از قبل روی مقصد است (بدون حذف، بازنویسی یا اجرای install.sh).');
            self::touch($job, $config);
        } elseif ($mode === 'offline') {
            self::log($job, 'نصب آفلاین v3.4.2؛ بسته از cache JaySub (بدون GitHub روی مقصد).');
            self::touch($job, $config);
            $pre = 'bash -lc ' . escapeshellarg(MigrationTargetPrerequisites::remoteOfflineInstallPrerequisiteScript());
            $preFlight = self::runRemoteChecked($job, $target, $encryption, $pre, 60, $config, 'پیش‌نیاز نصب آفلاین');
            self::logRemoteOutput($job, $preFlight);
            self::assertRemoteShellSuccess($preFlight, 'پیش‌نیازهای Ubuntu/Debian برای نصب آفلاین');
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
            $remoteArchive = OfflineXuiInstaller::remoteArchivePath($arch);
            $job['xui_remote_archive'] = $remoteArchive;
            self::log($job, 'مسیر SCP مقصد: ' . $remoteArchive);
            self::log($job, 'SHA-256 تأیید شد؛ SCP به مقصد...');
            self::touch($job, $config);
            $transfer = SshRemoteZip::uploadLocalFile(
                $target, $encryption, $archive['path'], $remoteArchive, self::storageBase($config)
            );
            if (!$transfer['ok']) {
                throw new \RuntimeException('انتقال بسته آفلاین: ' . ($transfer['error'] ?? 'ناموفق'));
            }
            $shaCheck = 'bash -lc ' . escapeshellarg(
                'printf \'%s  %s\\n\' ' . escapeshellarg($archive['sha256']) . ' ' . escapeshellarg($remoteArchive)
                . ' | sha256sum -c -'
            );
            $shaRemote = self::runRemoteChecked($job, $target, $encryption, $shaCheck, 60, $config, 'تأیید SHA-256 بسته روی مقصد');
            self::assertRemoteShellSuccess($shaRemote, 'تأیید SHA-256 بسته آفلاین روی مقصد');
            $installCmd = 'bash -lc ' . escapeshellarg(
                OfflineXuiInstaller::remoteInstallScript($arch, $remoteArchive)
            );
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
        } else {
            self::log($job, 'نصب آنلاین: install.sh پین‌شده + آرگومان v3.4.2؛ تأیید checksum بسته در لاگ upstream.');
            self::touch($job, $config);
            $installCmd = 'bash -lc ' . escapeshellarg(OnlineXuiInstaller::remoteInstallScript());
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
        }
        $verifyScript = $skipInstall
            ? MigrationPostInstallVerify::remoteVerifyExistingScript()
            : MigrationPostInstallVerify::remoteVerifyScript();
        $verifyCmd = 'bash -lc ' . escapeshellarg($verifyScript);
        $verify = self::runRemoteChecked($job, $target, $encryption, $verifyCmd, 300, $config, 'تأیید سلامت x-ui');
        self::logRemoteOutput($job, $verify);
        self::assertRemoteShellSuccess($verify, 'تأیید نصب 3x-ui');
        self::finishStep($job, 'install_xui', $config);

        if ($skipInstall && !self::confirmTargetOverwrite($job)) {
            self::log(
                $job,
                'انتقال SSL و بازگردانی DB انجام نشد — x-ui سالم روی مقصد است و تأیید صریح بازنویسی در wizard داده نشده است.',
            );
            self::skipStep($job, 'ssl_copy', 'رد شد: x-ui موجود — بدون تأیید بازنویسی SSL', $config);
            self::skipStep($job, 'restore_backup', 'رد شد: x-ui موجود — بدون تأیید بازنویسی DB', $config);
            $job['migration_outcome'] = 'verified_only';
            $job['result_summary'] = 'فقط بررسی شد؛ SSL و Restore انجام نشد.';

            return;
        }

        $jobIdForSnapshot = MigrationTargetSnapshot::sanitizeJobId((string) ($job['id'] ?? ''));
        $snapCmd = 'bash -lc ' . escapeshellarg(
            MigrationTargetSnapshot::remoteCreateSnapshotScript($certPath, $jobIdForSnapshot)
        );
        $snap = self::runRemoteChecked($job, $target, $encryption, $snapCmd, 120, $config, 'نسخه پشتیبان مقصد قبل از SSL/DB');
        self::logRemoteOutput($job, $snap);
        self::assertRemoteShellSuccess($snap, 'نسخه پشتیبان SSL و DB روی مقصد');
        $job['target_snapshot_id'] = $jobIdForSnapshot;
        $job['target_snapshot_manifest'] = MigrationTargetSnapshot::manifestPathForJob($jobIdForSnapshot);
        $job['target_snapshot_status'] = 'created';
        self::touch($job, $config);

        try {
            self::runSslAndRestoreSteps(
                $job,
                $config,
                $encryption,
                $target,
                $source,
                $certPath,
                $backupPath,
                $jobIdForSnapshot,
            );
        } catch (\Throwable $e) {
            self::rollbackTargetSnapshot($job, $target, $encryption, $config, $jobIdForSnapshot);
            throw $e;
        }
    }

    /**
     * Post-snapshot SSL transfer + DB restore (any failure triggers outer recovery rollback).
     *
     * @param array<string, mixed> $job
     * @param array<string, mixed> $config
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     */
    private static function runSslAndRestoreSteps(
        array &$job,
        array $config,
        Encryption $encryption,
        array $target,
        array $source,
        string $certPath,
        string $backupPath,
        string $jobIdForSnapshot,
    ): void {
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
            $storedSsl = SslBackupService::latestBackupFilePath(
                $config,
                (int) ($job['source_ssl_server_id'] ?? 0),
            );
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

        $sslScript = SslMigrationTransfer::remoteExtractAndVerifyScript(
            $certPath,
            $remoteArchive,
            $ext,
            $jobIdForSnapshot,
        );
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
        $dbSslCmd = 'bash -lc ' . escapeshellarg(SslMigrationTransfer::remoteVerifyDbCertPathsScript($certPath));
        $dbSsl = self::runRemoteChecked($job, $target, $encryption, $dbSslCmd, 120, $config, 'تطابق SSL با دیتابیس');
        self::logRemoteOutput($job, $dbSsl);
        self::assertRemoteShellSuccess($dbSsl, 'تطابق مسیرها و جفت cert/key در دیتابیس x-ui');
        self::log($job, 'تنظیمات پنل از بک‌آپ اعمال شد — همان مسیر/کاربر پنل قبلی (DNS را به IP جدید بزنید).');
        self::finishStep($job, 'restore_backup', $config);
        $job['target_snapshot_status'] = 'committed';
        self::touch($job, $config);
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
     * @param array<string, mixed> $config
     */
    private static function skipStep(array &$job, string $id, string $message, array $config): void
    {
        self::setStep($job, $id, 'skipped', $message);
        self::log($job, $message);
        self::touch($job, $config);
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $target
     * @param array<string, mixed> $config
     */
    private static function rollbackTargetSnapshot(
        array &$job,
        array $target,
        Encryption $encryption,
        array $config,
        string $jobIdForSnapshot,
    ): void {
        if (($job['target_snapshot_status'] ?? '') !== 'created') {
            return;
        }
        $cmd = 'bash -lc ' . escapeshellarg(
            MigrationTargetSnapshot::remoteRollbackSnapshotScript($jobIdForSnapshot)
        );
        $rb = self::runRemoteChecked($job, $target, $encryption, $cmd, 180, $config, 'بازگردانی snapshot مقصد');
        self::logRemoteOutput($job, $rb);
        $out = self::remoteCombinedOutput($rb);
        if (($rb['ok'] ?? false) && str_contains($out, 'JaySub migration rollback: SUCCESS')) {
            $job['target_snapshot_status'] = 'rolled_back';
            self::log($job, 'JaySub migration recovery: rollback SUCCESS (snapshot job=' . $jobIdForSnapshot . ').');
        } else {
            $job['target_snapshot_status'] = 'rollback_failed';
            self::log(
                $job,
                'JaySub migration recovery: rollback FAILED — VPS را دستی بررسی کنید (docs/MIGRATION-RECOVERY.md).',
            );
        }
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

    /**
     * @deprecated Use {@see OnlineXuiInstaller::remoteInstallScript()} — kept for tests comparing legacy URL.
     */
    public static function remoteXuiInstallScript(string $version = 'v3.4.2'): string
    {
        $ver = preg_replace('/[^a-zA-Z0-9._-]/', '', $version) ?: 'v3.4.2';
        if ($ver !== OnlineXuiInstaller::TARGET_VERSION) {
            throw new \InvalidArgumentException('JaySub migration only supports ' . OnlineXuiInstaller::TARGET_VERSION);
        }

        return OnlineXuiInstaller::remoteInstallScript();
    }

    public static function normalizeInstallMode(string $mode): string
    {
        return strtolower(trim($mode)) === 'offline' ? 'offline' : 'online';
    }

    /** Explicit wizard opt-in to replace SSL files and x-ui.db on an already-healthy target. */
    public static function confirmTargetOverwrite(array $job): bool
    {
        return !empty($job['confirm_target_overwrite']);
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
            'Partial x-ui install without /usr/local/x-ui/x-ui',
            'Could not download verified install.sh',
            'Official install.sh exited with error',
            'x-ui install log contains extract/binary errors',
            'x-ui panel binary missing after online install',
            'Official release archive checksum failed during online install',
            'Release v3.4.2 checksum not confirmed in install log',
            'Missing prerequisites on target for offline x-ui install',
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
