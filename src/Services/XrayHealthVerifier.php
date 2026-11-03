<?php

declare(strict_types=1);

namespace App\Services;

use App\Xui\XrayPanelClientInterface;

/** Post-save Xray health checks for 3x-ui v3.4.2 (xray.state + getXrayResult). */
final class XrayHealthVerifier
{
    public const POLL_ATTEMPTS = 3;

    public const POLL_DELAY_MICROSECONDS = 450_000;

    /**
     * @return array{ok: bool, error?: string, state?: string, attempts?: int}
     */
    public static function verify(XrayPanelClientInterface $client): array
    {
        $lastError = 'وضعیت Xray نامشخص است';
        for ($attempt = 1; $attempt <= self::POLL_ATTEMPTS; ++$attempt) {
            if ($attempt > 1) {
                usleep(self::POLL_DELAY_MICROSECONDS);
            }

            $status = $client->getServerStatus();
            $xrayResult = $client->getXrayResult();
            $eval = self::evaluatePoll($status, $xrayResult);
            if ($eval['ok']) {
                return ['ok' => true, 'state' => $eval['state'] ?? 'running', 'attempts' => $attempt];
            }
            $lastError = $eval['error'] ?? $lastError;
        }

        return ['ok' => false, 'error' => $lastError, 'attempts' => self::POLL_ATTEMPTS];
    }

    /**
     * @param array<string, mixed> $statusResult
     * @param array<string, mixed> $xrayResultResponse
     * @return array{ok: bool, error?: string, state?: string}
     */
    public static function evaluatePoll(array $statusResult, array $xrayResultResponse): array
    {
        if (!($statusResult['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string) ($statusResult['error'] ?? 'server/status ناموفق')];
        }

        $state = self::extractXrayState($statusResult['data'] ?? null);
        if ($state === null) {
            return ['ok' => false, 'error' => 'پاسخ server/status فاقد obj.xray.state است'];
        }
        if ($state !== 'running') {
            return ['ok' => false, 'error' => "xray.state باید running باشد (فعلی: {$state})", 'state' => $state];
        }

        if (!($xrayResultResponse['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => (string) ($xrayResultResponse['error'] ?? 'getXrayResult ناموفق'),
                'state' => $state,
            ];
        }

        $blob = self::xrayResultBlob($xrayResultResponse['data'] ?? null);
        if ($blob === null) {
            return ['ok' => false, 'error' => 'پاسخ getXrayResult ناقص است', 'state' => $state];
        }
        if (self::containsStartupFailure($blob)) {
            return ['ok' => false, 'error' => 'getXrayResult خطای راه‌اندازی Xray را گزارش می‌کند', 'state' => $state];
        }

        return ['ok' => true, 'state' => $state];
    }

    public static function extractXrayState(mixed $statusData): ?string
    {
        if (!is_array($statusData)) {
            return null;
        }
        $obj = $statusData['obj'] ?? null;
        if (is_string($obj)) {
            $decoded = json_decode($obj, true);
            $obj = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($obj)) {
            return null;
        }
        $xray = $obj['xray'] ?? null;
        if (!is_array($xray)) {
            return null;
        }
        $state = $xray['state'] ?? null;

        return is_string($state) && $state !== '' ? $state : null;
    }

    public static function containsStartupFailure(string $blob): bool
    {
        $lower = strtolower($blob);

        return str_contains($lower, 'cannot unmarshal')
            || str_contains($lower, 'failed to start')
            || str_contains($lower, 'failed to load config');
    }

    private static function xrayResultBlob(mixed $data): ?string
    {
        if (!is_array($data)) {
            return null;
        }
        if (($data['success'] ?? true) === false) {
            $msg = is_string($data['msg'] ?? null) ? $data['msg'] : json_encode($data, JSON_UNESCAPED_UNICODE);

            return is_string($msg) ? $msg : '';
        }
        $parts = [json_encode($data, JSON_UNESCAPED_UNICODE) ?: ''];
        if (isset($data['obj']) && is_string($data['obj'])) {
            $parts[] = $data['obj'];
        } elseif (isset($data['obj']) && is_array($data['obj'])) {
            $parts[] = json_encode($data['obj'], JSON_UNESCAPED_UNICODE) ?: '';
        }
        if (isset($data['msg']) && is_string($data['msg'])) {
            $parts[] = $data['msg'];
        }

        return implode("\n", $parts);
    }
}
