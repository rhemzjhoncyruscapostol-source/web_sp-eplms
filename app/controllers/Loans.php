<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

class Loans extends Base_api
{
    private $loans;

    public function __construct()
    {
        parent::__construct();
        $this->loans = $this->call->model('Loans_model', 'loans');
    }

    public function index()
    {
        $this->api->require_method('GET');
        $this->authenticate(['hr']);
        $this->ok(['loans' => $this->loans->list_for_hr()]);
    }

    public function mine()
    {
        $this->api->require_method('GET');
        $this->authenticate(['employee']);
        $this->ok(['loans' => $this->loans->list_for_employee($this->my_employee_id())]);
    }

    public function store()
    {
        $this->api->require_method('POST');
        $me = $this->authenticate(['hr']);
        $input = $this->api->body();
        $employeeId = (int) ($input['employee_id'] ?? 0);
        $name = $this->text($input['loan_name'] ?? '', 120);
        $principal = $input['principal'] ?? null;
        $installment = $input['installment'] ?? null;
        if (!$this->row('SELECT id FROM employees WHERE id = ? AND is_active = 1', [$employeeId])) {
            $this->fail('Choose an active employee.', 422, ['employee_id' => 'Choose an active employee.']);
        }
        if ($name === '' || !is_numeric($principal) || $principal <= 0 || !is_numeric($installment) || $installment <= 0 || $installment > $principal) {
            $this->fail('Enter a loan name, positive principal, and installment no greater than the principal.', 422);
        }
        $remarks = $this->text($input['remarks'] ?? '', 255);
        $id = $this->insert('employee_loans', [
            'employee_id' => $employeeId,
            'loan_name' => $name,
            'principal' => round((float) $principal, 2),
            'installment' => round((float) $installment, 2),
            'balance' => round((float) $principal, 2),
            'remarks' => $remarks !== '' ? $remarks : null,
            'created_by' => $me['id'],
        ]);
        $this->audit('loan_added', 'Added loan #' . $id . ' for employee #' . $employeeId);
        $this->ok(['message' => 'Loan recorded.', 'id' => $id], 201);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');
        $this->authenticate(['hr']);
        $id = (int) $id;
        $input = $this->api->body();
        $name = $this->text($input['loan_name'] ?? '', 120);
        $installment = $input['installment'] ?? null;
        $current = $this->row('SELECT principal, balance FROM employee_loans WHERE id = ? AND status = "active"', [$id]);
        if (!$current) {
            $this->fail('Active loan not found.', 404);
        }
        if ($name === '' || !is_numeric($installment) || $installment <= 0 || $installment > (float) $current['principal']) {
            $this->fail('Enter a loan name and positive installment no greater than the original principal.', 422);
        }
        $remarks = $this->text($input['remarks'] ?? '', 255);
        $this->loans->update_terms($id, $name, round((float) $installment, 2), $remarks !== '' ? $remarks : null);
        $this->audit('loan_updated', 'Updated loan #' . $id);
        $this->ok(['message' => 'Loan updated.']);
    }

    public function destroy($id)
    {
        $this->api->require_method('DELETE');
        $this->authenticate(['hr']);
        $id = (int) $id;
        if (!$this->loans->cancel($id)) {
            $this->fail('Active loan not found.', 404);
        }
        $this->audit('loan_cancelled', 'Cancelled loan #' . $id);
        $this->ok(['message' => 'Loan cancelled.']);
    }
}
