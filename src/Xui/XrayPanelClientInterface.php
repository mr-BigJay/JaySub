<?php

declare(strict_types=1);

namespace App\Xui;

/** Minimal 3x-ui client surface for routing save health and rollback. */
interface XrayPanelClientInterface
{
    /** @return array{ok: bool, data?: mixed, error?: string, http_code?: int} */
    public function getServerStatus(): array;

    /** @return array{ok: bool, data?: mixed, error?: string, http_code?: int} */
    public function getXrayResult(): array;

    /** @return array{ok: bool, data?: mixed, error?: string, http_code?: int} */
    public function fetchXrayTemplate(): array;

    /** @return array{ok: bool, data?: mixed, error?: string, http_code?: int} */
    public function updateXrayTemplate(string $xraySettingJson, ?string $outboundTestUrl = null): array;
}
