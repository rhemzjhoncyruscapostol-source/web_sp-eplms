<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Api_model.php';

class Audit_model extends Api_model
{
    public function search(string $where, array $params, int $limit, int $offset): array
    {
        return $this->rows(
            "SELECT id, username, role, action, detail, ip, created_at FROM audit_log $where
              ORDER BY id DESC LIMIT $limit OFFSET $offset",
            $params
        );
    }

    public function total(string $where, array $params): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM audit_log $where", $params);
    }
}
