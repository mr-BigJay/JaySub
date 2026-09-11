<?php

declare(strict_types=1);

namespace App\Services;

final class BackupService
{
    /** @param array<string, mixed> $config */
    public static function storageDir(array $config): string
    {
        $base = (string) ($config['paths']['storage'] ?? dirname(__DIR__, 2) . '/storage');
        $dir = rtrim($base, '/') . '/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{filename:string, bytes:int, method:string}
     */
    public static function create(array $config): array
    {
        $dir = self::storageDir($config);
        $filename = 'jaysub-' . date('Y-m-d-His') . '.sql.gz';
        $path = $dir . '/' . $filename;

        $db = $config['database'] ?? [];
        $host = (string) ($db['host'] ?? '127.0.0.1');
        $port = (int) ($db['port'] ?? 3306);
        $name = (string) ($db['name'] ?? '');
        $user = (string) ($db['user'] ?? '');
        $pass = (string) ($db['password'] ?? '');

        if ($name === '' || $user === '') {
            throw new \RuntimeException('تنظیمات دیتابیس در config.php ناقص است.');
        }

        $method = 'php';
        if (self::commandExists('mysqldump')) {
            $method = 'mysqldump';
            $cmd = sprintf(
                'mysqldump --single-transaction --quick --host=%s --port=%d --user=%s %s 2>/dev/null | gzip -c > %s',
                escapeshellarg($host),
                $port,
                escapeshellarg($user),
                escapeshellarg($name),
                escapeshellarg($path)
            );
            $hadPwd = getenv('MYSQL_PWD');
            if ($pass !== '') {
                putenv('MYSQL_PWD=' . $pass);
            }
            $proc = proc_open(
                $cmd,
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes
            );
            if ($pass !== '') {
                if ($hadPwd !== false) {
                    putenv('MYSQL_PWD=' . $hadPwd);
                } else {
                    putenv('MYSQL_PWD');
                }
            }
            if (is_resource($proc)) {
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
                if ($code !== 0 || !is_file($path) || filesize($path) < 32) {
                    @unlink($path);
                    $method = 'php';
                }
            } else {
                $method = 'php';
            }
        }

        if ($method === 'php') {
            self::exportViaPhp($config, $path);
        }

        if (!is_file($path)) {
            throw new \RuntimeException('ایجاد فایل پشتیبان ناموفق بود.');
        }

        self::pruneOld($dir, 10);

        return [
            'filename' => $filename,
            'bytes' => (int) filesize($path),
            'method' => $method,
        ];
    }

    /** @param array<string, mixed> $config */
    private static function exportViaPhp(array $config, string $gzipPath): void
    {
        $pdo = \App\Core\Database::pdo();
        $sql = "-- JaySub backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $table = (string) $table;
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch();
            if (!is_array($create)) {
                continue;
            }
            $sql .= "\nDROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $create['Create Table'] . ";\n";
            $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
            while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
                $cols = array_map(static fn ($c) => '`' . str_replace('`', '``', (string) $c) . '`', array_keys($row));
                $vals = [];
                foreach ($row as $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } elseif (is_int($v) || is_float($v)) {
                        $vals[] = (string) $v;
                    } else {
                        $vals[] = $pdo->quote((string) $v);
                    }
                }
                $sql .= 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
            }
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        $gz = gzopen($gzipPath, 'wb9');
        if ($gz === false) {
            throw new \RuntimeException('gzip باز نشد');
        }
        gzwrite($gz, $sql);
        gzclose($gz);
    }

    /** @return list<array{filename:string, bytes:int, mtime:int}> */
    public static function listFiles(array $config): array
    {
        $dir = self::storageDir($config);
        $files = glob($dir . '/jaysub-*.sql.gz') ?: [];
        $out = [];
        foreach ($files as $path) {
            $name = basename($path);
            $out[] = [
                'filename' => $name,
                'bytes' => (int) filesize($path),
                'mtime' => (int) filemtime($path),
            ];
        }
        usort($out, static fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    /** @param array<string, mixed> $config */
    public static function resolveDownloadPath(array $config, string $filename): ?string
    {
        if (!preg_match('/^jaysub-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$/', $filename)) {
            return null;
        }
        $path = self::storageDir($config) . '/' . $filename;
        return is_file($path) ? $path : null;
    }

    private static function commandExists(string $cmd): bool
    {
        $out = [];
        $code = 0;
        @exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $out, $code);
        return $code === 0 && $out !== [];
    }

    private static function pruneOld(string $dir, int $keep): void
    {
        $files = glob($dir . '/jaysub-*.sql.gz') ?: [];
        usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }
}
