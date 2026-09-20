<?php

declare(strict_types=1);

namespace App\Xui;

/**
 * Extract traffic from 3x-ui GET /panel/api/inbounds/list (same fields as inbounds UI).
 */
final class InboundTraffic
{
    /**
     * @param array{ok?:bool, data?:mixed} $listResult Return value of XuiClient::listInbounds()
     * @return list<array<string, mixed>>
     */
    public static function inboundsFromListResult(array $listResult): array
    {
        if (($listResult['ok'] ?? false) !== true) {
            return [];
        }
        $data = $listResult['data'] ?? null;
        if (!is_array($data)) {
            return [];
        }
        if (isset($data['obj']) && is_array($data['obj'])) {
            return self::normalizeInboundList($data['obj']);
        }
        if (array_is_list($data) || isset($data['id'])) {
            return self::normalizeInboundList($data);
        }

        return [];
    }

    /**
     * Totals shown on 3x-ui inbounds page: sum of each inbound's up/down (not clientStats).
     *
     * @param list<mixed> $inbounds
     * @return array{up: int, down: int, total: int, inbound_count: int}
     */
    public static function panelTrafficTotals(array $inbounds): array
    {
        $inbounds = self::normalizeInboundList($inbounds);
        $up = 0;
        $down = 0;
        $count = 0;
        foreach ($inbounds as $inbound) {
            if (!is_array($inbound)) {
                continue;
            }
            ++$count;
            [$ibu, $ibd] = self::inboundUpDown($inbound);
            $up += $ibu;
            $down += $ibd;
        }

        return [
            'up' => $up,
            'down' => $down,
            'total' => $up + $down,
            'inbound_count' => $count,
        ];
    }

    /**
     * @param list<mixed>|array<string, mixed> $inbounds
     * @return list<array<string, mixed>>
     */
    public static function normalizeInboundList(array $inbounds): array
    {
        if ($inbounds === []) {
            return [];
        }
        if (array_is_list($inbounds)) {
            return $inbounds;
        }
        if (isset($inbounds['id'])) {
            return [$inbounds];
        }
        return array_values($inbounds);
    }

    /** @return array{0:int,1:int} */
    private static function inboundUpDown(array $inbound): array
    {
        $up = self::toByteInt($inbound['up'] ?? 0);
        $down = self::toByteInt($inbound['down'] ?? 0);
        if ($up > 0 || $down > 0) {
            return [$up, $down];
        }
        $statsUp = 0;
        $statsDown = 0;
        $clientStats = $inbound['clientStats'] ?? [];
        if (is_array($clientStats)) {
            foreach ($clientStats as $stat) {
                if (!is_array($stat)) {
                    continue;
                }
                $statsUp += self::toByteInt($stat['up'] ?? 0);
                $statsDown += self::toByteInt($stat['down'] ?? 0);
            }
        }
        if ($statsUp > 0 || $statsDown > 0) {
            return [$statsUp, $statsDown];
        }

        return [0, 0];
    }

    private static function toByteInt(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_float($value)) {
            return max(0, (int) round($value));
        }
        if (is_string($value) && $value !== '' && is_numeric($value)) {
            return max(0, (int) round((float) $value));
        }

        return 0;
    }

    /**
     * @param list<mixed> $inbounds
     * @return array<string, array{inbound_id:int, protocol:?string, up:int, down:int, enable:bool, uuid:?string}>
     */
    /**
     * @param list<array<string, mixed>> $inbounds
     * @return list<string>
     */
    public static function disabledEmails(array $inbounds): array
    {
        $out = [];
        foreach (self::statsByEmail($inbounds) as $email => $s) {
            if ($s['enable'] === false) {
                $out[] = $email;
            }
        }

        return $out;
    }

    public static function statsByEmail(array $inbounds): array
    {
        /** @var array<string, array{inbound_id:int, protocol:?string, up:int, down:int, enable:bool, uuid:?string}> $statsByEmail */
        $statsByEmail = [];

        foreach (self::normalizeInboundList($inbounds) as $inbound) {
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
