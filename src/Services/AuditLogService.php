<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class AuditLogService
{
    public static function log(
        string $actorType,
        ?int $actorId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $details = null,
    ): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = Database::pdo()->prepare(
            'INSERT INTO audit_logs (actor_type, actor_id, action, entity_type, entity_id, details, ip_address)
             VALUES (:actor_type, :actor_id, :action, :entity_type, :entity_id, :details, :ip)'
        );
        $stmt->execute([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            'ip' => $ip,
        ]);
    }
}
