<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Handles 3X-UI counter resets by keeping base + current raw counters.
 */
final class TrafficCounter
{
    /**
     * @return array{base_up: int, base_down: int, last_up: int, last_down: int, total_up: int, total_down: int}
     */
    public static function applyReading(
        int $baseUp,
        int $baseDown,
        int $lastUp,
        int $lastDown,
        int $newUp,
        int $newDown,
    ): array {
        if ($newUp < $lastUp) {
            $baseUp += $lastUp;
        }
        if ($newDown < $lastDown) {
            $baseDown += $lastDown;
        }

        $lastUp = $newUp;
        $lastDown = $newDown;

        return [
            'base_up' => $baseUp,
            'base_down' => $baseDown,
            'last_up' => $lastUp,
            'last_down' => $lastDown,
            'total_up' => $baseUp + $lastUp,
            'total_down' => $baseDown + $lastDown,
        ];
    }
}
