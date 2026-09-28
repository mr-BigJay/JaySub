<?php

declare(strict_types=1);

namespace App\Services;

final class MigrationJobStore
{
    /** @param array<string, mixed> $config */
    public static function directory(array $config): string
    {
        $base = (string) ($config['paths']['storage'] ?? dirname(__DIR__, 2) . '/storage');
        $dir = rtrim($base, '/') . '/migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        return $dir;
    }

    /** @param array<string, mixed> $config */
    public static function path(array $config, string $jobId): string
    {
        $safe = preg_replace('/[^a-f0-9]/', '', strtolower($jobId)) ?? '';
        if ($safe === '') {
            throw new \InvalidArgumentException('شناسه job نامعتبر');
        }

        return self::directory($config) . '/' . $safe . '.json';
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    public static function load(array $config, string $jobId): ?array
    {
        $path = self::path($config, $jobId);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $job
     */
    public static function save(array $config, array $job): void
    {
        $id = (string) ($job['id'] ?? '');
        if ($id === '') {
            throw new \InvalidArgumentException('job بدون id');
        }
        $path = self::path($config, $id);
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('ذخیره job ناموفق');
        }
        file_put_contents($path, $json, LOCK_EX);
    }
}
