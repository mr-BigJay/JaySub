<?php

declare(strict_types=1);

// Standalone SQLite compatibility smoke test; no SSH/network/production DB required.
// Run: php tests/SqliteFallbackSmoke.php
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\XuiRemoteScriptLib;

function check(bool $passed, string $message): void
{
    if (!$passed) {
        throw new RuntimeException($message);
    }
}

exec("python3 -c 'import sqlite3' >/dev/null 2>&1", $ignore, $pyCode);
if ($pyCode !== 0 || !is_executable('/bin/bash')) {
    echo "SKIP: Python 3 sqlite3 and bash required\n";
    exit(0);
}
$root = sys_get_temp_dir() . '/jaysub-sqlite-fallback-' . bin2hex(random_bytes(6));
check(mkdir($root, 0700), 'Could not make temporary test directory');
$pathDir = $root . '/bin';
$source = $root . '/source.db';
$target = $root . '/backup.db';
$script = $root . '/test.sh';
try {
    check(mkdir($pathDir, 0700), 'Could not make isolated PATH directory');
    $python = trim((string) shell_exec('command -v python3'));
    check($python !== '' && is_executable($python), 'Python 3 executable not found');
    check(symlink($python, $pathDir . '/python3'), 'Could not link isolated Python');
    $fixture = 'import sqlite3,sys;'
        . 'c=sqlite3.connect(sys.argv[1]);'
        . 'c.execute("CREATE TABLE settings (key TEXT, value TEXT)");'
        . 'c.execute("INSERT INTO settings VALUES (?, ?)", ("webCertFile", "/root/cert/test.pem"));'
        . 'c.commit();c.close()';
    exec('python3 -c ' . escapeshellarg($fixture) . ' ' . escapeshellarg($source) . ' 2>&1', $fixtureOut, $fixtureCode);
    check($fixtureCode === 0, 'Could not initialize temporary SQLite database: ' . implode("\n", $fixtureOut));
    $body = <<<'BASH'
set -euo pipefail
__FALLBACK__
[ "$(type -P sqlite3 || true)" = "" ]
[ "$(sqlite3 __SRC__ 'PRAGMA quick_check;')" = "ok" ]
[ "$(sqlite3 __SRC__ "SELECT value FROM settings WHERE key='webCertFile' LIMIT 1;")" = "/root/cert/test.pem" ]
[ "$(sqlite3 -separator '|' __SRC__ "SELECT key,value FROM settings WHERE value LIKE '/%';")" = "webCertFile|/root/cert/test.pem" ]
sqlite3 __SRC__ ".backup '__DST_RAW__'"
[ "$(sqlite3 __DST__ 'PRAGMA quick_check;')" = "ok" ]
[ "$(sqlite3 __DST__ "SELECT count(*) FROM settings;")" = "1" ]
BASH;
    $body = str_replace(
        ['__FALLBACK__', '__SRC__', '__DST__', '__DST_RAW__'],
        [
            XuiRemoteScriptLib::bashSqliteFallbackBlock(),
            escapeshellarg($source),
            escapeshellarg($target),
            $target,
        ],
        $body
    );
    check(file_put_contents($script, $body) !== false, 'Could not write test script');
    exec('/bin/bash -n ' . escapeshellarg($script) . ' 2>&1', $syntaxOut, $syntaxCode);
    check($syntaxCode === 0, 'Generated bash syntax error: ' . implode("\n", $syntaxOut));
    // An isolated PATH forces the Python fallback even when sqlite3 is
    // installed on the management server. No system binary is modified.
    exec(
        'env PATH=' . escapeshellarg($pathDir) . ' /bin/bash ' . escapeshellarg($script) . ' 2>&1',
        $out,
        $exitCode
    );
    check($exitCode === 0, 'SQLite fallback verification failed: ' . implode("\n", $out));
    echo "PASS: SQLite CLI absent, Python fallback, quick_check, query, snapshot backup\n";
} finally {
    @unlink($script);
    @unlink($source);
    @unlink($target);
    @unlink($pathDir . '/python3');
    @rmdir($pathDir);
    @rmdir($root);
}
