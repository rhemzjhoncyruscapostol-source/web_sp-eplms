<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Api_model.php';

class Employees_model extends Api_model
{
    public function list_employees(): array
    {
        return $this->rows(
            'SELECT e.id, e.user_id, e.employee_no, e.full_name, e.job_title, e.department, e.reports_to,
                    e.date_hired, e.is_active, b.full_name AS reports_to_name, u.username,
                    c.vacation, c.sick, c.special
               FROM employees e
          LEFT JOIN employees b ON b.id = e.reports_to
          LEFT JOIN users u ON u.id = e.user_id
          LEFT JOIN leave_credits c ON c.employee_id = e.id
           ORDER BY e.full_name'
        );
    }

    public function find(int $id): ?array
    {
        return $this->row('SELECT * FROM employees WHERE id = ?', [$id]);
    }

    public function employee_no_taken(string $no, ?int $self_id): bool
    {
        return (bool) $this->row('SELECT id FROM employees WHERE employee_no = ? AND id <> ?', [$no, (int) $self_id]);
    }

    public function exists(int $employee_id): bool
    {
        return (bool) $this->row('SELECT id FROM employees WHERE id = ?', [$employee_id]);
    }

    public function history_count(int $employee_id): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM salaries WHERE employee_id = ?', [$employee_id])
             + (int) $this->scalar('SELECT COUNT(*) FROM leave_requests WHERE employee_id = ?', [$employee_id]);
    }

    public function reassign_reports_to(int $old_boss, int $new_boss): int
    {
        return $this->run('UPDATE employees SET reports_to = ? WHERE reports_to = ?', [$new_boss, $old_boss]);
    }

    public function default_credits(int $employee_id): int
    {
        return $this->run(
            'INSERT IGNORE INTO leave_credits (employee_id, vacation, sick, special) VALUES (?, ?, ?, ?)',
            [$employee_id, 15, 15, 3]
        );
    }

    public function save_credits(int $employee_id, array $credits): int
    {
        return $this->run(
            'UPDATE leave_credits SET vacation = ?, sick = ?, special = ?, updated_at = ? WHERE employee_id = ?',
            [$credits['vacation'], $credits['sick'], $credits['special'], date('Y-m-d H:i:s'), $employee_id]
        );
    }

    public function supervisor(int $employee_id): ?array
    {
        return $this->row('SELECT id FROM employees WHERE id = ?', [$employee_id]);
    }

    public function reports_to(int $employee_id): ?string
    {
        return $this->scalar('SELECT reports_to FROM employees WHERE id = ?', [$employee_id]);
    }

    public function creates_loop(int $id, int $boss): bool
    {
        $seen = [];
        $cursor = $boss;
        while ($cursor !== null && !isset($seen[$cursor])) {
            if ($cursor === $id) {
                return true;
            }
            $seen[$cursor] = true;
            $next = $this->scalar('SELECT reports_to FROM employees WHERE id = ?', [$cursor]);
            $cursor = $next !== null ? (int) $next : null;
        }
        return false;
    }
}
