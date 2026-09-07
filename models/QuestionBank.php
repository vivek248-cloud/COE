<?php
declare(strict_types=1);
require_once __DIR__.'/Model.php';

final class QuestionBank extends Model
{
    protected function table(): string { return 'question_banks'; }

    public function count(): int { return (int)$this->db->query('SELECT COUNT(*) FROM question_banks')->fetchColumn(); }

    public function all(string $search='', string $status=''): array
    {
        $where=[]; $params=[];
        if($search!==''){ $where[]='(bank_code LIKE :search OR title LIKE :search OR course_code LIKE :search OR course_name LIKE :search)'; $params['search']='%'.$search.'%'; }
        if(in_array($status,['draft','active','archived'],true)){ $where[]='status=:status'; $params['status']=$status; }
        $sql='SELECT * FROM question_banks'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY updated_at DESC, id DESC';
        $stmt=$this->db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
    }

    public function find(int $id): ?array { return $this->findById($id); }

    public function create(array $data): int { return $this->insert($this->filter($data)); }
    public function updateById(int $id,array $data): bool { return $this->update($id,$this->filter($data)); }
    public function deleteById(int $id): bool { return $this->delete($id); }

    private function filter(array $data): array
    {
        $allowed=['bank_code','title','university','department','course_code','course_name','semester','academic_year','source_file','status','total_questions'];
        return array_intersect_key($data,array_flip($allowed));
    }
}
