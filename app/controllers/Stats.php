<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

class Stats extends Base_api
{
    /** GET /api/stats - numbers for the dashboard cards. What you get depends on your role. */
    public function index()
    {
        $this->api->require_method('GET');
        $me = $this->authenticate(['admin', 'hr', 'mis', 'accounting']);
        $out = [];

        $out['employees'] = [
            'active'   => (int) $this->scalar('SELECT COUNT(*) FROM employees WHERE is_active = 1'),
            'inactive' => (int) $this->scalar('SELECT COUNT(*) FROM employees WHERE is_active = 0'),
        ];

        if (in_array($me['role'], ['admin', 'hr', 'mis'], true)) {
            $leave = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0];
            foreach ($this->rows('SELECT status, COUNT(*) AS c FROM leave_requests GROUP BY status') as $r) {
                $leave[$r['status']] = (int) $r['c'];
            }
            $out['leave'] = $leave;
            $out['on_leave_today'] = (int) $this->scalar(
                "SELECT COUNT(*) FROM leave_requests WHERE status = 'approved' AND CURDATE() BETWEEN start_date AND end_date"
            );
            $out['absent_this_month'] = (int) $this->scalar(
                "SELECT COUNT(*) FROM attendance WHERE status = 'absent'
                  AND YEAR(work_date) = YEAR(CURDATE()) AND MONTH(work_date) = MONTH(CURDATE())"
            );
        }

        if (in_array($me['role'], ['admin', 'mis'], true)) {
            $roles = [];
            foreach ($this->rows('SELECT role, COUNT(*) AS c FROM users WHERE is_active = 1 GROUP BY role') as $r) {
                $roles[$r['role']] = (int) $r['c'];
            }
            $out['users_by_role'] = $roles;
            $out['accounts'] = [
                'total'  => (int) $this->scalar('SELECT COUNT(*) FROM users'),
                'active' => (int) $this->scalar('SELECT COUNT(*) FROM users WHERE is_active = 1'),
                'locked' => (int) $this->scalar('SELECT COUNT(*) FROM users WHERE is_active = 0'),
            ];
            $out['failed_logins_24h'] = (int) $this->scalar(
                "SELECT COUNT(*) FROM audit_log WHERE action = 'login_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            $out['recent_audit'] = $this->rows(
                'SELECT id, username, role, action, detail, created_at FROM audit_log ORDER BY id DESC LIMIT 5'
            );
        }

        $this->ok($out);
    }
}
