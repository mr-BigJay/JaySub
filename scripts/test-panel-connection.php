<?php

declare(strict_types=1);

/**
 * Test 3x-ui API from the same host/PHP as JaySub (useful when curl works elsewhere but admin test fails).
 *
 *   php scripts/test-panel-connection.php --panel-id 1
 *   php scripts/test-panel-connection.php --url 'https://host:port/path' --token 'PLAIN_TOKEN'
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
$encryption = new \App\Core\Encryption((string) $config['security']['encryption_key']);

$panelId = null;
$url = null;
$token = null;
$argv = $_SERVER['argv'] ?? [];
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--panel-id' && isset($argv[$i + 1])) {
        $panelId = (int) $argv[++$i];
    } elseif ($argv[$i] === '--url' && isset($argv[$i + 1])) {
        $url = $argv[++$i];
    } elseif ($argv[$i] === '--token' && isset($argv[$i + 1])) {
        $token = $argv[++$i];
    }
}

if ($panelId !== null && $panelId > 0) {
    \App\Core\Database::init($config['database']);
    $stmt = \App\Core\Database::pdo()->prepare('SELECT id, name, base_url, api_token_encrypted FROM vpn_panels WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $panelId]);
    $row = $stmt->fetch();
    if ($row === false) {
        fwrite(STDERR, "Panel id {$panelId} not found.\n");
        exit(1);
    }
    $url = (string) $row['base_url'];
    try {
        $token = $encryption->decrypt((string) $row['api_token_encrypted']);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Cannot decrypt stored token (check security.encryption_key in config.php).\n");
        exit(1);
    }
    echo 'Panel #' . $panelId . ' ' . $row['name'] . "\n";
    echo 'base_url: ' . $url . "\n";
}

if ($url === null || $token === null || trim($url) === '' || trim($token) === '') {
    fwrite(STDERR, "Usage: php scripts/test-panel-connection.php --panel-id ID\n");
    fwrite(STDERR, "   or: php scripts/test-panel-connection.php --url URL --token TOKEN\n");
    exit(1);
}

$token = \App\Xui\XuiToken::normalize($token);
echo 'token length: ' . strlen($token) . " chars\n";

$client = new \App\Xui\XuiClient(rtrim(trim($url), '/'), $token);
$result = $client->getServerStatus();
if ($result['ok'] ?? false) {
    $ver = is_array($result['data']['obj'] ?? null) ? ($result['data']['obj']['panelVersion'] ?? '?') : '?';
    echo "OK — panel API reachable (panelVersion: {$ver})\n";
    exit(0);
}

$code = (int) ($result['http_code'] ?? 0);
$err = (string) ($result['error'] ?? 'unknown');
fwrite(STDERR, "FAILED (HTTP {$code}): {$err}\n");
exit(1);
