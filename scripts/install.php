<?php

declare(strict_types=1);

/**
 * CLI: php scripts/install.php [admin_password]
 * Applies schema and sets admin password.
 */

$root = dirname(__DIR__);
$configExample = $root . '/config/config.example.php';
$configFile = $root . '/config/config.php';

if (!is_file($configFile)) {
    copy($configExample, $configFile);
    echo "Created config/config.php from example — edit database credentials.\n";
}

$config = require $configFile;
$db = $config['database'];
$schemaFile = $root . '/database/schema.sql';

if (!is_file($schemaFile)) {
    throw new RuntimeException('schema.sql not found');
}

$imported = importSchemaViaMysqlCli($db, $schemaFile);
if (!$imported) {
    importSchemaViaPdo($db, $schemaFile);
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    (int) $db['port'],
    $db['name'],
    $db['charset'] ?? 'utf8mb4'
);
$pdo = new PDO($dsn, $db['user'], $db['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$password = $argv[1] ?? 'Admin@12345';
$hash = password_hash($password, PASSWORD_DEFAULT);
$pdo->prepare('UPDATE users SET password_hash = :h WHERE username = \'admin\'')->execute(['h' => $hash]);

echo "Install complete. Admin password: {$password}\n";

/** @param array<string, mixed> $db */
function importSchemaViaMysqlCli(array $db, string $schemaFile): bool
{
    $mysql = trim((string) shell_exec('command -v mysql 2>/dev/null') ?: '');
    if ($mysql === '') {
        return false;
    }

    $cnf = tempnam(sys_get_temp_dir(), 'jaysub-mysql-');
    if ($cnf === false) {
        return false;
    }
    $cnfContent = "[client]\nuser=" . $db['user'] . "\npassword=" . str_replace(["\n", "\r"], '', (string) $db['password']) . "\n";
    file_put_contents($cnf, $cnfContent);
    chmod($cnf, 0600);

    $host = escapeshellarg((string) $db['host']);
    $port = (int) $db['port'];
    $schema = escapeshellarg($schemaFile);
    $defaults = escapeshellarg($cnf);
    $cmd = "{$mysql} --defaults-extra-file={$defaults} -h {$host} -P {$port} < {$schema} 2>&1";
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    @unlink($cnf);

    if ($code !== 0) {
        $msg = implode("\n", $output);
        if (str_contains($msg, 'already exists')) {
            return true;
        }
        fwrite(STDERR, "mysql import warning: {$msg}\n");
        return $code === 0;
    }

    return true;
}

/** @param array<string, mixed> $db */
function importSchemaViaPdo(array $db, string $schemaFile): void
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;charset=%s',
        $db['host'],
        (int) $db['port'],
        $db['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $sql = file_get_contents($schemaFile);
    if ($sql === false) {
        throw new RuntimeException('Cannot read schema.sql');
    }

    // Split on semicolon + newline (avoids semicolons inside COMMENT strings)
    $parts = preg_split('/;\s*\R/', $sql) ?: [];
    foreach ($parts as $statement) {
        $statement = trim($statement);
        if ($statement === '' || str_starts_with($statement, '--')) {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'already exists')) {
                continue;
            }
            throw $e;
        }
    }
}
