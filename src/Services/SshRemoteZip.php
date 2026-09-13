<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Encryption;

final class SshRemoteZip
{
    /**
     * @param array<string, mixed> $server
     * @return array{ok:bool, body?:string, error?:string}
     */
    public static function zipCertDirectory(array $server, Encryption $encryption): array
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
        $remoteCmd = 'cd ' . escapeshellarg($parent) . ' && zip -r - ' . escapeshellarg($folder);

        try {
            $secret = $encryption->decrypt((string) $server['ssh_secret_encrypted']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'رمز/کلید SSH ذخیره‌شده قابل خواندن نیست.'];
        }

        $authType = (string) ($server['auth_type'] ?? 'password');
        if ($authType === 'key') {
            return self::runSshKey($host, $port, $user, $secret, $remoteCmd);
        }
        return self::runSshPassword($host, $port, $user, $secret, $remoteCmd);
    }

    /** @return array{ok:bool, body?:string, error?:string} */
    private static function runSshKey(string $host, int $port, string $user, string $privateKey, string $remoteCmd): array
    {
        $keyFile = tempnam(sys_get_temp_dir(), 'jssk_');
        if ($keyFile === false) {
            return ['ok' => false, 'error' => 'ایجاد فایل موقت کلید ناموفق بود.'];
        }
        file_put_contents($keyFile, $privateKey);
        chmod($keyFile, 0600);
        try {
            $ssh = sprintf(
                'ssh -i %s -p %d -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15 %s@%s %s',
                escapeshellarg($keyFile),
                $port,
                escapeshellarg($user),
                escapeshellarg($host),
                escapeshellarg($remoteCmd)
            );
            return self::execCapture($ssh);
        } finally {
            @unlink($keyFile);
        }
    }

    /** @return array{ok:bool, body?:string, error?:string} */
    private static function runSshPassword(string $host, int $port, string $user, string $password, string $remoteCmd): array
    {
        if (!self::commandExists('sshpass')) {
            return ['ok' => false, 'error' => 'روی سرور JaySub بسته sshpass نصب نیست (برای SSH با پسورد). یا احراز هویت با کلید خصوصی استفاده کنید.'];
        }
        $ssh = sprintf(
            'ssh -p %d -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15 %s@%s %s',
            $port,
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg($remoteCmd)
        );
        $cmd = 'sshpass -e ' . $ssh;
        $prev = getenv('SSHPASS');
        putenv('SSHPASS=' . $password);
        try {
            return self::execCapture($cmd);
        } finally {
            if ($prev !== false) {
                putenv('SSHPASS=' . $prev);
            } else {
                putenv('SSHPASS');
            }
        }
    }

    /** @return array{ok:bool, body?:string, error?:string} */
    private static function execCapture(string $command): array
    {
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = proc_open($command, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'اجرای SSH ناموفق بود.'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0 || $stdout === false || $stdout === '') {
            $err = trim((string) $stderr);
            if ($err === '' && is_string($stdout) && str_starts_with($stdout, 'PK')) {
                // zip ok despite stderr noise
            } else {
                return ['ok' => false, 'error' => $err !== '' ? $err : 'خروجی zip خالی است یا zip روی سرور remote نصب نیست.'];
            }
        }
        if (!is_string($stdout) || strlen($stdout) < 4 || !str_starts_with($stdout, 'PK')) {
            return ['ok' => false, 'error' => 'پاسخ SSH شبیه فایل zip نیست.'];
        }
        return ['ok' => true, 'body' => $stdout];
    }

    private static function commandExists(string $cmd): bool
    {
        $out = [];
        $code = 0;
        @exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $out, $code);
        return $code === 0 && $out !== [];
    }
}
