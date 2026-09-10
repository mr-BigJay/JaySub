<?php

declare(strict_types=1);

use App\Services\TrafficCounter;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertEq(mixed $a, mixed $b, string $msg): void
{
    if ($a !== $b) {
        fwrite(STDERR, "FAIL: {$msg} — expected " . var_export($b, true) . ' got ' . var_export($a, true) . "\n");
        exit(1);
    }
}

// Normal increment
$r = TrafficCounter::applyReading(0, 0, 100, 200, 150, 250);
assertEq($r['total_up'], 150, 'delta up');
assertEq($r['total_down'], 250, 'delta down');

// Counter reset
$r2 = TrafficCounter::applyReading($r['base_up'], $r['base_down'], $r['last_up'], $r['last_down'], 10, 20);
assertEq($r2['base_up'], 150, 'base up after reset');
assertEq($r2['base_down'], 250, 'base down after reset');
assertEq($r2['total_up'], 160, 'total up after reset');
assertEq($r2['total_down'], 270, 'total down after reset');

echo "TrafficCounterTest OK\n";
