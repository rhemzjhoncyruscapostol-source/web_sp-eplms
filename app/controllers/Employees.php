<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

class Employees extends Base_api
{
    /** GET /api/employees  (admin, hr, mis, accounting) */
    public function index()
    {
        $this->api->require_method('GET');
        $me = $this->authenticate(['admin', 'hr', 'mis', 'accounting']);
        $salaryColumn = in_array($me['role'], ['hr', 'mis'], true) ? 'e.monthly_salary' : 'NULL AS monthly_salary';

        $rows = $this->rows(
            "SELECT e.id, e.user_id, e.employee_no, e.full_name, e.job_title, e.department, $salaryColumn, e.reports_to,
                    e.date_hired, e.is_active, e.include_in_chart, b.full_name AS reports_to_name, u.username,
                    c.vacation, c.sick, c.special
               FROM employees e
          LEFT JOIN employees b ON b.id = e.reports_to
          LEFT JOIN users u ON u.id = e.user_id
          LEFT JOIN leave_credits c ON c.employee_id = e.id
           ORDER BY e.full_name"
        );
        $this->ok(['employees' => array_map([$this, 'shape'], $rows)]);
    }

    /** POST /api/employees  (hr, mis) */
    public function store()
    {
        $this->api->require_method('POST');
        $me = $this->authenticate(['hr', 'mis']);

        [$data, $credits] = $this->validated($this->api->body(), null);
        $salaryEffectiveFrom = $data['salary_effective_from'];
        unset($data['salary_effective_from']);
        $this->data->db->transaction();
        try {
            $id = $this->insert('employees', $data);
            if ((float) $data['monthly_salary'] > 0) {
                $this->insert('employee_salary_history', [
                    'employee_id' => $id,
                    'monthly_salary' => $data['monthly_salary'],
                    'effective_from' => $salaryEffectiveFrom,
                    'created_by' => (int) $me['id'],
                ]);
            }
            $this->give_default_credits($id);
            if ($credits) {
                $this->save_credits($id, $credits);
            }
            $this->data->db->commit();
        } catch (Throwable $e) {
            $this->data->db->roll_back();
            throw $e;
        }
        $this->audit('employee_added', 'Added employee ' . $data['full_name'] . " (#$id)");
        $this->ok(['message' => 'Employee added.', 'id' => $id], 201);
    }

