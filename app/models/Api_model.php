<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Shared persistence model for the JSON API.
 *
 * Controllers keep request and response handling; all database execution
 * passes through this model.
 */
class Api_model extends Model
{
    public function rows(string $sql, array $params = []): array
    {
        return $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function row(string $sql, array $params = []): ?array
    {
        $row = $this->db->raw($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function run(string $sql, array $params = []): int
    {
        return $this->db->raw($sql, $params)->rowCount();
    }

    public function scalar(string $sql, array $params = [])
    {
        $row = $this->db->raw($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }

    public function insert(string $table, array $fields): int
    {
        return (int) $this->db->table($table)->insert($fields);
    }
}
