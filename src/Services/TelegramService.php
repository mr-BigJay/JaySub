<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Format;

final class TelegramService
{
    public function __construct(
        private readonly ?string $botToken,
    ) {
    }

    public function sendMessage(string $chatId, string $text, bool $force = false): bool
    {
        if ($this->botToken === null || $this->botToken === '' || $chatId === '') {
            return false;
        }
        if (!$force && SettingsService::get('telegram_notifications_enabled', '1') !== '1') {
            return false;
        }
        $url = 'https://api.telegram.org/bot' . $this->botToken . '/sendMessage';
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ],
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $response !== false && $code >= 200 && $code < 300;
    }

    public static function warningMessage(string $title, float $quotaBytes, float $usedBytes, int $percent): string
    {
        $quota = Format::bytesToGb($quotaBytes);
        $used = Format::bytesToGb($usedBytes);
        $remaining = Format::bytesToGb(max(0, $quotaBytes - $usedBytes));
        return "⚠️ <b>{$title}</b>\n\n"
            . "{$percent}٪ از حجم سرویس شما مصرف شده است.\n\n"
            . "حجم سرویس: {$quota}\n"
            . "مصرف: {$used}\n"
            . "باقی‌مانده: {$remaining}";
    }

    public static function limitMessage(float $quotaBytes): string
    {
        return "🔴 <b>اتمام حجم سرویس</b>\n\n"
            . 'حجم سرویس شما (' . Format::bytesToGb($quotaBytes) . ") به پایان رسید.\n"
            . 'اتصال VPN موقتاً قطع شد. برای تمدید با پشتیبانی تماس بگیرید.';
    }

    public static function rechargeMessage(float $newQuotaBytes): string
    {
        return "✅ <b>شارژ مجدد سرویس</b>\n\n"
            . 'حجم جدید سرویس: ' . Format::bytesToGb($newQuotaBytes) . "\n"
            . 'VPN شما دوباره فعال شد.';
    }
}
