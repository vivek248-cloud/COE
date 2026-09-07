<?php
declare(strict_types=1);

abstract class Model
{
    public function __construct(protected PDO $db) {}

    abstract protected function table(): string;

    protected function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM `{$this->table()}` WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    protected function countRows(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM `{$this->table()}`");
        return (int)$stmt->fetchColumn();
    }

    protected function insert(array $data): int
    {
        $fields = array_keys($data);
        $columns = implode(', ', array_map(fn($f) => "`$f`", $fields));
        $params = implode(', ', array_map(fn($f) => ":$f", $fields));
        $stmt = $this->db->prepare("INSERT INTO `{$this->table()}` ($columns) VALUES ($params)");
        $stmt->execute($data);
        return (int)$this->db->lastInsertId();
    }

    protected function insertRow(array $data): int
    {
        return $this->insert($data);
    }

    protected function update(int $id, array $data): bool
    {
        $set=[]; $params=['id'=>$id];
        foreach($data as $field=>$value){ $key='p_'.$field; $set[]="`$field` = :$key"; $params[$key]=$value; }
        $stmt=$this->db->prepare("UPDATE `{$this->table()}` SET ".implode(', ',$set)." WHERE id=:id");
        $stmt->execute($params); return $stmt->rowCount() > 0;
    }

    protected function updateRow(int $id, array $data): bool
    {
        return $this->update($id, $data);
    }

    protected function delete(int $id): bool
    {
        $stmt=$this->db->prepare("DELETE FROM `{$this->table()}` WHERE id=:id");
        $stmt->execute(['id'=>$id]); return $stmt->rowCount() > 0;
    }

    protected function deleteRow(int $id): bool
    {
        return $this->delete($id);
    }
}
