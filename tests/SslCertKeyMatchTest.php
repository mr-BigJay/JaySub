<?php

declare(strict_types=1);

use App\Services\SslMigrationTransfer;
use App\Services\XuiRemoteScriptLib;
use PHPUnit\Framework\TestCase;

final class SslCertKeyMatchTest extends TestCase
{
    public function testSslTreeScriptUsesPubkeySha256(): void
    {
        $script = SslMigrationTransfer::remoteExtractAndVerifyScript(
            '/root/cert',
            '/tmp/jaysub-certs.zip',
            '.zip',
            'abc123def4567890abc123def4567890',
        );
        self::assertStringContainsString('verify_cert_key_match', $script);
        self::assertStringContainsString('pubout -outform DER', $script);
        self::assertStringNotContainsString('noout -modulus', $script);
    }

    public function testOpenSslPositiveAndNegativePairs(): void
    {
        if (!is_executable('/usr/bin/openssl')) {
            self::markTestSkipped('openssl not available');
        }

        $dir = sys_get_temp_dir() . '/jaysub-ssl-match-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $rsaKey = $dir . '/rsa.key';
        $rsaCert = $dir . '/rsa.crt';
        $ecKey = $dir . '/ec.key';
        $ecCert = $dir . '/ec.crt';
        $badKey = $dir . '/bad.key';

        try {
            exec('openssl req -x509 -newkey rsa:2048 -keyout ' . escapeshellarg($rsaKey)
                . ' -out ' . escapeshellarg($rsaCert) . ' -days 1 -nodes -subj /CN=rsa 2>/dev/null', $out, $code);
            self::assertSame(0, $code);

            exec('openssl ecparam -name prime256v1 -genkey -noout -out ' . escapeshellarg($ecKey) . ' 2>/dev/null', $out, $code);
            self::assertSame(0, $code);
            exec('openssl req -x509 -key ' . escapeshellarg($ecKey) . ' -out ' . escapeshellarg($ecCert)
                . ' -days 1 -subj /CN=ec 2>/dev/null', $out, $code);
            self::assertSame(0, $code);

            file_put_contents($badKey, "not a private key\n");

            $lib = XuiRemoteScriptLib::bashCertKeyPubkeySha256Block();
            $runner = $dir . '/run.sh';
            file_put_contents($runner, $lib . "\n"
                . 'verify_cert_key_match ' . escapeshellarg($rsaCert) . ' ' . escapeshellarg($rsaKey) . ' rsa' . "\n"
                . 'verify_cert_key_match ' . escapeshellarg($ecCert) . ' ' . escapeshellarg($ecKey) . ' ec' . "\n"
                . 'if verify_cert_key_match ' . escapeshellarg($rsaCert) . ' ' . escapeshellarg($ecKey) . ' bad; then exit 9; fi' . "\n"
                . 'if verify_cert_key_match ' . escapeshellarg($rsaCert) . ' ' . escapeshellarg($badKey) . ' missing; then exit 8; fi' . "\n"
                . 'exit 0' . "\n");
            exec('bash ' . escapeshellarg($runner) . ' 2>&1', $output, $exit);
            self::assertSame(0, $exit, implode("\n", $output));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }
}
