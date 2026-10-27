<?php

declare(strict_types=1);

use App\Services\OutboundSaveWorkflow;
use App\Services\XrayHealthVerifier;
use App\Xui\XraySettingOutboundJson;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MockXrayPanelClient.php';

final class OutboundSaveWorkflowTest extends TestCase
{
    private function runningStatus(): array
    {
        return ['ok' => true, 'data' => ['obj' => ['xray' => ['state' => 'running']]]];
    }

    private function cleanXrayResult(): array
    {
        return ['ok' => true, 'data' => ['success' => true]];
    }

    private function queueHealthOk(MockXrayPanelClient $mock): void
    {
        for ($i = 0; $i < XrayHealthVerifier::POLL_ATTEMPTS; ++$i) {
            $mock->pushStatus($this->runningStatus());
            $mock->pushXrayResult($this->cleanXrayResult());
        }
    }

    /** @return array{ok: bool, data: array<string, mixed>, data_object: object} */
    private function fetchPayload(string $xrayJson): array
    {
        $wire = json_decode(
            '{"success":true,"obj":{"xraySetting":' . $xrayJson . ',"inboundTags":"[]","outboundTestUrl":""}}',
            false,
        );
        self::assertInstanceOf(\stdClass::class, $wire);

        return [
            'ok' => true,
            'data' => json_decode(json_encode($wire), true) ?: [],
            'data_object' => $wire,
        ];
    }

    public function testSuccessfulAppendDoesNotRollback(): void
    {
        $original = (string) file_get_contents(__DIR__ . '/fixtures/xray-outbound-append-base.json');
        $newOb = json_decode('{"tag":"added","protocol":"freedom","settings":{}}', true);
        self::assertIsArray($newOb);
        $expected = XraySettingOutboundJson::appendOutbound($original, $newOb);

        $mock = new MockXrayPanelClient();
        $mock->pushUpdate(['ok' => true, 'data' => ['success' => true]]);
        $mock->pushFetch($this->fetchPayload($expected));
        $this->queueHealthOk($mock);

        $result = OutboundSaveWorkflow::persistAfterBackup($mock, $original, $expected, null, 'b.json');
        self::assertTrue($result['ok']);
        self::assertCount(1, $mock->updatePayloads);
    }

    public function testFailedUpdateWithoutDriftSkipsRollback(): void
    {
        $original = '{"routing":{"rules":[]},"outbounds":[]}';
        $expected = XraySettingOutboundJson::appendOutbound($original, ['tag' => 'n', 'protocol' => 'freedom']);
        $mock = new MockXrayPanelClient();
        $mock->pushUpdate(['ok' => false, 'error' => 'fail']);
        $mock->pushFetch($this->fetchPayload($original));
        $this->queueHealthOk($mock);

        $result = OutboundSaveWorkflow::persistAfterBackup($mock, $original, $expected, null, 'b.json');
        self::assertFalse($result['ok']);
        self::assertCount(1, $mock->updatePayloads);
    }
}
