<?php

declare(strict_types=1);

require_once __DIR__ . '/Model.php';

final class Blueprint extends Model
{
    protected function table(): string
    {
        return 'blueprints';
    }

    public function find(int $id): ?array { return $this->findById($id); }
    public function count(): int { return $this->countRows(); }
    public function create(array $data): int { return $this->insertRow($this->filterFields($data)); }
    public function update(int $id, array $data): bool { return $this->updateRow($id, $this->filterFields($data)); }
    public function delete(int $id): bool { return $this->deleteRow($id); }

    private function filterFields(array $data): array
    {
        $allowed = ['question_bank_id', 'name', 'total_marks', 'instructions', 'status'];
        return array_intersect_key($data, array_flip($allowed));
    }
}
