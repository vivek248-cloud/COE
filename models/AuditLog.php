<?php

declare(strict_types=1);

require_once __DIR__ . '/Model.php';

final class AuditLog extends Model
{
    protected function table(): string
    {
        return 'audit_logs';
    }

    public function create(array $data): int
    {
        return $this->insertRow($this->filterFields($data));
    }

    private function filterFields(array $data): array
    {
        $allowed = ['user_id', 'user_type', 'action', 'module', 'record_id', 'ip_address', 'details'];
        return array_intersect_key($data, array_flip($allowed));
    }
}
