<?php

declare(strict_types=1);

use App\Services\RoutingRollbackCoordinator;
use App\Services\XrayHealthVerifier;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/MockXrayPanelClient.php';

final class RoutingRollbackCoordinatorTest extends TestCase
{
    private function runningStatus(): array
    {
        return [
            'ok' => true,
            'data' => ['success' => true, 'obj' => ['xray' => ['state' => 'running']]],
        ];
    }

    private function cleanXrayResult(): array
    {
        return ['ok' => true, 'data' => ['success' => true, 'obj' => 'ok']];
    }

    private function xrayInner(string $raw): array
    {
        return [
            'ok' => true,
            'data' => [
                'success' => true,
                'obj' => [
                    'xraySetting' => $raw,
                    'inboundTags' => '[]',
                    'outboundTestUrl' => '',
                ],
            ],
        ];
    }

    public function testRestoreVerifiedWhenConfigAndRunning(): void
    {
        $original = '{"routing":{"rules":[]},"outbounds":[{"tag":"d","settings":{}}]}';
        $mock = new MockXrayPanelClient();
        $mock->pushUpdate(['ok' => true, 'data' => ['success' => true]]);
        $mock->pushFetch($this->xrayInner($original));
        for ($i = 0; $i < XrayHealthVerifier::POLL_ATTEMPTS; ++$i) {
            $mock->pushStatus($this->runningStatus());
            $mock->pushXrayResult($this->cleanXrayResult());
        }

        $result = RoutingRollbackCoordinator::restoreOriginalAndVerify($mock, $original, null);
        self::assertTrue($result['ok']);
        self::assertTrue($result['config_restored']);
        self::assertTrue($result['xray_running']);
        self::assertSame($original, $mock->updatePayloads[0] ?? '');
    }

    public function testRestoreCriticalWhenConfigNotRestored(): void
    {
        $original = '{"routing":{"rules":[]}}';
        $bad = '{"routing":{"rules":[{"type":"field"}]}}';
        $mock = new MockXrayPanelClient();
        $mock->pushUpdate(['ok' => true, 'data' => ['success' => true]]);
        $mock->pushFetch($this->xrayInner($bad));
        for ($i = 0; $i < XrayHealthVerifier::POLL_ATTEMPTS; ++$i) {
            $mock->pushStatus($this->runningStatus());
            $mock->pushXrayResult($this->cleanXrayResult());
        }

        $result = RoutingRollbackCoordinator::restoreOriginalAndVerify($mock, $original, null);
        self::assertFalse($result['ok']);
        self::assertTrue($result['critical'] ?? false);
        self::assertFalse($result['config_restored'] ?? true);
    }

    public function testRestoreCriticalWhenXrayNotRunning(): void
    {
        $original = '{"routing":{"rules":[]}}';
        $mock = new MockXrayPanelClient();
        $mock->pushUpdate(['ok' => false, 'error' => 'update rejected']);
        $mock->pushFetch($this->xrayInner($original));
        for ($i = 0; $i < XrayHealthVerifier::POLL_ATTEMPTS; ++$i) {
            $mock->pushStatus([
                'ok' => true,
                'data' => ['obj' => ['xray' => ['state' => 'stop']]],
            ]);
            $mock->pushXrayResult($this->cleanXrayResult());
        }

        $result = RoutingRollbackCoordinator::restoreOriginalAndVerify($mock, $original, null);
        self::assertFalse($result['ok']);
        self::assertTrue($result['critical'] ?? false);
        self::assertFalse($result['xray_running'] ?? true);
    }
}
