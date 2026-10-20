<?php

declare(strict_types=1);

use App\Services\OfflineXuiInstaller;
use App\Services\XuiMigrationRestore;
use App\Services\XuiRemoteScriptLib;
use PHPUnit\Framework\TestCase;

final class XuiRemoteScriptLibTest extends TestCase
{
    public function testTarValidationRejectsUnsafeMemberNames(): void
    {
        if (!is_executable('/bin/bash')) {
            self::markTestSkipped('bash required');
        }
        $listing = sys_get_temp_dir() . '/unsafe-list-' . bin2hex(random_bytes(3));
        file_put_contents($listing, "../x-ui/x-ui\n");
        $script = '#!/bin/bash
' . XuiRemoteScriptLib::bashValidateTarArchiveBlock() . '
validate_tar_members() {
  local archive=$1
  local listing="$2"
  local unsafe
  unsafe="$(mktemp)"
  grep -E "(^/|\.\./|(^|/)\.\.(/|$))" "$listing" >"$unsafe" || true
  if [ -s "$unsafe" ]; then
    echo "Unsafe paths in archive" >&2
    rm -f "$unsafe"
    return 1
  fi
  rm -f "$unsafe"
  return 0
}
validate_tar_members /dev/null ' . escapeshellarg($listing) . '
';
        $path = tempnam(sys_get_temp_dir(), 'tval-');
        file_put_contents($path, $script);
        exec('bash ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        self::assertNotSame(0, $code, implode("\n", $out));
        @unlink($listing);
        @unlink($path);
    }

    public function testInstallAndRestoreScriptsPassBashSyntaxCheck(): void
    {
        if (!is_executable('/bin/bash')) {
            self::markTestSkipped('bash required');
        }
        $remote = OfflineXuiInstaller::remotePackagePath(str_repeat('e', 32), 'amd64');
        foreach ([
            OfflineXuiInstaller::remoteInstallScript('amd64', $remote),
            XuiMigrationRestore::remoteRestoreScript(
                XuiMigrationRestore::remoteBackupPath('panel.db')
            ),
        ] as $i => $body) {
            $path = tempnam(sys_get_temp_dir(), 'xui-sh-');
            file_put_contents($path, $body);
            exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, 'script ' . $i . ': ' . implode("\n", $out));
            @unlink($path);
        }
    }
}
