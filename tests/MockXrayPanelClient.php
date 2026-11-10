<?php

declare(strict_types=1);

use App\Xui\XrayPanelClientInterface;

/** Scripted 3x-ui responses for routing health/rollback unit tests. */
final class MockXrayPanelClient implements XrayPanelClientInterface
{
    /** @var list<array{ok: bool, data?: mixed, error?: string}> */
    private array $statusQueue = [];

    /** @var list<array{ok: bool, data?: mixed, error?: string}> */
    private array $xrayResultQueue = [];

    /** @var list<array{ok: bool, data?: mixed, error?: string}> */
    private array $fetchQueue = [];

    /** @var list<array{ok: bool, data?: mixed, error?: string}> */
    private array $updateQueue = [];

    /** @var list<string> */
    public array $updatePayloads = [];

    /** @param array{ok: bool, data?: mixed, error?: string} $response */
    public function pushStatus(array $response): void
    {
        $this->statusQueue[] = $response;
    }

    /** @param array{ok: bool, data?: mixed, error?: string} $response */
    public function pushXrayResult(array $response): void
    {
        $this->xrayResultQueue[] = $response;
    }

    /** @param array{ok: bool, data?: mixed, error?: string} $response */
    public function pushFetch(array $response): void
    {
        $this->fetchQueue[] = $response;
    }

    /** @param array{ok: bool, data?: mixed, error?: string} $response */
    public function pushUpdate(array $response): void
    {
        $this->updateQueue[] = $response;
    }

    public function getServerStatus(): array
    {
        return array_shift($this->statusQueue) ?? ['ok' => false, 'error' => 'status queue empty'];
    }

    public function getXrayResult(): array
    {
        return array_shift($this->xrayResultQueue) ?? ['ok' => false, 'error' => 'xray result queue empty'];
    }

    public function fetchXrayTemplate(): array
    {
        return array_shift($this->fetchQueue) ?? ['ok' => false, 'error' => 'fetch queue empty'];
    }

    public function updateXrayTemplate(string $xraySettingJson, ?string $outboundTestUrl = null): array
    {
        $this->updatePayloads[] = $xraySettingJson;

        return array_shift($this->updateQueue) ?? ['ok' => false, 'error' => 'update queue empty'];
    }
}
