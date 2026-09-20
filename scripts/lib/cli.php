<?php

declare(strict_types=1);

/**
 * @return array{config: array<string, mixed>, sync: \App\Services\TrafficSyncService}
 */
function jaysub_cli_traffic_sync(): array
{
    $root = dirname(__DIR__, 2);
    /** @var array<string, mixed> $config */
    $config = require $root . '/src/bootstrap-cli.php';
    $key = (string) ($config['security']['encryption_key'] ?? '');
    if ($key === '') {
        throw new \RuntimeException('config.php: security.encryption_key خالی است.');
    }
    $encryption = new \App\Core\Encryption($key);
    $telegram = new \App\Services\TelegramService(\App\Services\SettingsService::get('telegram_bot_token'));
    $sync = new \App\Services\TrafficSyncService($encryption, $telegram);

    return ['config' => $config, 'sync' => $sync];
}
