<?php

declare(strict_types=1);

// Run: php tests/MigrationOfflineChoiceSmoke.php
// No database, SSH, PHPUnit or external services required.
require dirname(__DIR__) . '/vendor/autoload.php';

$html = \App\View\Layout::adminMigrationPage(
    '<input type="hidden" name="csrf_token" value="test">',
    [
        ['id' => 1, 'panel_name' => 'Sample', 'name' => 'Sample', 'host' => '127.0.0.1', 'vpn_panel_id' => 1],
    ],
    null,
    ['amd64' => true, 'arm64' => false]
);

$start1 = strpos($html, 'data-migration-wizard-panel="1"');
$start2 = strpos($html, 'data-migration-wizard-panel="2"');
$start3 = strpos($html, 'data-migration-wizard-panel="3"');

if ($start1 === false || $start2 === false || $start3 === false || !($start1 < $start2 && $start2 < $start3)) {
    throw new \RuntimeException('Migration wizard steps not found or out of order.');
}

$step1 = substr($html, $start1, $start2 - $start1);
$step2 = substr($html, $start2, $start3 - $start2);
$checkboxPattern = '~<input\b(?=[^>]*\bid="migration-xui-install-mode")[^>]*>~';
if (!preg_match($checkboxPattern, $step1, $match)) {
    throw new \RuntimeException('Offline checkbox is missing from step 1.');
}

$input = $match[0];
foreach (['type="checkbox"', 'name="xui_install_mode"', 'value="offline"'] as $expected) {
    if (!str_contains($input, $expected)) {
        throw new \RuntimeException('Offline checkbox missing attribute: ' . $expected);
    }
}
if (preg_match('~\bchecked(?:\s|=|>)~', $input)) {
    throw new \RuntimeException('Offline checkbox must be unchecked by default.');
}
if (str_contains($step2, 'xui_install_mode') || substr_count($html, 'name="xui_install_mode"') !== 1) {
    throw new \RuntimeException('Offline mode must appear only in step 1.');
}
if (!str_contains($html, 'id="migration-review-install-mode"')) {
    throw new \RuntimeException('Mode confirmation missing in the final review.');
}

echo "PASS: first-step offline checkbox is optional, unchecked, and unique; online remains default.\n";
