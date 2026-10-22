<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Api_model.php';

class Loans_model extends Api_model
{
    public function list_for_hr(): array
    {
        return $this->rows(
            'SELECT l.id, l.employee_id, l.loan_name, l.principal, l.installment, l.balance,
                    l.status, l.remarks, e.full_name, e.employee_no
               FROM employee_loans l JOIN employees e ON e.id = l.employee_id
              ORDER BY l.status = "active" DESC, e.full_name, l.created_at DESC'
        );
    }

    public function list_for_employee(int $employeeId): array
    {
        $loans = $this->rows(
            'SELECT id, loan_name, principal, installment, balance, status, remarks, created_at
               FROM employee_loans WHERE employee_id = ? ORDER BY created_at DESC',
            [$employeeId]
        );
        foreach ($loans as &$loan) {
            $loan['repayments'] = $this->rows(
                'SELECT r.amount, s.period, r.created_at FROM loan_repayments r
                  JOIN salaries s ON s.id = r.salary_id WHERE r.loan_id = ? ORDER BY s.period DESC',
                [(int) $loan['id']]
            );
        }
        unset($loan);
        return $loans;
    }

    public function update_terms(int $id, string $name, float $installment, ?string $remarks): int
    {
        return $this->run(
            'UPDATE employee_loans SET loan_name = ?, installment = ?, remarks = ?, updated_at = ? WHERE id = ? AND status = "active"',
            [$name, $installment, $remarks, date('Y-m-d H:i:s'), $id]
        );
    }

    public function cancel(int $id): int
    {
        return $this->run(
            'UPDATE employee_loans SET status = "cancelled", updated_at = ? WHERE id = ? AND status = "active"',
            [date('Y-m-d H:i:s'), $id]
        );
    }
}
