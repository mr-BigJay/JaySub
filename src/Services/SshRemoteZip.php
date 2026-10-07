<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Encryption;

final class SshRemoteZip
{
    /**
     * @param array<string, mixed> $server
     * @return array{ok:bool, body?:string, error?:string, extension?:string}
     */
    public static function zipCertDirectory(array $server, Encryption $encryption, ?string $storageBase = null): array
    {
        $host = trim((string) ($server['host'] ?? ''));
        $user = trim((string) ($server['ssh_username'] ?? 'root'));
        $port = (int) ($server['ssh_port'] ?? 22);
        $certPath = trim((string) ($server['cert_path'] ?? '/root/cert'));
        if ($host === '' || $user === '') {
            return ['ok' => false, 'error' => 'میزبان یا کاربر SSH خالی است.'];
        }

        $parent = str_replace("'", '', dirname($certPath));
        $folder = str_replace("'", '', basename($certPath));
        $remoteCmd = self::remoteArchiveCommand($parent, $folder);

        try {
            $secret = $encryption->decrypt((string) $server['ssh_secret_encrypted']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'رمز/کلید SSH ذخیره‌شده قابل خواندن نیست.'];
        }

        $sshOpts = self::sshOptionFlags($storageBase);
        $authType = (string) ($server['auth_type'] ?? 'password');
        if ($authType === 'key') {
            return self::runSshKey($host, $port, $user, $secret, $remoteCmd, $sshOpts, $storageBase);
        }

        return self::runSshPassword($host, $port, $user, $secret, $remoteCmd, $sshOpts, $storageBase);
    }

    public static function sshStateDirectory(?string $storageBase = null): string
    {
        $base = $storageBase ?? dirname(__DIR__, 2) . '/storage';
        $dir = rtrim($base, '/') . '/ssl-ssh';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $known = $dir . '/known_hosts';
        if (!is_file($known)) {
            file_put_contents($known, '');
            chmod($known, 0600);
        }

        return $dir;
    }

    private static function remoteArchiveCommand(string $parent, string $folder): string
    {
        $script = 'P=' . escapeshellarg($parent) . '; F=' . escapeshellarg($folder) . '; '
            . 'if [ ! -d "$P/$F" ]; then '
            . 'echo "پوشه گواهی روی سرور مبدأ وجود ندارد: $P/$F" >&2; '
            . 'echo "محتوای $P:" >&2; ls -la "$P" 2>&1 | head -25 >&2; '
            . 'exit 2; '
            . 'fi; '
            . 'cd "$P" && if command -v zip >/dev/null 2>&1; then exec zip -r - -- "$F";'
            . ' elif command -v tar >/dev/null 2>&1; then exec tar -czf - -- "$F";'
            . ' else echo "روی سرور remote نه zip و نه tar نصب است (apt install zip)" >&2; exit 127; fi';

        return 'bash -lc ' . escapeshellarg($script);
    }

    private static function sshOptionFlags(?string $storageBase): string
    {
        $stateDir = self::sshStateDirectory($storageBase);
        $known = $stateDir . '/known_hosts';

        return sprintf(
            '-o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=%s -o GlobalKnownHostsFile=/dev/null -o ConnectTimeout=20',
            escapeshellarg($known)
        );
    }

    /**
     * @return array{ok:bool, body?:string, error?:string, extension?:string}
     */
    private static function runSshKey(
        string $host,
        int $port,
        string $user,
        string $privateKey,
        string $remoteCmd,
        string $sshOpts,
        ?string $storageBase,
    ): array {
        $keyFile = tempnam(sys_get_temp_dir(), 'jssk_');
        if ($keyFile === false) {
            return ['ok' => false, 'error' => 'ایجاد فایل موقت کلید ناموفق بود.'];
        }
        file_put_contents($keyFile, $privateKey);
        chmod($keyFile, 0600);
        try {
            $ssh = sprintf(
                'ssh -i %s -p %d %s -o BatchMode=yes %s@%s %s',
                escapeshellarg($keyFile),
                $port,
                $sshOpts,
                escapeshellarg($user),
                escapeshellarg($host),
                escapeshellarg($remoteCmd)
            );

            return self::execCapture($ssh, $storageBase, $host);
        } finally {
            @unlink($keyFile);
        }
    }

