<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function client_ip(): string
{
    // Do not trust X-Forwarded-For unless your production reverse proxy is
    // explicitly configured as trusted.
    return substr($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN', 0, 45);
}

function audit_log(?int $userId, string $action, string $description = ''): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_logs
             (user_id, action, description, ip_address, user_agent, created_at)
             VALUES (:user_id, :action, :description, :ip, :ua, NOW())'
        );

        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':description' => $description,
            ':ip' => client_ip(),
            ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN', 0, 500),
        ]);
    } catch (Throwable $e) {
        error_log('QPS audit logging failed: ' . $e->getMessage());
    }
}
