<?php

declare(strict_types=1);

use App\Services\XrayHealthVerifier;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/MockXrayPanelClient.php';

final class XrayHealthVerifierTest extends TestCase
{
    public function testRunningStateAndCleanXrayResultPass(): void
    {
        $status = [
            'ok' => true,
            'data' => [
                'success' => true,
                'obj' => ['xray' => ['state' => 'running']],
            ],
        ];
        $xr = ['ok' => true, 'data' => ['success' => true, 'obj' => 'Xray started']];
        $eval = XrayHealthVerifier::evaluatePoll($status, $xr);
        self::assertTrue($eval['ok']);
        self::assertSame('running', $eval['state']);
    }

    public function testStoppedStateFails(): void
    {
        $status = [
            'ok' => true,
            'data' => ['obj' => ['xray' => ['state' => 'stop']]],
        ];
        $xr = ['ok' => true, 'data' => ['success' => true]];
        $eval = XrayHealthVerifier::evaluatePoll($status, $xr);
        self::assertFalse($eval['ok']);
    }

    public function testMissingStateFailsClosed(): void
    {
        $status = ['ok' => true, 'data' => ['obj' => []]];
        $xr = ['ok' => true, 'data' => ['success' => true]];
        self::assertFalse(XrayHealthVerifier::evaluatePoll($status, $xr)['ok']);
    }

    public function testXrayResultCannotUnmarshalFails(): void
    {
        $status = [
            'ok' => true,
            'data' => ['obj' => ['xray' => ['state' => 'running']]],
        ];
        $xr = [
            'ok' => true,
            'data' => ['success' => true, 'msg' => 'cannot unmarshal array into map'],
        ];
        self::assertFalse(XrayHealthVerifier::evaluatePoll($status, $xr)['ok']);
    }

    public function testPollRequiresConsecutiveRunning(): void
    {
        $mock = new MockXrayPanelClient();
        $mock->pushStatus(['ok' => true, 'data' => ['obj' => ['xray' => ['state' => 'stop']]]]);
        $mock->pushXrayResult(['ok' => true, 'data' => ['success' => true]]);
        $mock->pushStatus(['ok' => true, 'data' => ['obj' => ['xray' => ['state' => 'running']]]]);
        $mock->pushXrayResult(['ok' => true, 'data' => ['success' => true]]);
        $mock->pushStatus(['ok' => true, 'data' => ['obj' => ['xray' => ['state' => 'running']]]]);
        $mock->pushXrayResult(['ok' => true, 'data' => ['success' => true]]);

        $result = XrayHealthVerifier::verify($mock);
        self::assertTrue($result['ok']);
        self::assertGreaterThanOrEqual(2, $result['attempts'] ?? 0);
    }
}
