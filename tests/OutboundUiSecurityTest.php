<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OutboundUiSecurityTest extends TestCase
{
    public function testOutboundPageAvoidsDynamicInnerHtmlForUserFields(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
        $start = strpos($js, 'const outboundPage = document.querySelector');
        self::assertNotFalse($start);
        $end = strpos($js, 'fetchJson(\'/admin/outbounds/api/panels\')', $start);
        self::assertNotFalse($end);
        $block = substr($js, $start, $end - $start);

        self::assertStringNotContainsString('tr.innerHTML =', $block);
        self::assertStringNotContainsString('card.innerHTML =', $block);
        self::assertStringNotContainsString('row.innerHTML =', $block);
        self::assertStringNotContainsString('bulkResults.innerHTML', $block);
        self::assertStringContainsString('textContent', $block);
        self::assertStringContainsString('replaceChildren', $block);
    }
}
