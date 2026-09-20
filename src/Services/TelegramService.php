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
        $result = $this->apiRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);
        return $result['ok'];
    }

    /** @return array{ok:bool, username?:string, name?:string, error?:string} */
    public function getBotInfo(): array
    {
        if ($this->botToken === null || $this->botToken === '') {
            return ['ok' => false, 'error' => 'توکن ربات تنظیم نشده است.'];
        }
        $result = $this->apiRequest('getMe', []);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?? 'خطای اتصال'];
        }
        $data = $result['data'];
        $user = is_array($data) ? $data : [];
        $username = isset($user['username']) ? '@' . $user['username'] : '';
        $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
        return ['ok' => true, 'username' => $username, 'name' => $name];
    }

    /**
     * @param array<string, scalar> $params
     * @return array{ok:bool, data?:mixed, error?:string, http_code?:int}
     */
    public function apiRequest(string $method, array $params): array
    {
        if ($this->botToken === null || $this->botToken === '') {
            return ['ok' => false, 'error' => 'توکن خالی است'];
        }
        $url = 'https://api.telegram.org/bot' . $this->botToken . '/' . $method;
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init failed'];
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 15,
        ];
        if ($params !== []) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $params;
        }
        curl_setopt_array($ch, $opts);
        self::applyProxyToCurl($ch);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            return ['ok' => false, 'error' => $curlErr !== '' ? $curlErr : 'پاسخ خالی از Telegram', 'http_code' => $code];
        }
        $json = json_decode($response, true);
        if (!is_array($json) || !($json['ok'] ?? false)) {
            $desc = is_array($json) ? (string) ($json['description'] ?? 'API error') : 'Invalid JSON';
            return ['ok' => false, 'error' => $desc, 'http_code' => $code];
        }
        return ['ok' => true, 'data' => $json['result'] ?? null, 'http_code' => $code];
    }

    public static function applyProxyToCurl(\CurlHandle $ch): void
    {
        if (SettingsService::get('telegram_proxy_enabled', '0') !== '1') {
            return;
        }
        $proxy = trim((string) SettingsService::get('telegram_proxy_url', ''));
        if ($proxy === '') {
            return;
        }
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        if (str_starts_with($proxy, 'socks5://') || str_starts_with($proxy, 'socks5h://')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        } elseif (str_starts_with($proxy, 'socks4://')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
        } else {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }
    }

    public static function maskToken(?string $token): string
    {
        $token = trim((string) $token);
        if ($token === '') {
            return '—';
        }
        if (strlen($token) <= 12) {
            return '••••';
        }
        return substr($token, 0, 6) . '…' . substr($token, -4);
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
