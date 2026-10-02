<?php

declare(strict_types=1);

use App\Services\SshRemoteZip;

require dirname(__DIR__) . '/vendor/autoload.php';

$tmp = sys_get_temp_dir() . '/jay-sub-ssh-test-' . getmypid();
@mkdir($tmp, 0700, true);
$dir = SshRemoteZip::sshStateDirectory($tmp);
if (!is_file($dir . '/known_hosts')) {
    fwrite(STDERR, "FAIL: known_hosts not created\n");
    exit(1);
}

$ref = new ReflectionClass(SshRemoteZip::class);
$detect = $ref->getMethod('detectArchive');
$detect->setAccessible(true);
$zip = $detect->invoke(null, 'PK' . str_repeat("\0", 10));
if ($zip === null || $zip['extension'] !== '.zip') {
    fwrite(STDERR, "FAIL: zip detect\n");
    exit(1);
}
$gz = $detect->invoke(null, "\x1f\x8b" . str_repeat("\0", 10));
if ($gz === null || $gz['extension'] !== '.tar.gz') {
    fwrite(STDERR, "FAIL: gzip detect\n");
    exit(1);
}

@unlink($dir . '/known_hosts');
@rmdir($dir);
@rmdir($tmp);

echo "SshRemoteZipTest OK\n";