    /**
     * @return array{ok:bool, body?:string, error?:string, extension?:string}
     */
    private static function runSshPassword(
        string $host,
        int $port,
        string $user,
        string $password,
        string $remoteCmd,
        string $sshOpts,
        ?string $storageBase,
    ): array {
        if (!self::commandExists('sshpass')) {
            return ['ok' => false, 'error' => 'روی سرور JaySub بسته sshpass نصب نیست (برای SSH با پسورد). یا احراز هویت با کلید خصوصی استفاده کنید.'];
        }
        $ssh = sprintf(
            'ssh -p %d %s %s@%s %s',
            $port,
            $sshOpts,
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg($remoteCmd)
        );
        $cmd = 'sshpass -e ' . $ssh;
        $prev = getenv('SSHPASS');
        putenv('SSHPASS=' . $password);
        try {
            return self::execCapture($cmd, $storageBase, $host);
        } finally {
            if ($prev !== false) {
                putenv('SSHPASS=' . $prev);
            } else {
                putenv('SSHPASS');
            }
        }
    }

    /**
     * @return array{ok:bool, body?:string, error?:string, extension?:string}
     */
    private static function execCapture(string $command, ?string $storageBase, ?string $retryHost = null): array
    {
        $stateDir = self::sshStateDirectory($storageBase);
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['HOME'] = $stateDir;
        $env['SSH_AUTH_SOCK'] = '';

        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = proc_open($command, $descriptors, $pipes, $stateDir, $env);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'اجرای SSH ناموفق بود.'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $stderrStr = is_string($stderr) ? $stderr : '';
        $archive = self::detectArchive(is_string($stdout) ? $stdout : '');
        if ($archive === null) {
            if ($retryHost !== null && self::isHostKeyChangedError($stderrStr . $stdout)) {
                self::removeKnownHost($retryHost, $storageBase);

                return self::execCapture($command, $storageBase, null);
            }
            $err = self::formatSshError(trim($stderrStr), $code);

            return ['ok' => false, 'error' => $err];
        }

        if ($code !== 0) {
            $err = trim((string) $stderr);
            if ($err !== '' && !self::stderrIsBenign($err)) {
                return ['ok' => false, 'error' => self::formatSshError($err, $code)];
            }
        }

        return [
            'ok' => true,
            'body' => $stdout,
            'extension' => $archive['extension'],
        ];
    }

    /** @return array{extension:string}|null */
    private static function detectArchive(string $stdout): ?array
    {
        if (strlen($stdout) < 4) {
            return null;
        }
        if (str_starts_with($stdout, 'PK')) {
            return ['extension' => '.zip'];
        }
        if ($stdout[0] === "\x1f" && $stdout[1] === "\x8b") {
            return ['extension' => '.tar.gz'];
        }

        return null;
    }

    private static function stderrIsBenign(string $stderr): bool
    {
        if (str_contains($stderr, 'Warning: Permanently added')) {
            return true;
        }
        if (str_contains($stderr, 'Could not create directory')) {
            return false;
        }

        return false;
    }

    private static function formatSshError(string $stderr, int $code): string
    {
        if ($stderr === '') {
            return $code === 127
                ? 'روی سرور remote دستور zip/tar پیدا نشد — روی آن سرور: apt install -y zip'
                : 'SSH/بکاپ ناموفق (کد ' . $code . ').';
        }
        if (str_contains($stderr, 'REMOTE HOST IDENTIFICATION HAS CHANGED')
            || str_contains($stderr, 'Host key verification failed')) {
            return 'کلید SSH این VPS عوض شده (معمولاً بعد از rebuild). روی سرور JaySub اجرا کنید: '
                . 'ssh-keygen -f storage/ssl-ssh/known_hosts -R «IP-سرور-جدید» — سپس انتقال را دوباره بزنید. '
                . '(نسخهٔ جدید JaySub این کار را خودکار هم انجام می‌دهد.)';
        }
        if (str_contains($stderr, 'Permission denied') && str_contains($stderr, '.ssh')) {
            return 'خطای SSH روی JaySub (known_hosts). پوشه storage/ssl-ssh باید برای www-data قابل نوشتن باشد. جزئیات: ' . mb_substr($stderr, 0, 400);
        }
        if (str_contains($stderr, 'zip: command not found')) {
            return 'روی سرور remote بسته zip نصب نیست. نصب کنید: apt install -y zip — یا با به‌روزرسانی JaySub از tar به‌صورت خودکار استفاده می‌شود.';
        }
        if (str_contains($stderr, 'Cannot stat') || str_contains($stderr, 'پوشه گواهی روی سرور مبدأ وجود ندارد')) {
            return 'مسیر گواهی SSL روی سرور مبدأ پیدا نشد (cert_path). در JaySub → بکاپ SSL مسیر را با خروجی '
                . 'ls روی همان VPS هماهنگ کنید، یا یک‌بار بکاپ SSL بگیرید تا از نسخهٔ ذخیره‌شده استفاده شود. '
                . mb_substr($stderr, 0, 800);
        }

        return mb_substr($stderr, 0, 2000);
    }

