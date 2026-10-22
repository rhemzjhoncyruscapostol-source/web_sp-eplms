<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Api_model.php';

class Payroll_model extends Api_model
{
    public function current_rules(string $period): array
    {
        $row = $this->row(
            "SELECT rules FROM payroll_schedules WHERE effective_from <= ? ORDER BY effective_from DESC LIMIT 1",
            [$period . '-01']
        );
        if (!$row) {
            throw new RuntimeException('No payroll contribution schedule is effective for this pay period.');
        }
        $rules = json_decode($row['rules'], true);
        if (!is_array($rules)) {
            throw new RuntimeException('The effective payroll contribution schedule is invalid.');
        }
        return $rules;
    }

    public function calculate(array $input, array $rules): array
    {
        $gross = round($input['basic'] + $input['allowances'] + $input['taxable_benefits'] + $input['non_taxable_benefits'], 2);
        $contributory = $input['basic'] + $input['allowances'] + $input['taxable_benefits'];
        $sssRules = $rules['sss'];
        $salaryCredit = $contributory > 0
            ? min(
                (float) $sssRules['maximum_salary_credit'],
                max((float) $sssRules['minimum_salary_credit'], ceil($contributory / (float) $sssRules['salary_credit_step']) * (float) $sssRules['salary_credit_step'])
            )
            : 0;
        $sss = round($salaryCredit * (float) $sssRules['rate'], 2);

        $healthRules = $rules['philhealth'];
        $healthBase = $contributory > 0
            ? min((float) $healthRules['maximum_salary'], max((float) $healthRules['minimum_salary'], $contributory))
            : 0;
        $philhealth = round($healthBase * (float) $healthRules['rate'] * (float) $healthRules['employee_share'], 2);

        $fundRules = $rules['pagibig'];
        $fundBase = min((float) $fundRules['maximum_fund_salary'], $contributory);
        $fundRate = $fundBase <= (float) $fundRules['low_salary_ceiling']
            ? (float) $fundRules['low_salary_rate']
            : (float) $fundRules['high_salary_rate'];
        $pagibig = round($fundBase * $fundRate, 2);

        $taxableBase = max(0, $contributory - $sss - $philhealth - $pagibig);
        $tax = $this->withholding_tax($taxableBase, $rules['withholding_tax']);
        $deductions = round($input['other_deductions'] + $sss + $philhealth + $pagibig + $tax, 2);
        return [
            'gross' => $gross,
            'sss' => $sss,
            'philhealth' => $philhealth,
            'pagibig' => $pagibig,
            'withholding_tax' => $tax,
            'deductions' => $deductions,
            'net_before_loans' => round($gross - $deductions, 2),
        ];
    }

    public function create_salary(array $input, array $rules, int $createdBy): array
    {
        $calculated = $this->calculate($input, $rules);
        if ($calculated['net_before_loans'] < 0) {
            throw new DomainException('Payroll deductions cannot exceed gross pay.');
        }

        $this->db->transaction();
        try {
            if ($this->row('SELECT id FROM salaries WHERE employee_id = ? AND period = ?', [$input['employee_id'], $input['period']])) {
                throw new DomainException('This employee already has a salary record for that month.');
            }

            $salaryId = $this->insert('salaries', [
                'employee_id' => $input['employee_id'],
                'period' => $input['period'],
                'basic' => $input['basic'],
                'allowances' => $input['allowances'],
                'taxable_benefits' => $input['taxable_benefits'],
                'non_taxable_benefits' => $input['non_taxable_benefits'],
                'deductions' => $input['other_deductions'],
                'sss' => $calculated['sss'],
                'philhealth' => $calculated['philhealth'],
                'pagibig' => $calculated['pagibig'],
                'withholding_tax' => $calculated['withholding_tax'],
                'net' => $calculated['net_before_loans'],
                'remarks' => $input['remarks'],
                'created_by' => $createdBy,
            ]);
            $repayments = $this->repay_loans((int) $input['employee_id'], $salaryId, $calculated['net_before_loans']);
            $totalLoans = array_sum(array_column($repayments, 'amount'));
            $net = round($calculated['net_before_loans'] - $totalLoans, 2);
            $this->run('UPDATE salaries SET net = ? WHERE id = ?', [$net, $salaryId]);
            $this->db->commit();
            return ['id' => $salaryId, 'repayments' => $repayments, 'net' => $net];
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public function restore_repayments(int $salaryId): void
    {
        $repayments = $this->rows('SELECT loan_id, amount FROM loan_repayments WHERE salary_id = ? FOR UPDATE', [$salaryId]);
        foreach ($repayments as $repayment) {
            $this->run(
                "UPDATE employee_loans SET balance = balance + ?, status = IF(status = 'paid', 'active', status) WHERE id = ?",
                [$repayment['amount'], $repayment['loan_id']]
            );
        }
        $this->run('DELETE FROM loan_repayments WHERE salary_id = ?', [$salaryId]);
    }

    public function repay_loans(int $employeeId, int $salaryId, float $available): array
    {
        $loans = $this->rows(
            "SELECT id, loan_name, installment, balance FROM employee_loans
              WHERE employee_id = ? AND status = 'active' AND balance > 0 ORDER BY id FOR UPDATE",
            [$employeeId]
        );
        $repayments = [];
        foreach ($loans as $loan) {
            $amount = round(min((float) $loan['installment'], (float) $loan['balance'], $available), 2);
            if ($amount <= 0) {
                continue;
            }
            $this->insert('loan_repayments', ['loan_id' => $loan['id'], 'salary_id' => $salaryId, 'amount' => $amount]);
            $balance = round((float) $loan['balance'] - $amount, 2);
            $this->run(
                'UPDATE employee_loans SET balance = ?, status = ?, updated_at = ? WHERE id = ?',
                [$balance, $balance <= 0 ? 'paid' : 'active', date('Y-m-d H:i:s'), $loan['id']]
            );
            $repayments[] = ['loan_id' => (int) $loan['id'], 'loan_name' => $loan['loan_name'], 'amount' => $amount, 'balance' => $balance];
            $available = round($available - $amount, 2);
        }
        return $repayments;
    }

    private function withholding_tax(float $taxable, array $brackets): float
    {
        foreach ($brackets as $bracket) {
            if ($bracket['up_to'] === null || $taxable <= (float) $bracket['up_to']) {
                return round((float) $bracket['base_tax'] + max(0, $taxable - (float) $bracket['excess_over']) * (float) $bracket['rate'], 2);
            }
        }
        throw new RuntimeException('The withholding tax schedule has no upper tax bracket.');
    }
}
