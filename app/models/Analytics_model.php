<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Api_model.php';

class Analytics_model extends Api_model
{
    public function hr_dashboard(int $year): array
    {
        return $this->people_summary($year) + [
            'absences_by_weekday' => array_values($this->absences_by_weekday_90d()),
        ];
    }

    public function absences_by_weekday_90d(): array
    {
        $rows = $this->rows(
            "SELECT DAYOFWEEK(work_date) AS d, COUNT(*) AS c FROM attendance
              WHERE status = 'absent' AND work_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY d"
        );
        $weekdays = array_fill(2, 5, 0);
        foreach ($rows as $r) {
            if (isset($weekdays[(int) $r['d']])) {
                $weekdays[(int) $r['d']] = (int) $r['c'];
            }
        }
        return $weekdays;
    }

    public function accounting_summary(int $year, string $office = ''): array
    {
        $bp = [$year];
        $ep = [$year];
        $bf = $ef = '';
        if ($office !== '') {
            $bf = ' AND office = ?';
            $ef = ' AND office = ?';
            $bp[] = $office;
            $ep[] = $office;
        }

        return [
            'budgets' => $this->rows("SELECT office, amount FROM budgets WHERE fiscal_year = ?$bf", $bp),
            'spent' => $this->rows("SELECT office, SUM(amount) AS total FROM expenses WHERE YEAR(expense_date) = ?$ef GROUP BY office", $ep),
            'categories' => $this->rows("SELECT category, SUM(amount) AS total FROM expenses WHERE YEAR(expense_date) = ?$ef GROUP BY category ORDER BY total DESC", $ep),
            'months' => $this->rows("SELECT MONTH(expense_date) AS m, SUM(amount) AS total FROM expenses WHERE YEAR(expense_date) = ?$ef GROUP BY m", $ep),
            'flagged' => $this->scalar("SELECT COUNT(*) FROM expenses WHERE flagged = 1 AND YEAR(expense_date) = ?$ef", $ep),
        ];
    }

    public function accounting_dashboard(int $year, string $office = ''): array
    {
        $summary = $this->accounting_summary($year, $office);
        $byOffice = [];

        foreach ($summary['budgets'] as $budget) {
            $byOffice[$budget['office']] = [
                'office' => $budget['office'],
                'budget' => (float) $budget['amount'],
                'actual' => 0.0,
            ];
        }
        foreach ($summary['spent'] as $spent) {
            $byOffice[$spent['office']] = $byOffice[$spent['office']] ?? [
                'office' => $spent['office'],
                'budget' => 0.0,
            ];
            $byOffice[$spent['office']]['actual'] = (float) $spent['total'];
        }

        ksort($byOffice);
        $byOffice = array_values($byOffice);
        $totalBudget = array_sum(array_column($byOffice, 'budget'));
        $totalSpent = array_sum(array_column($byOffice, 'actual'));
        $categories = $summary['categories'];

        foreach ($categories as &$category) {
            $category['total'] = (float) $category['total'];
        }
        unset($category);

        $months = array_fill(1, 12, 0.0);
        foreach ($summary['months'] as $month) {
            $months[(int) $month['m']] = (float) $month['total'];
        }

        return $this->people_summary($year) + [
            'year' => $year,
            'kpis' => [
                'total_budget' => $totalBudget,
                'total_expenses' => $totalSpent,
                'used_percent' => $totalBudget > 0 ? round($totalSpent / $totalBudget * 100, 1) : null,
                'variance' => $totalBudget - $totalSpent,
                'flagged' => (int) $summary['flagged'],
            ],
            'by_office' => $byOffice,
            'by_category' => $categories,
            'by_month' => array_values($months),
        ];
    }

    public function people_summary(int $year): array
    {
        $leave = array_fill(1, 12, 0);
        foreach ($this->rows(
            "SELECT MONTH(start_date) AS m, SUM(days) AS d FROM leave_requests
              WHERE status = 'approved' AND YEAR(start_date) = ? GROUP BY m",
            [$year]
        ) as $r) {
            $leave[(int) $r['m']] = (int) $r['d'];
        }
        $head = $this->rows(
            "SELECT COALESCE(department, 'Unassigned') AS office, COUNT(*) AS total
               FROM employees WHERE is_active = 1 GROUP BY office ORDER BY total DESC, office"
        );
        foreach ($head as &$h) {
            $h['total'] = (int) $h['total'];
        }
        return ['leave_days_by_month' => array_values($leave), 'headcount' => $head];
    }
}
