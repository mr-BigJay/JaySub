<?php

declare(strict_types=1);

use App\Services\RoutingSaveWorkflow;
use App\Services\XrayHealthVerifier;
use App\Xui\XraySettingJson;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MockXrayPanelClient.php';

final class RoutingSaveWorkflowTest extends TestCase
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

    private function queueHealthOk(MockXrayPanelClient $mock, int $attempts = XrayHealthVerifier::POLL_ATTEMPTS): void
    {
        for ($i = 0; $i < $attempts; ++$i) {
            $mock->pushStatus($this->runningStatus());
            $mock->pushXrayResult($this->cleanXrayResult());
        }
    }

    /** @return array{ok: bool, data: array<string, mixed>, data_object: object} */
    private function fetchPayloadForXrayRaw(string $xraySettingJson): array
    {
        $wire = json_decode(
            '{"success":true,"obj":{"xraySetting":'
            . $xraySettingJson
            . ',"inboundTags":"[]","outboundTestUrl":""}}',
            false,
        );
        self::assertInstanceOf(\stdClass::class, $wire);

        return [
            'ok' => true,
            'data' => json_decode(json_encode($wire), true) ?: [],
            'data_object' => $wire,
        ];
    }

    public function testSuccessfulRuleChangeDoesNotRollback(): void
    {
        $path = __DIR__ . '/fixtures/xray-v342-ws-headers.json';
        $original = (string) file_get_contents($path);
        $rules = XraySettingJson::rulesFromRoot(XraySettingJson::decodeRoot($original));
        $rules[] = ['type' => 'field', 'domain' => ['added.example'], 'outboundTag' => 'direct'];
        $expected = XraySettingJson::replaceRoutingRules($original, $rules);

        $mock = new MockXrayPanelClient();
        $mock->pushUpdate(['ok' => true, 'data' => ['success' => true]]);
        $mock->pushFetch($this->fetchPayloadForXrayRaw($expected));
        $this->queueHealthOk($mock);

        $result = RoutingSaveWorkflow::persistAfterBackup(
            $mock,
            $original,
            $expected,
            null,
            'backup.raw.json',
        );

        self::assertTrue($result['ok']);
        self::assertCount(1, $mock->updatePayloads);
        self::assertSame($expected, $mock->updatePayloads[0]);
    }

    public function testNoopSkipsUpdate(): void
    {
        $raw = '{"routing":{"rules":[]},"outbounds":[]}';
        $mock = new MockXrayPanelClient();
        $result = RoutingSaveWorkflow::persistAfterBackup($mock, $raw, $raw, null, 'b.json');
        self::assertTrue($result['ok']);
        self::assertTrue($result['noop'] ?? false);
        self::assertSame([], $mock->updatePayloads);
    }

    public function testFailedUpdateWithoutDriftSkipsRollback(): void
    {
        $original = '{"routing":{"rules":[]},"outbounds":[]}';
        $expected = '{"routing":{"rules":[{"type":"field","outboundTag":"direct"}]},"outbounds":[]}';
        $mock = new MockXrayPanelClient();
        $mock->pushUpdate(['ok' => false, 'error' => 'panel rejected']);
        $mock->pushFetch($this->fetchPayloadForXrayRaw($original));
        $this->queueHealthOk($mock);

        $result = RoutingSaveWorkflow::persistAfterBackup(
            $mock,
            $original,
            $expected,
            null,
            'backup.raw.json',
        );

        self::assertFalse($result['ok']);
        self::assertSame('panel rejected', $result['error']);
        self::assertCount(1, $mock->updatePayloads);
        self::assertArrayNotHasKey('rolled_back', $result);
    }

    public function testFailedUpdateWithDriftRollsBack(): void
    {
        $original = '{"routing":{"rules":[]},"outbounds":[]}';
        $expected = '{"routing":{"rules":[{"type":"field","outboundTag":"direct"}]},"outbounds":[]}';
        $drifted = '{"routing":{"rules":[{"type":"field","outboundTag":"blocked"}]},"outbounds":[]}';

        $mock = new MockXrayPanelClient();
        $mock->pushUpdate(['ok' => false, 'error' => 'panel rejected']);
        $mock->pushFetch($this->fetchPayloadForXrayRaw($drifted));
        $this->queueHealthOk($mock);
        $mock->pushUpdate(['ok' => true, 'data' => ['success' => true]]);
        $mock->pushFetch($this->fetchPayloadForXrayRaw($original));
        $this->queueHealthOk($mock);

        $result = RoutingSaveWorkflow::persistAfterBackup(
            $mock,
            $original,
            $expected,
            null,
            'backup.raw.json',
        );

        self::assertFalse($result['ok']);
        self::assertTrue($result['rolled_back'] ?? false);
        self::assertSame($original, $mock->updatePayloads[1] ?? '');
    }

    public function testUnexpectedOutsideRulesChangeDetectedBeforeUpdate(): void
    {
        $original = '{"routing":{"rules":[]},"outbounds":[{"tag":"d","settings":{}}]}';
        $bad = '{"routing":{"rules":[]},"outbounds":[{"tag":"d","settings":[]}]}';
        $mock = new MockXrayPanelClient();
        $result = RoutingSaveWorkflow::persistAfterBackup($mock, $original, $bad, null, 'b.json');
        self::assertFalse($result['ok']);
        self::assertSame([], $mock->updatePayloads);
    }

    public function testRollbackFailureReportsCritical(): void
    {
        $original = '{"routing":{"rules":[]},"outbounds":[]}';
        $expected = '{"routing":{"rules":[{"type":"field","outboundTag":"x"}]},"outbounds":[]}';
        $wrong = '{"routing":{"rules":[{"type":"field","outboundTag":"y"}]},"outbounds":[]}';

        $mock = new MockXrayPanelClient();
        $mock->pushUpdate(['ok' => true, 'data' => ['success' => true]]);
        $mock->pushFetch($this->fetchPayloadForXrayRaw($wrong));
        $this->queueHealthOk($mock);
        $mock->pushUpdate(['ok' => true, 'data' => ['success' => true]]);
        $mock->pushFetch($this->fetchPayloadForXrayRaw($wrong));
        $this->queueHealthOk($mock);

        $result = RoutingSaveWorkflow::persistAfterBackup(
            $mock,
            $original,
            $expected,
            null,
            'backup.raw.json',
        );

        self::assertFalse($result['ok']);
        self::assertTrue($result['critical'] ?? false);
        self::assertFalse($result['rolled_back'] ?? true);
    }
}