    /** PUT /api/employees/{id}  (hr, mis) */
    public function update($id)
    {
        $this->api->require_method('PUT');
        $me = $this->authenticate(['hr', 'mis']);
        $id = (int) $id;
        $current = $this->find($id);

        [$data, $credits] = $this->validated($this->api->body(), $id);
        $data['updated_at'] = date('Y-m-d H:i:s');

        $salaryChanged = (float) $current['monthly_salary'] !== (float) $data['monthly_salary'];
        $this->data->db->transaction();
        try {
            $salaryEffectiveFrom = $data['salary_effective_from'];
            unset($data['salary_effective_from']);
            $sets = implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($data)));
            $this->run("UPDATE employees SET $sets WHERE id = ?", array_merge(array_values($data), [$id]));
            if ($salaryChanged) {
                $this->insert('employee_salary_history', [
                    'employee_id' => $id,
                    'monthly_salary' => $data['monthly_salary'],
                    'effective_from' => $salaryEffectiveFrom,
                    'created_by' => (int) $me['id'],
                ]);
            }
            if ($credits) {
                $this->give_default_credits($id);
                $this->save_credits($id, $credits);
            }
            $this->data->db->commit();
        } catch (Throwable $e) {
            $this->data->db->roll_back();
            throw $e;
        }
        if ($credits) {
            $this->notify_employee($id, 'Credits updated', sprintf(
                'Your leave credits are now: vacation %s, sick %s, special privilege %s.',
                $credits['vacation'], $credits['sick'], $credits['special']
            ), '/app/my-profile');
        }
        $this->audit('employee_updated', 'Updated employee ' . $data['full_name'] . " (#$id)");
        $this->ok(['message' => 'Employee updated.']);
    }

    public function salary_history($id)
    {
        $this->api->require_method('GET');
        $this->authenticate(['hr', 'mis']);
        $id = (int) $id;
        $this->find($id);
        $history = $this->rows(
            'SELECT monthly_salary, effective_from, created_at
               FROM employee_salary_history
              WHERE employee_id = ?
           ORDER BY effective_from DESC, id DESC',
            [$id]
        );
        foreach ($history as &$row) {
            $row['monthly_salary'] = (float) $row['monthly_salary'];
        }
        unset($row);
        $this->ok(['salary_history' => $history]);
    }

    /** DELETE /api/employees/{id}  (mis) - only when there is no payroll, leave or expense history. */
    public function destroy($id)
    {
        $this->api->require_method('DELETE');
        $this->authenticate(['mis']);
        $id = (int) $id;
        $emp = $this->find($id);

        $history = (int) $this->scalar('SELECT COUNT(*) FROM salaries WHERE employee_id = ?', [$id])
                 + (int) $this->scalar('SELECT COUNT(*) FROM leave_requests WHERE employee_id = ?', [$id]);
        if ($history > 0) {
            $this->fail('This employee has salary or leave records, so they cannot be deleted. Set them to inactive instead.', 409);
        }

        // People who reported to this employee move up to this employee's own supervisor.
        $this->run('UPDATE employees SET reports_to = ? WHERE reports_to = ?', [$emp['reports_to'], $id]);
        $this->run('DELETE FROM employees WHERE id = ?', [$id]);
        $this->audit('employee_deleted', 'Deleted employee ' . $emp['full_name'] . " (#$id)");
        $this->ok(['message' => 'Employee deleted.']);
    }

    // ------------------------------------------------------------ internals

    private function find(int $id): array
    {
        $emp = $this->row('SELECT * FROM employees WHERE id = ?', [$id]);
        if (!$emp) {
            $this->fail('Employee not found.', 404);
        }
        return $emp;
    }

    private function save_credits(int $id, array $c): void
    {
        $this->run(
            'UPDATE leave_credits SET vacation = ?, sick = ?, special = ?, updated_at = ? WHERE employee_id = ?',
            [$c['vacation'], $c['sick'], $c['special'], date('Y-m-d H:i:s'), $id]
        );
    }

    /** Validate input. Returns [columns for the employees table, credits array or null]. */
    private function validated(array $in, $self_id): array
    {
        $errors = [];

        $name = $this->text($in['full_name'] ?? '', 150);
        $title = $this->text($in['job_title'] ?? '', 120);
        $dept = $this->text($in['department'] ?? '', 120);
        $no = $this->text($in['employee_no'] ?? '', 30);
        if ($name === '') $errors['full_name'] = 'Full name is required.';
        if ($title === '') $errors['job_title'] = 'Job title is required.';
        if ($dept === '') $errors['department'] = 'Choose or type the office.';

        if ($no !== '' && $this->row('SELECT id FROM employees WHERE employee_no = ? AND id <> ?', [$no, (int) $self_id])) {
            $errors['employee_no'] = 'That Employee ID is already used.';
        }

        $hired = $this->text($in['date_hired'] ?? '', 10);
        if ($hired !== '' && !$this->valid_date($hired)) {
            $errors['date_hired'] = 'Use a valid date (YYYY-MM-DD).';
        }

        $rawSalary = $in['monthly_salary'] ?? ($self_id !== null ? $this->scalar('SELECT monthly_salary FROM employees WHERE id = ?', [$self_id]) : 0);
        if ($rawSalary === null || $rawSalary === '' || !is_numeric($rawSalary) || $rawSalary < 0 || $rawSalary > 9999999999.99) {
            $errors['monthly_salary'] = 'Monthly salary must be a number of 0 or more.';
            $monthlySalary = 0;
        } else {
            $monthlySalary = round((float) $rawSalary, 2);
        }
        $salaryEffectiveFrom = $this->text(
            $in['salary_effective_from'] ?? ($self_id === null ? date('Y-m-d') : ''),
            10
        );
        if ($salaryEffectiveFrom !== '' && !$this->valid_date($salaryEffectiveFrom)) {
            $errors['salary_effective_from'] = 'Use a valid effective date (YYYY-MM-DD).';
        } elseif ($salaryEffectiveFrom === '') {
            $salaryEffectiveFrom = date('Y-m-d');
        }
        if ($self_id !== null && isset($monthlySalary)) {
            $currentSalary = (float) $this->scalar('SELECT monthly_salary FROM employees WHERE id = ?', [$self_id]);
            if ($currentSalary !== $monthlySalary) {
                $latestEffectiveDate = $this->scalar(
                    'SELECT MAX(effective_from) FROM employee_salary_history WHERE employee_id = ?',
                    [$self_id]
                );
                if ($latestEffectiveDate && $salaryEffectiveFrom < $latestEffectiveDate) {
                    $errors['salary_effective_from'] = 'A salary change cannot be backdated before the most recent salary history entry.';
                }
            }
        }

        $boss = $in['reports_to'] ?? null;
        $boss = ($boss === null || $boss === '') ? null : (int) $boss;
        if ($boss !== null) {
            if (!$this->row('SELECT id FROM employees WHERE id = ?', [$boss])) {
                $errors['reports_to'] = 'That supervisor does not exist.';
            } elseif ($self_id !== null && $this->creates_loop($self_id, $boss)) {
                $errors['reports_to'] = 'An employee cannot report to themselves or to someone below them.';
            }
        }

        $credits = null;
        if (isset($in['credits']) && is_array($in['credits'])) {
            $credits = [];
            foreach (['vacation', 'sick', 'special'] as $k) {
                $v = $in['credits'][$k] ?? null;
                if (!is_numeric($v) || $v < 0 || $v > 365) {
                    $errors['credits'] = 'Leave credits must be numbers between 0 and 365.';
                    break;
                }
                $credits[$k] = round((float) $v, 1);
            }
        }

        if ($errors) {
            $this->fail('Please fix the highlighted fields.', 422, $errors);
        }

        return [[
            'employee_no' => $no !== '' ? $no : null,
            'full_name'   => $name,
            'job_title'   => $title,
            'department'  => $dept,
            'monthly_salary' => $monthlySalary,
            'salary_effective_from' => $salaryEffectiveFrom,
            'reports_to'  => $boss,
            'date_hired'  => $hired !== '' ? $hired : null,
            'is_active'   => (!empty($in['is_active']) || !array_key_exists('is_active', $in)) ? 1 : 0,
            'include_in_chart' => array_key_exists('include_in_chart', $in)
                ? (!empty($in['include_in_chart']) ? 1 : 0)
                : ($self_id !== null ? (int) $this->scalar('SELECT include_in_chart FROM employees WHERE id = ?', [$self_id]) : 0),
        ], $credits];
    }

    /** True when making $boss the supervisor of $id would create a loop in the chart. */
    private function creates_loop(int $id, int $boss): bool
    {
        $seen = [];
        $cursor = $boss;
        while ($cursor !== null && !isset($seen[$cursor])) {
            if ($cursor === $id) {
                return true;
            }
            $seen[$cursor] = true;
            $next = $this->scalar('SELECT reports_to FROM employees WHERE id = ?', [$cursor]);
            $cursor = $next !== null ? (int) $next : null;
        }
        return false;
    }

    private function shape(array $r): array
    {
        foreach (['id', 'user_id', 'reports_to'] as $k) {
            $r[$k] = $r[$k] !== null ? (int) $r[$k] : null;
        }
        $r['is_active'] = (int) $r['is_active'];
        $r['include_in_chart'] = (int) $r['include_in_chart'];
        foreach (['vacation', 'sick', 'special'] as $k) {
            $r[$k] = $r[$k] !== null ? (float) $r[$k] : null;
        }
        $r['monthly_salary'] = isset($r['monthly_salary']) && $r['monthly_salary'] !== null
            ? (float) $r['monthly_salary']
            : null;
        return $r;
    }
}
