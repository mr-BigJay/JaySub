<?php

declare(strict_types=1);

use App\Xui\InboundTraffic;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertEq(mixed $a, mixed $b, string $msg): void
{
    if ($a !== $b) {
        fwrite(STDERR, "FAIL: {$msg} — expected " . var_export($b, true) . ' got ' . var_export($a, true) . "\n");
        exit(1);
    }
}

$totals = InboundTraffic::panelTrafficTotals([
    ['id' => 1, 'up' => 100, 'down' => 200],
    ['id' => 2, 'up' => 50, 'down' => 150],
]);
assertEq($totals['up'], 150, 'panel up sum');
assertEq($totals['down'], 350, 'panel down sum');
assertEq($totals['total'], 500, 'panel total');
assertEq($totals['inbound_count'], 2, 'inbound count');

$fallback = InboundTraffic::panelTrafficTotals([
    ['id' => 1, 'up' => 0, 'down' => 0, 'clientStats' => [['up' => 10, 'down' => 90]]],
]);
assertEq($fallback['total'], 100, 'clientStats fallback');

$parsed = InboundTraffic::inboundsFromListResult([
    'ok' => true,
    'data' => ['success' => true, 'obj' => [['id' => 3, 'up' => '5', 'down' => 7]]],
]);
assertEq(count($parsed), 1, 'parse list result');
assertEq(InboundTraffic::panelTrafficTotals($parsed)['total'], 12, 'string byte counts');

echo "InboundTrafficTest OK\n";