    private static function commandExists(string $cmd): bool
    {
        $out = [];
        $code = 0;
        @exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $out, $code);

        return $code === 0 && $out !== [];
    }

    /**
     * @param array<string, mixed> $server
     * @return array{ok:bool, stdout?:string, stderr?:string, error?:string, exit_code?:int}
     */
    public static function runRemoteShell(
        array $server,
        Encryption $encryption,
        string $remoteCmd,
        int $timeoutSeconds = 120,
        ?string $storageBase = null,
    ): array {
        $host = trim((string) ($server['host'] ?? ''));
        $user = trim((string) ($server['ssh_username'] ?? 'root'));
        $port = (int) ($server['ssh_port'] ?? 22);
        if ($host === '' || $user === '') {
            return ['ok' => false, 'error' => 'میزبان یا کاربر SSH خالی است.'];
        }

        try {
            $secret = $encryption->decrypt((string) $server['ssh_secret_encrypted']);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'رمز/کلید SSH ذخیره‌شده قابل خواندن نیست.'];
        }

        $sshOpts = self::sshOptionFlags($storageBase);
        $wrapped = 'timeout ' . max(10, $timeoutSeconds) . ' ' . $remoteCmd;
        $authType = (string) ($server['auth_type'] ?? 'password');
        if ($authType === 'key') {
            return self::runSshKeyText($host, $port, $user, $secret, $wrapped, $sshOpts, $storageBase);
        }

        return self::runSshPasswordText($host, $port, $user, $secret, $wrapped, $sshOpts, $storageBase);
    }

    /**
     * @param array<string, mixed> $server
     * @return array{ok:bool, error?:string}
     */
    public static function uploadLocalFile(
        array $server,
        Encryption $encryption,
        string $localPath,
        string $remotePath,
        ?string $storageBase = null,
    ): array {
        if (!is_file($localPath)) {
            return ['ok' => false, 'error' => 'فایل محلی وجود ندارد.'];
        }
        $host = trim((string) ($server['host'] ?? ''));
        $user = trim((string) ($server['ssh_username'] ?? 'root'));
        $port = (int) ($server['ssh_port'] ?? 22);
        if ($host === '' || $user === '') {
            return ['ok' => false, 'error' => 'میزبان یا کاربر SSH خالی است.'];
        }

        try {
            $secret = $encryption->decrypt((string) $server['ssh_secret_encrypted']);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'رمز/کلید SSH ذخیره‌شده قابل خواندن نیست.'];
        }

        $sshOpts = self::sshOptionFlags($storageBase);
        $authType = (string) ($server['auth_type'] ?? 'password');
        $remote = escapeshellarg($remotePath);
        $local = escapeshellarg($localPath);

        if ($authType === 'key') {
            $keyFile = tempnam(sys_get_temp_dir(), 'jssk_');
            if ($keyFile === false) {
                return ['ok' => false, 'error' => 'فایل موقت کلید ناموفق'];
            }
            file_put_contents($keyFile, $secret);
            chmod($keyFile, 0600);
            $scp = sprintf(
                'scp -i %s -P %d %s -o BatchMode=yes %s %s@%s:%s',
                escapeshellarg($keyFile),
                $port,
                $sshOpts,
                $local,
                escapeshellarg($user),
                escapeshellarg($host),
                $remote
            );
            try {
                $r = self::execText($scp, $storageBase, $host);
            } finally {
                @unlink($keyFile);
            }
        } else {
            if (!self::commandExists('sshpass')) {
                return ['ok' => false, 'error' => 'sshpass برای SCP با پسورد لازم است.'];
            }
            $scp = sprintf(
                'scp -P %d %s %s %s@%s:%s',
                $port,
                $sshOpts,
                $local,
                escapeshellarg($user),
                escapeshellarg($host),
                $remote
            );
            $scp = 'sshpass -e ' . $scp;
            $prev = getenv('SSHPASS');
            putenv('SSHPASS=' . $secret);
            try {
                $r = self::execText($scp, $storageBase, $host);
            } finally {
                if ($prev !== false) {
                    putenv('SSHPASS=' . $prev);
                } else {
                    putenv('SSHPASS');
                }
            }
        }

        if (!($r['ok'] ?? false)) {
            return ['ok' => false, 'error' => $r['error'] ?? 'SCP ناموفق'];
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok:bool, stdout?:string, stderr?:string, error?:string, exit_code?:int}
     */
    private static function runSshKeyText(
        string $host,
        int $port,
        string $user,
        string $privateKey,
        string $remoteCmd,
        string $sshOpts,
        ?string $storageBase,
    ): array {
        $keyFile = tempnam(sys_get_temp_dir(), 'jssk_');
        if ($keyFile === false) {
            return ['ok' => false, 'error' => 'ایجاد فایل موقت کلید ناموفق بود.'];
        }
        file_put_contents($keyFile, $privateKey);
        chmod($keyFile, 0600);
        try {
            $ssh = sprintf(
                'ssh -i %s -p %d %s -o BatchMode=yes %s@%s %s',
                escapeshellarg($keyFile),
                $port,
                $sshOpts,
                escapeshellarg($user),
                escapeshellarg($host),
                escapeshellarg($remoteCmd)
            );

            return self::execText($ssh, $storageBase, $host);
        } finally {
            @unlink($keyFile);
        }
    }

    /**
     * @return array{ok:bool, stdout?:string, stderr?:string, error?:string, exit_code?:int}
     */
    private static function runSshPasswordText(
        string $host,
        int $port,
        string $user,
        string $password,
        string $remoteCmd,
        string $sshOpts,
        ?string $storageBase,
    ): array {
        if (!self::commandExists('sshpass')) {
            return ['ok' => false, 'error' => 'روی سرور JaySub بسته sshpass نصب نیست (برای SSH با پسورد). یا احراز هویت با کلید خصوصی استفاده کنید.'];
        }
        $ssh = sprintf(
            'ssh -p %d %s %s@%s %s',
            $port,
            $sshOpts,
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg($remoteCmd)
        );
        $cmd = 'sshpass -e ' . $ssh;
        $prev = getenv('SSHPASS');
        putenv('SSHPASS=' . $password);
        try {
            return self::execText($cmd, $storageBase, $host);
        } finally {
            if ($prev !== false) {
                putenv('SSHPASS=' . $prev);
            } else {
                putenv('SSHPASS');
            }
        }
    }

    private static function isHostKeyChangedError(string $text): bool
    {
        return str_contains($text, 'REMOTE HOST IDENTIFICATION HAS CHANGED')
            || str_contains($text, 'Host key verification failed');
    }

    private static function removeKnownHost(string $host, ?string $storageBase): void
    {
        $host = trim($host);
        if ($host === '') {
            return;
        }
        $known = self::sshStateDirectory($storageBase) . '/known_hosts';
        if (!is_file($known)) {
            return;
        }
        @exec(
            'ssh-keygen -f ' . escapeshellarg($known) . ' -R ' . escapeshellarg($host) . ' 2>/dev/null',
        );
    }

    /**
     * @return array{ok:bool, stdout?:string, stderr?:string, error?:string, exit_code?:int}
     */
    private static function execText(string $command, ?string $storageBase, ?string $retryHost = null): array
    {
        $result = self::execTextOnce($command, $storageBase);
        if ($result['ok'] || $retryHost === null) {
            return $result;
        }
        $blob = ($result['stderr'] ?? '') . ($result['stdout'] ?? '') . ($result['error'] ?? '');
        if (!self::isHostKeyChangedError($blob)) {
            return $result;
        }
        self::removeKnownHost($retryHost, $storageBase);

        return self::execTextOnce($command, $storageBase);
    }

    /**
     * @return array{ok:bool, stdout?:string, stderr?:string, error?:string, exit_code?:int}
     */
    private static function execTextOnce(string $command, ?string $storageBase): array
    {
        $stateDir = self::sshStateDirectory($storageBase);
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['HOME'] = $stateDir;
        $env['SSH_AUTH_SOCK'] = '';

        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = proc_open($command, $descriptors, $pipes, $stateDir, $env);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'اجرای SSH ناموفق بود.'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0) {
            $err = trim((string) $stderr);
            if ($err === '') {
                $err = trim((string) $stdout);
            }

            return [
                'ok' => false,
                'error' => self::formatSshError($err !== '' ? $err : 'SSH exit ' . $code, $code),
                'stdout' => is_string($stdout) ? $stdout : '',
                'stderr' => is_string($stderr) ? $stderr : '',
                'exit_code' => $code,
            ];
        }

        return [
            'ok' => true,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
            'exit_code' => $code,
        ];
    }
}
