<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Api_model.php';

class Public_api_model extends Api_model
{
    public function org_chart(): array
    {
        return $this->rows(
            'SELECT id, full_name, job_title, department, reports_to
               FROM employees WHERE is_active = 1 ORDER BY full_name'
        );
    }

    public function directory(string $clause, array $params, int $limit, int $offset): array
    {
        return $this->rows(
            "SELECT id, full_name, job_title, department FROM employees
              WHERE $clause ORDER BY full_name LIMIT $limit OFFSET $offset",
            $params
        );
    }

    public function office_list(): array
    {
        return $this->rows(
            'SELECT DISTINCT department FROM employees WHERE is_active = 1 AND department IS NOT NULL ORDER BY department'
        );
    }

    public function headcount(): array
    {
        return $this->rows(
            "SELECT COALESCE(department, 'Unassigned') AS office, COUNT(*) AS total
               FROM employees WHERE is_active = 1 GROUP BY office ORDER BY total DESC, office"
        );
    }

    public function leave_days_by_month_this_year(): array
    {
        return $this->rows(
            "SELECT MONTH(start_date) AS m, SUM(days) AS d FROM leave_requests
              WHERE status = 'approved' AND leave_type <> 'travel' AND YEAR(start_date) = YEAR(CURDATE()) GROUP BY m"
        );
    }
}
