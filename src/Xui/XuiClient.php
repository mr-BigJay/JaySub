<?php

declare(strict_types=1);

namespace App\Xui;

/**
 * HTTP client for MHSanaei/3x-ui panel API (OpenAPI: /panel/api/*).
 * Auth: Authorization: Bearer <api token>
 */
final class XuiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiToken,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    /** @return array{ok: bool, data?: mixed, error?: string, http_code?: int} */
    public function getServerStatus(): array
    {
        return $this->request('GET', '/panel/api/server/status');
    }

    /** @return array{ok: bool, data?: mixed, error?: string} */
    public function listInbounds(): array
    {
        return $this->request('GET', '/panel/api/inbounds/list');
    }

    /** @return array{ok: bool, data?: mixed, error?: string} */
    public function getClient(string $email): array
    {
        $encoded = rawurlencode($email);
        return $this->request('GET', '/panel/api/clients/get/' . $encoded);
    }

    /**
     * @param list<string> $emails
     * @return array{ok: bool, data?: mixed, error?: string}
     */
    public function bulkDisable(array $emails): array
    {
        return $this->request('POST', '/panel/api/clients/bulkDisable', ['emails' => $emails]);
    }

    /**
     * @param list<string> $emails
     * @return array{ok: bool, data?: mixed, error?: string}
     */
    public function bulkEnable(array $emails): array
    {
        return $this->request('POST', '/panel/api/clients/bulkEnable', ['emails' => $emails]);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{ok: bool, data?: mixed, error?: string, http_code?: int}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init failed'];
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiToken,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'error' => $curlError ?: 'Request failed', 'http_code' => $httpCode];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'Invalid JSON response', 'http_code' => $httpCode];
        }

        if ($httpCode >= 400 || ($decoded['success'] ?? true) === false) {
            $msg = is_string($decoded['msg'] ?? null) ? $decoded['msg'] : 'API error';
            return ['ok' => false, 'error' => $msg, 'data' => $decoded, 'http_code' => $httpCode];
        }

        return ['ok' => true, 'data' => $decoded, 'http_code' => $httpCode];
    }
}
