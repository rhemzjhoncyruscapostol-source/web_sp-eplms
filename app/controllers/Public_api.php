<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

/** Endpoints that need no login. They only ever expose names, positions, offices and totals. */
class Public_api extends Base_api
{
    /** GET / */
    public function index()
    {
        $this->ok(['name' => 'Sangguniang Panlalawigan Employee Leave Management System API', 'status' => 'ok']);
    }

    /** GET /api/org-chart - names and positions only. */
    public function org_chart()
    {
        $this->api->require_method('GET');
        $this->throttle();

        $rows = $this->rows(
            'SELECT id, full_name, job_title, department, reports_to
               FROM employees WHERE is_active = 1 ORDER BY full_name'
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['reports_to'] = $r['reports_to'] !== null ? (int) $r['reports_to'] : null;
        }
        $this->ok(['employees' => $rows]);
    }

    /** GET /api/directory?q=&office=&page=1&per=6 */
    public function directory()
    {
        $this->api->require_method('GET');
        $this->throttle();

        $q = $this->text($_GET['q'] ?? '', 100);
        $office = $this->text($_GET['office'] ?? '', 120);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = min(24, max(1, (int) ($_GET['per'] ?? 6)));

        $where = ['is_active = 1'];
        $params = [];
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where[] = '(full_name LIKE ? OR job_title LIKE ?)';
            array_push($params, $like, $like);
        }
        if ($office !== '') {
            $where[] = 'department = ?';
            $params[] = $office;
        }
        $clause = implode(' AND ', $where);

        $total = (int) $this->scalar("SELECT COUNT(*) FROM employees WHERE $clause", $params);
        $offset = ($page - 1) * $per;
        $rows = $this->rows(
            "SELECT id, full_name, job_title, department FROM employees
              WHERE $clause ORDER BY full_name LIMIT $per OFFSET $offset",
            $params
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
        }

        $offices = array_column($this->rows(
            'SELECT DISTINCT department FROM employees WHERE is_active = 1 AND department IS NOT NULL ORDER BY department'
        ), 'department');

        $this->ok([
            'employees' => $rows,
            'total'     => $total,
            'page'      => $page,
            'pages'     => max(1, (int) ceil($total / $per)),
            'offices'   => $offices,
        ]);
    }

    /** GET /api/public/summary - totals only, no personal or expense records. */
    public function summary()
    {
        $this->api->require_method('GET');
        $this->throttle();

        $headcount = $this->rows(
            "SELECT COALESCE(department, 'Unassigned') AS office, COUNT(*) AS total
               FROM employees WHERE is_active = 1 GROUP BY office ORDER BY total DESC, office"
        );
        foreach ($headcount as &$h) {
            $h['total'] = (int) $h['total'];
        }

        $months = array_fill(1, 12, 0);
        foreach ($this->rows(
            "SELECT MONTH(start_date) AS m, SUM(days) AS d FROM leave_requests
              WHERE status = 'approved' AND YEAR(start_date) = YEAR(CURDATE()) GROUP BY m"
        ) as $r) {
            $months[(int) $r['m']] = (int) $r['d'];
        }

        $this->ok([
            'year'           => (int) date('Y'),
            'employees'      => (int) $this->scalar('SELECT COUNT(*) FROM employees WHERE is_active = 1'),
            'offices'        => count($headcount),
            'on_leave_today' => (int) $this->scalar(
                "SELECT COUNT(*) FROM leave_requests WHERE status = 'approved' AND CURDATE() BETWEEN start_date AND end_date"
            ),
            'headcount'      => $headcount,
            'leave_days_by_month' => array_values($months),
        ]);
    }

    /** OPTIONS preflight for the browser. The Api library answers it while loading. */
    public function preflight()
    {
        exit;
    }

    private function throttle(): void
    {
        $this->api->rate_limit('public_' . $this->ip(), 120, 60);
    }
}
