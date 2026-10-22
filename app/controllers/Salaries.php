<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

class Salaries extends Base_api
{
    private $payroll;

    public function __construct()
    {
        parent::__construct();
        $this->payroll = $this->call->model('Payroll_model', 'payroll');
    }

    /** GET /api/me/salaries  (employee) - only the logged-in employee's own records. */
    public function mine()
    {
        $this->api->require_method('GET');
        $this->authenticate(['employee']);
        $emp = $this->my_employee_id();

        $rows = $this->rows(
            'SELECT id, period, basic, allowances, taxable_benefits, non_taxable_benefits,
                    deductions, sss, philhealth, pagibig, withholding_tax, net, remarks
               FROM salaries WHERE employee_id = ? ORDER BY period DESC',
            [$emp]
        );
        $this->attach_repayments($rows);
        $this->ok(['salaries' => array_map([$this, 'shape'], $rows)]);
    }

    /** GET /api/salaries?period=2026-09  (HR) */
    public function index()
    {
        $this->api->require_method('GET');
        $this->authenticate(['hr']);

        $params = [];
        $where = '';
        $period = $_GET['period'] ?? '';
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $where = 'WHERE s.period = ?';
            $params[] = $period;
        }

        $rows = $this->rows(
            "SELECT s.id, s.employee_id, s.period, s.basic, s.allowances, s.taxable_benefits,
                    s.non_taxable_benefits, s.deductions, s.sss, s.philhealth, s.pagibig,
                    s.withholding_tax, s.net, s.remarks,
                    e.full_name, e.job_title, e.department
               FROM salaries s JOIN employees e ON e.id = s.employee_id
               $where
           ORDER BY s.period DESC, e.full_name",
            $params
        );
        $this->attach_repayments($rows);
        $this->ok(['salaries' => array_map([$this, 'shape'], $rows)]);
    }

    /** POST /api/salaries  (HR) */
    public function store()
    {
        $this->api->require_method('POST');
        $me = $this->authenticate(['hr']);
        $in = $this->json_body();

        $data = $this->validated($in);
        $employee = (int) ($in['employee_id'] ?? 0);
        if (!$this->row('SELECT id FROM employees WHERE id = ?', [$employee])) {
            $this->fail('Choose an employee.', 422, ['employee_id' => 'Choose an employee.']);
        }
        $data['employee_id'] = $employee;
        $rules = $this->payroll->current_rules($data['period']);
        try {
            $result = $this->payroll->create_salary($data, $rules, (int) $me['id']);
        } catch (DomainException $e) {
            $this->fail($e->getMessage(), 409);
        }

        $this->notify_employee($employee, 'Salary posted', 'Your salary for ' . $data['period'] . ' is now available.');
        $this->audit('salary_added', 'Salary record #' . $result['id'] . ' for employee #' . $employee . ' (' . $data['period'] . ')');
        $this->ok(['message' => 'Salary record saved.', 'id' => $result['id']], 201);
    }

    /** PUT /api/salaries/{id}  (HR) */
    public function update($id)
    {
        $this->api->require_method('PUT');
        $this->authenticate(['hr']);
        $id = (int) $id;

        $current = $this->row('SELECT id, employee_id FROM salaries WHERE id = ?', [$id]);
        if (!$current) {
            $this->fail('Salary record not found.', 404);
        }
        $data = $this->validated($this->json_body());
        $clash = $this->row(
            'SELECT id FROM salaries WHERE employee_id = ? AND period = ? AND id <> ?',
            [$current['employee_id'], $data['period'], $id]
        );
        if ($clash) {
            $this->fail('This employee already has a salary record for that month.', 409);
        }

        $rules = $this->payroll->current_rules($data['period']);
        $calculated = $this->payroll->calculate($data, $rules);
        if ($calculated['net_before_loans'] < 0) {
            $this->fail('Payroll deductions cannot exceed gross pay.', 422);
        }
        $this->db_transactional_update($id, $current, $data, $calculated);
        $this->audit('salary_updated', 'Salary record #' . $id . ' updated');
        $this->ok(['message' => 'Salary record updated.']);
    }

    /** DELETE /api/salaries/{id}  (HR) */
    public function destroy($id)
    {
        $this->api->require_method('DELETE');
        $this->authenticate(['hr']);
        $id = (int) $id;
        if (!$this->row('SELECT id FROM salaries WHERE id = ?', [$id])) {
            $this->fail('Salary record not found.', 404);
        }
        $this->data->db->transaction();
        try {
            $this->payroll->restore_repayments($id);
            $this->run('DELETE FROM salaries WHERE id = ?', [$id]);
            $this->data->db->commit();
        } catch (Throwable $e) {
            $this->data->db->roll_back();
            throw $e;
        }
        $this->audit('salary_deleted', 'Salary record #' . $id . ' deleted');
        $this->ok(['message' => 'Salary record deleted.']);
    }

    private function db_transactional_update(int $id, array $current, array $data, array $calculated): void
    {
        $this->data->db->transaction();
        try {
            $this->payroll->restore_repayments($id);
            $fields = [
                'period' => $data['period'],
                'basic' => $data['basic'],
                'allowances' => $data['allowances'],
                'taxable_benefits' => $data['taxable_benefits'],
                'non_taxable_benefits' => $data['non_taxable_benefits'],
                'deductions' => $data['other_deductions'],
                'sss' => $calculated['sss'],
                'philhealth' => $calculated['philhealth'],
                'pagibig' => $calculated['pagibig'],
                'withholding_tax' => $calculated['withholding_tax'],
                'net' => $calculated['net_before_loans'],
                'remarks' => $data['remarks'],
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $sets = implode(', ', array_map(function ($column) { return "$column = ?"; }, array_keys($fields)));
            $this->run("UPDATE salaries SET $sets WHERE id = ?", array_merge(array_values($fields), [$id]));
            $repayments = $this->payroll->repay_loans((int) $current['employee_id'], $id, $calculated['net_before_loans']);
            $loanTotal = array_sum(array_column($repayments, 'amount'));
            $this->run('UPDATE salaries SET net = ? WHERE id = ?', [round($calculated['net_before_loans'] - $loanTotal, 2), $id]);
            $this->data->db->commit();
        } catch (Throwable $e) {
            $this->data->db->roll_back();
            throw $e;
        }
    }

    // ------------------------------------------------------------ internals

    private function validated(array $in): array
    {
        $errors = [];
        $period = $this->text($in['period'] ?? '', 7);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $errors['period'] = 'Choose a month.';
        }

        $money = [];
        foreach ([
            'basic' => 'Basic pay',
            'allowances' => 'Allowances',
            'taxable_benefits' => 'Taxable benefits',
            'non_taxable_benefits' => 'Non-taxable benefits',
            'deductions' => 'Other deductions',
        ] as $field => $label) {
            $raw = $in[$field] ?? 0;
            if ($raw === '' || $raw === null) {
                $raw = 0;
            }
            if (!is_numeric($raw) || $raw < 0 || $raw > 99999999) {
                $errors[$field] = "$label must be a number of 0 or more.";
                $money[$field] = 0;
            } else {
                $money[$field] = round((float) $raw, 2);
            }
        }
        if ($errors) {
            $this->fail('Please fix the highlighted fields.', 422, $errors);
        }

        $remarks = $this->text($in['remarks'] ?? '', 255);
        return [
            'period'     => $period,
            'basic'      => $money['basic'],
            'allowances' => $money['allowances'],
            'taxable_benefits' => $money['taxable_benefits'],
            'non_taxable_benefits' => $money['non_taxable_benefits'],
            'other_deductions' => $money['deductions'],
            'remarks'    => $remarks !== '' ? $remarks : null,
        ];
    }

    private function attach_repayments(array &$rows): void
    {
        if (!$rows) {
            return;
        }
        $ids = array_map(function ($row) { return (int) $row['id']; }, $rows);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $repayments = $this->rows(
            "SELECT r.salary_id, r.loan_id, r.amount, l.loan_name
               FROM loan_repayments r JOIN employee_loans l ON l.id = r.loan_id
              WHERE r.salary_id IN ($marks) ORDER BY r.id",
            $ids
        );
        $bySalary = [];
        foreach ($repayments as $repayment) {
            $repayment['loan_id'] = (int) $repayment['loan_id'];
            $repayment['amount'] = (float) $repayment['amount'];
            $bySalary[$repayment['salary_id']][] = $repayment;
        }
        foreach ($rows as &$row) {
            $row['loan_repayments'] = $bySalary[$row['id']] ?? [];
        }
        unset($row);
    }

    private function shape(array $r): array
    {
        foreach (['id', 'employee_id'] as $k) {
            if (array_key_exists($k, $r)) {
                $r[$k] = (int) $r[$k];
            }
        }
        foreach (['basic', 'allowances', 'taxable_benefits', 'non_taxable_benefits', 'deductions', 'sss', 'philhealth', 'pagibig', 'withholding_tax', 'net'] as $k) {
            $r[$k] = (float) $r[$k];
        }
        return $r;
    }
}
