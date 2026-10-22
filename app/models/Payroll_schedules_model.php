<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Api_model.php';

class Payroll_schedules_model extends Api_model
{
    public function all_schedules(): array
    {
        $rows = $this->rows('SELECT id, effective_from, rules FROM payroll_schedules ORDER BY effective_from DESC');
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['rules'] = json_decode($row['rules'], true);
        }
        unset($row);
        return $rows;
    }

    public function save_schedule(string $effectiveFrom, array $rules, int $userId): int
    {
        return $this->insert('payroll_schedules', [
            'effective_from' => $effectiveFrom,
            'rules' => json_encode($rules, JSON_UNESCAPED_SLASHES),
            'created_by' => $userId,
        ]);
    }
}
