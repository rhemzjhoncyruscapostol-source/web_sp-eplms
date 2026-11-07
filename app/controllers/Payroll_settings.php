<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

class Payroll_settings extends Base_api
{
    private $schedules;

    public function __construct()
    {
        parent::__construct();
        $this->schedules = $this->call->model('Payroll_schedules_model', 'schedules');
    }

    public function index()
    {
        $this->api->require_method('GET');
        $this->authenticate(['hr']);
        $this->ok(['schedules' => $this->schedules->all_schedules()]);
    }

    public function store()
    {
        $this->api->require_method('POST');
        $me = $this->authenticate(['hr']);
        $input = $this->api->body();
        $effectiveFrom = $this->text($input['effective_from'] ?? '', 10);
        $rules = $input['rules'] ?? null;
        if (!$this->valid_date($effectiveFrom) || $effectiveFrom < '2025-01-01') {
            $this->fail('Choose a valid effective date on or after 2025-01-01.', 422);
        }
        $this->validate_rules($rules);

        try {
            $id = $this->schedules->save_schedule($effectiveFrom, $rules, (int) $me['id']);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $this->fail('A payroll schedule already exists for that effective date.', 409);
            }
            throw $e;
        }
        $this->audit('payroll_schedule_added', 'Added payroll schedule effective ' . $effectiveFrom);
        $this->ok(['message' => 'Payroll schedule saved.', 'id' => $id], 201);
    }

    private function validate_rules($rules): void
    {
        $required = [
            'sss' => ['rate', 'minimum_salary_credit', 'maximum_salary_credit', 'salary_credit_step'],
            'philhealth' => ['rate', 'minimum_salary', 'maximum_salary', 'employee_share'],
            'pagibig' => ['low_salary_ceiling', 'low_salary_rate', 'high_salary_rate', 'maximum_fund_salary'],
        ];
        if (!is_array($rules) || !isset($rules['withholding_tax']) || !is_array($rules['withholding_tax'])) {
            $this->fail('Provide all contribution and withholding tax schedule values.', 422);
        }
        foreach ($required as $section => $fields) {
            if (!isset($rules[$section]) || !is_array($rules[$section])) {
                $this->fail('Provide all contribution and withholding tax schedule values.', 422);
            }
            foreach ($fields as $field) {
                if (!isset($rules[$section][$field]) || !is_numeric($rules[$section][$field]) || $rules[$section][$field] < 0) {
                    $this->fail('Contribution schedule values must be non-negative numbers.', 422);
                }
            }
        }
        if ($rules['sss']['rate'] > 1 || $rules['philhealth']['rate'] > 1 || $rules['philhealth']['employee_share'] > 1
            || $rules['pagibig']['low_salary_rate'] > 1 || $rules['pagibig']['high_salary_rate'] > 1
            || $rules['sss']['minimum_salary_credit'] > $rules['sss']['maximum_salary_credit']
            || $rules['sss']['salary_credit_step'] <= 0
            || $rules['philhealth']['minimum_salary'] > $rules['philhealth']['maximum_salary']
            || $rules['pagibig']['maximum_fund_salary'] <= 0
            || $rules['pagibig']['low_salary_ceiling'] > $rules['pagibig']['maximum_fund_salary']) {
            $this->fail('Contribution rates or salary limits are invalid.', 422);
        }

        $brackets = $rules['withholding_tax'];
        if (count($brackets) !== 6) {
            $this->fail('Provide exactly six withholding tax brackets.', 422);
        }
        $previousLimit = 0;
        foreach ($brackets as $index => $bracket) {
            if (!is_array($bracket) || !isset($bracket['base_tax'], $bracket['rate'], $bracket['excess_over'])
                || !is_numeric($bracket['base_tax']) || !is_numeric($bracket['rate']) || !is_numeric($bracket['excess_over'])
                || $bracket['base_tax'] < 0 || $bracket['rate'] < 0 || $bracket['rate'] > 1 || $bracket['excess_over'] < 0
                || ($index < 5 && (!isset($bracket['up_to']) || !is_numeric($bracket['up_to'])))) {
                $this->fail('Withholding tax brackets contain invalid values.', 422);
            }
            if ($index < 5) {
                if ((float) $bracket['up_to'] <= $previousLimit || (float) $bracket['excess_over'] < $previousLimit) {
                    $this->fail('Withholding tax bracket limits must increase in order.', 422);
                }
                $previousLimit = (float) $bracket['up_to'];
            } elseif ((float) $bracket['excess_over'] < $previousLimit) {
                $this->fail('The final withholding tax bracket must start after the previous bracket.', 422);
            }
        }
        if ($brackets[5]['up_to'] !== null) {
            $this->fail('The final withholding tax bracket must have no upper limit.', 422);
        }
    }
}
