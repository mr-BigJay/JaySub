<?php

declare(strict_types=1);

namespace App\Xui;

/**
 * Extract per-client traffic from 3x-ui inbound list response.
 */
final class InboundTraffic
{
    /**
     * @param list<mixed> $inbounds
     * @return array<string, array{inbound_id:int, protocol:?string, up:int, down:int, enable:bool, uuid:?string}>
     */
    public static function statsByEmail(array $inbounds): array
    {
        /** @var array<string, array{inbound_id:int, protocol:?string, up:int, down:int, enable:bool, uuid:?string}> $statsByEmail */
        $statsByEmail = [];

        foreach ($inbounds as $inbound) {
            if (!is_array($inbound)) {
                continue;
            }
            $inboundId = (int) ($inbound['id'] ?? 0);
            $protocol = is_string($inbound['protocol'] ?? null) ? $inbound['protocol'] : null;

            $clientStats = $inbound['clientStats'] ?? [];
            if (is_array($clientStats)) {
                foreach ($clientStats as $stat) {
                    if (!is_array($stat) || !isset($stat['email'])) {
                        continue;
                    }
                    $email = (string) $stat['email'];
                    $statsByEmail[$email] = [
                        'inbound_id' => $inboundId,
                        'protocol' => $protocol,
                        'up' => (int) ($stat['up'] ?? 0),
                        'down' => (int) ($stat['down'] ?? 0),
                        'enable' => (bool) ($stat['enable'] ?? true),
                        'uuid' => isset($stat['uuid']) ? (string) $stat['uuid'] : null,
                    ];
                }
            }

            $settingsRaw = $inbound['settings'] ?? null;
            if (!is_string($settingsRaw) || $settingsRaw === '') {
                continue;
            }
            $settings = json_decode($settingsRaw, true);
            if (!is_array($settings)) {
                continue;
            }
            $clients = $settings['clients'] ?? [];
            if (!is_array($clients)) {
                continue;
            }
            foreach ($clients as $client) {
                if (!is_array($client)) {
                    continue;
                }
                $email = $client['email'] ?? $client['id'] ?? null;
                if (!is_string($email) || $email === '') {
                    continue;
                }
                if (isset($statsByEmail[$email])) {
                    continue;
                }
                $statsByEmail[$email] = [
                    'inbound_id' => $inboundId,
                    'protocol' => $protocol,
                    'up' => 0,
                    'down' => 0,
                    'enable' => true,
                    'uuid' => isset($client['id']) ? (string) $client['id'] : null,
                ];
            }
        }

        return $statsByEmail;
    }
}
