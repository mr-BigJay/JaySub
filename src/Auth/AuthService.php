<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use App\Core\Session;
use App\Services\AuditLogService;

final class AuthService
{
    public static function isRateLimited(string $type, string $username, string $ip, int $maxAttempts, int $lockoutMinutes): bool
    {
        try {
            $minutes = max(1, min(1440, $lockoutMinutes));
            $stmt = Database::pdo()->prepare(
                'SELECT COUNT(*) AS cnt FROM login_attempts
                 WHERE login_type = :t AND username = :u AND ip_address = :ip
                   AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL ' . $minutes . ' MINUTE)'
            );
            $stmt->execute(['t' => $type, 'u' => $username, 'ip' => $ip]);
            $row = $stmt->fetch();
            return (int) ($row['cnt'] ?? 0) >= $maxAttempts;
        } catch (\PDOException) {
            return false;
        }
    }

    public static function recordAttempt(string $type, string $username, string $ip, bool $success): void
    {
        try {
            Database::pdo()->prepare(
                'INSERT INTO login_attempts (login_type, username, ip_address, attempted_at, success) VALUES (:t, :u, :ip, NOW(), :s)'
            )->execute(['t' => $type, 'u' => $username, 'ip' => $ip, 's' => $success ? 1 : 0]);
        } catch (\PDOException) {
            // Table missing or DB error — do not block login
        }
    }

    public static function loginAdmin(string $username, string $password, int $maxAttempts, int $lockoutMinutes): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (self::isRateLimited('admin', $username, $ip, $maxAttempts, $lockoutMinutes)) {
            return false;
        }
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE username = :u AND is_active = 1 LIMIT 1');
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();
        $ok = $user !== false && password_verify($password, $user['password_hash']);
        self::recordAttempt('admin', $username, $ip, $ok);
        if (!$ok) {
            return false;
        }
        Session::set('admin_id', (int) $user['id']);
        Session::set('admin_username', $user['username']);
        AuditLogService::log('admin', (int) $user['id'], 'login', null, null);
        return true;
    }

    public static function loginCustomer(string $username, string $password, int $maxAttempts, int $lockoutMinutes): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (self::isRateLimited('customer', $username, $ip, $maxAttempts, $lockoutMinutes)) {
            return false;
        }
        $stmt = Database::pdo()->prepare('SELECT * FROM customers WHERE username = :u AND is_active = 1 LIMIT 1');
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();
        $ok = $user !== false && password_verify($password, $user['password_hash']);
        self::recordAttempt('customer', $username, $ip, $ok);
        if (!$ok) {
            return false;
        }
        Session::set('customer_id', (int) $user['id']);
        Session::set('customer_name', $user['name']);
        AuditLogService::log('customer', (int) $user['id'], 'login', null, null);
        return true;
    }

    public static function adminId(): ?int
    {
        $id = Session::get('admin_id');
        return is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
    }

    public static function customerId(): ?int
    {
        $id = Session::get('customer_id');
        return is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
    }

    public static function logout(): void
    {
        Session::destroy();
    }
}
