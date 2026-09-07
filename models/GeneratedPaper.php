<?php

declare(strict_types=1);

require_once __DIR__ . '/Model.php';

final class GeneratedPaper extends Model
{
    protected function table(): string
    {
        return 'generated_papers';
    }

    public function find(int $id): ?array { return $this->findById($id); }
    public function count(): int { return $this->countRows(); }
    public function create(array $data): int { return $this->insertRow($this->filterFields($data)); }

    private function filterFields(array $data): array
    {
        $allowed = [
            'question_bank_id', 'blueprint_id', 'paper_title',
            'semester_name', 'course_code', 'academic_year',
            'file_name', 'file_path', 'status',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
