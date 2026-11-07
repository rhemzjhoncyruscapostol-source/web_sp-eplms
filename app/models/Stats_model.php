<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Api_model.php';

class Stats_model extends Api_model
{
    public function employee_counts(): array
    {
        return [
            'active'   => (int) $this->scalar('SELECT COUNT(*) FROM employees WHERE is_active = 1'),
            'inactive' => (int) $this->scalar('SELECT COUNT(*) FROM employees WHERE is_active = 0'),
        ];
    }

    public function leave_summary(): array
    {
        $leave = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0];
        foreach ($this->rows('SELECT status, COUNT(*) AS c FROM leave_requests GROUP BY status') as $r) {
            $leave[$r['status']] = (int) $r['c'];
        }
        return $leave;
    }

    public function on_leave_today(): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM leave_requests WHERE status = 'approved' AND leave_type <> 'travel'
              AND CURDATE() BETWEEN start_date AND end_date"
        );
    }

    public function absent_this_month(): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM attendance WHERE status = 'absent'
              AND YEAR(work_date) = YEAR(CURDATE()) AND MONTH(work_date) = MONTH(CURDATE())"
        );
    }

    public function user_roles(): array
    {
        $roles = [];
        foreach ($this->rows('SELECT role, COUNT(*) AS c FROM users WHERE is_active = 1 GROUP BY role') as $r) {
            $roles[$r['role']] = (int) $r['c'];
        }
        return $roles;
    }

    public function account_summary(): array
    {
        return [
            'total'  => (int) $this->scalar('SELECT COUNT(*) FROM users'),
            'active' => (int) $this->scalar('SELECT COUNT(*) FROM users WHERE is_active = 1'),
            'locked' => (int) $this->scalar('SELECT COUNT(*) FROM users WHERE is_active = 0'),
        ];
    }

    public function failed_logins_24h(): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'login_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
    }

    public function recent_audit(): array
    {
        return $this->rows(
            'SELECT id, username, role, action, detail, created_at FROM audit_log ORDER BY id DESC LIMIT 5'
        );
    }
}
