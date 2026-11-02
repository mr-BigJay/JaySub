<?php

declare(strict_types=1);

use App\Services\OutboundSaveGuard;
use App\Services\RoutingSaveGuard;
use App\View\Layout;
use PHPUnit\Framework\TestCase;

final class Outbound3xUiModalTest extends TestCase
{
    public function testOutboundPageShowsTopCreateButtonAndCenteredModal(): void
    {
        $html = Layout::adminOutboundsPage('csrf-test-token');
        self::assertStringContainsString('class="outbound-page-toolbar"', $html);
        self::assertStringContainsString('id="outbound-add-btn"', $html);
        self::assertStringContainsString('id="outbound-create-dialog"', $html);
        self::assertStringContainsString('id="outbound-create-form"', $html);
        self::assertStringContainsString('id="outbound-tab-basics"', $html);
        self::assertStringContainsString('id="outbound-tab-json"', $html);
    }

    public function testBasicsFormContains3xUiFields(): void
    {
        $html = Layout::adminOutboundsPage('csrf-test-token');
        foreach ([
            'ob-protocol', 'ob-tag', 'ob-send-through', 'ob-address', 'ob-port',
            'ob-id', 'ob-encryption', 'ob-reverse-tag', 'ob-network',
            'ob-http-obfs', 'ob-sni', 'ob-fingerprint', 'ob-public-key',
            'ob-short-id', 'ob-sockopts', 'ob-tcp-masks', 'ob-json-editor',
        ] as $id) {
            self::assertStringContainsString('id="' . $id . '"', $html);
        }
        self::assertStringContainsString('id="outbound-select-all"', $html);
        self::assertStringContainsString('id="outbound-bulk-json-preview"', $html);
    }

    public function testModalJavascriptBuildsFlatVlessAndSendsToPreview(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/outbound-modal.js');
        $appScript = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
        self::assertStringContainsString("node.settings = { address, port, id, flow:", $script);
        self::assertStringContainsString("protocol === 'vmess'", $script);
        self::assertStringContainsString('JSON.stringify(object, null, 2)', $script);
        self::assertStringContainsString("jaysub:outbound-built", $script);
        self::assertStringContainsString("jaysub:outbound-built", $appScript);
        self::assertStringContainsString('outbound-bulk-json-preview', $appScript);
    }

    public function testJsonTabHasShareLinkImportInputAndButton(): void
    {
        $html = Layout::adminOutboundsPage('csrf-test-token');
        self::assertStringContainsString('id="ob-import-link"', $html);
        self::assertStringContainsString('id="ob-import-button"', $html);
        self::assertStringContainsString('>Import</button>', $html);

        $loader = (string) file_get_contents(dirname(__DIR__) . '/src/View/Layout.php');
        $modal = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/outbound-modal.js');
        self::assertStringContainsString("Assets::url('js/outbound-import.js')", $loader);
        self::assertStringContainsString('JaySubOutboundImport.parse(', $modal);
        self::assertStringContainsString('jsonEditor.value = validated', $modal);
    }

    public function testNoProductionWriteGateIsEnabled(): void
    {
        self::assertFalse(OutboundSaveGuard::PERSIST_ENABLED);
        self::assertFalse(RoutingSaveGuard::PERSIST_ENABLED);
    }
}
