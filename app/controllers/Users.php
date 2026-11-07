<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

/**
 * Account management. There is no public registration: MIS and HR manage accounts.
 */
class Users extends Base_api
{
    private const ROLES = ['admin', 'hr', 'employee', 'mis', 'accounting'];

    /** GET /api/users  (hr, mis, admin read-only) */
    public function index()
    {
        $this->api->require_method('GET');
        $this->authenticate(['hr', 'mis', 'admin']);

        $rows = $this->rows(
            'SELECT u.id, u.username, u.email, u.phone, u.role, u.is_active, u.created_at,
                    e.id AS employee_id, e.full_name, e.department
               FROM users u LEFT JOIN employees e ON e.user_id = u.id
           ORDER BY u.created_at DESC, u.id DESC'
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['is_active'] = (int) $r['is_active'];
            $r['employee_id'] = $r['employee_id'] !== null ? (int) $r['employee_id'] : null;
        }
        $this->ok(['users' => $rows]);
    }

    /** POST /api/users  { username, email, password, role, employee_id? }  (hr, mis) */
    public function store()
    {
        $this->api->require_method('POST');
        $me = $this->authenticate(['hr', 'mis']);
        $in = $this->api->body();

        $data = $this->validated($in, null);
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';
        if (strlen($password) < 8) {
            $this->fail('Please fix the highlighted fields.', 422, ['password' => 'Use at least 8 characters.']);
        }

        $this->data->db->transaction();
        try {
            $id = $this->insert('users', [
                'username' => $data['username'],
                'email'    => $data['email'],
                'phone'    => $data['phone'],
                'password' => password_hash($password, PASSWORD_BCRYPT),
                'role'     => $data['role'],
            ]);
            if ($data['employee_id'] !== null) {
                $qrToken = $data['role'] === 'employee' ? bin2hex(random_bytes(32)) : null;
                $this->run(
                    'UPDATE employees SET user_id = ?, qr_token = ? WHERE id = ?',
                    [$id, $qrToken, $data['employee_id']]
                );
                if ($data['monthly_salary'] !== null) {
                    $this->record_salary(
                        $data['employee_id'],
                        $data['monthly_salary'],
                        $data['salary_effective_from'],
                        (int) $me['id']
                    );
                }
                if ($data['employee_is_active'] !== null) {
                    $this->run(
                        'UPDATE employees SET is_active = ? WHERE id = ?',
                        [$data['employee_is_active'] ? 1 : 0, $data['employee_id']]
                    );
                    $this->run(
                        'INSERT INTO leave_credits (employee_id, vacation, sick, special, updated_at)
                         VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE vacation = VALUES(vacation), sick = VALUES(sick),
                                                 special = VALUES(special), updated_at = VALUES(updated_at)',
                        [
                            $data['employee_id'],
                            $data['credits']['vacation'],
                            $data['credits']['sick'],
                            $data['credits']['special'],
                            date('Y-m-d H:i:s'),
                        ]
                    );
                }
            }
            $this->data->db->commit();
        } catch (Throwable $e) {
            $this->data->db->roll_back();
            throw $e;
        }
        $this->audit('user_created', 'Created account ' . $data['username'] . ' (' . $data['role'] . ')');
        $this->ok(['message' => 'Account created.', 'id' => $id], 201);
    }

    /** PUT /api/users/{id}  { username, email, role, is_active, employee_id }  (hr, mis) */
    public function update($id)
    {
        $this->api->require_method('PUT');
        $me = $this->authenticate(['hr', 'mis']);
        $id = (int) $id;
        $this->find($id);
        $in = $this->api->body();

        $data = $this->validated($in, $id);
        $active = !empty($in['is_active']) ? 1 : 0;

        if ($id === $me['id'] && ($data['role'] !== $me['role'] || !$active)) {
            $this->fail('You cannot change your own role or deactivate your own account.', 409);
        }

        $currentEmployeeId = $this->scalar('SELECT id FROM employees WHERE user_id = ?', [$id]);
        $currentEmployeeId = $currentEmployeeId !== null ? (int) $currentEmployeeId : null;
        $currentQrToken = $currentEmployeeId === null
            ? null
            : $this->scalar('SELECT qr_token FROM employees WHERE id = ?', [$currentEmployeeId]);

        $this->data->db->transaction();
        try {
            $this->run(
                'UPDATE users SET username = ?, email = ?, phone = ?, role = ?, is_active = ?, updated_at = ? WHERE id = ?',
                [$data['username'], $data['email'], $data['phone'], $data['role'], $active, date('Y-m-d H:i:s'), $id]
            );

            if ($currentEmployeeId !== null && $currentEmployeeId !== $data['employee_id']) {
                $this->run('UPDATE employees SET user_id = NULL, qr_token = NULL WHERE id = ?', [$currentEmployeeId]);
            }
            if ($data['employee_id'] !== null) {
                $qrToken = null;
                if ($data['role'] === 'employee') {
                    $qrToken = $currentEmployeeId === $data['employee_id'] && $currentQrToken
                        ? $currentQrToken
                        : bin2hex(random_bytes(32));
                }
                $this->run(
                    'UPDATE employees SET user_id = ?, qr_token = ? WHERE id = ?',
                    [$id, $qrToken, $data['employee_id']]
                );
            }
            if (!$active) {
                $this->run('DELETE FROM refresh_tokens WHERE user_id = ?', [$id]);
            }
            $this->data->db->commit();
        } catch (Throwable $e) {
            $this->data->db->roll_back();
            throw $e;
        }
        $this->audit('user_updated', 'Updated account ' . $data['username'] . ' (' . $data['role'] . ($active ? '' : ', disabled') . ')');
        $this->ok(['message' => 'Account updated.']);
    }

    /** POST /api/users/{id}/password  { password }  (hr, mis) */
    public function reset_password($id)
    {
        $this->api->require_method('POST');
        $this->authenticate(['hr', 'mis']);
        $id = (int) $id;
        $this->find($id);

        $in = $this->api->body();
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';
        if (strlen($password) < 8) {
            $this->fail('Use at least 8 characters.', 422, ['password' => 'Use at least 8 characters.']);
        }
        $this->run(
            'UPDATE users SET password = ?, updated_at = ? WHERE id = ?',
            [password_hash($password, PASSWORD_BCRYPT), date('Y-m-d H:i:s'), $id]
        );
        $this->run('DELETE FROM refresh_tokens WHERE user_id = ?', [$id]); // sign them out everywhere
        $this->audit('password_reset', 'Reset password for user #' . $id);
        $this->ok(['message' => 'Password changed. The user has been signed out.']);
    }

    /** DELETE /api/users/{id}  (hr, mis) */
    public function destroy($id)
    {
        $this->api->require_method('DELETE');
        $me = $this->authenticate(['hr', 'mis']);
        $id = (int) $id;
        $this->find($id);
        if ($id === $me['id']) {
            $this->fail('You cannot delete your own account.', 409);
        }
        $this->run('DELETE FROM refresh_tokens WHERE user_id = ?', [$id]);
        $this->run('UPDATE employees SET user_id = NULL, qr_token = NULL WHERE user_id = ?', [$id]);
        $this->run('DELETE FROM users WHERE id = ?', [$id]);
        $this->audit('user_deleted', 'Deleted user #' . $id);
        $this->ok(['message' => 'Account deleted.']);
    }

    // ------------------------------------------------------------ internals

    private function find(int $id): array
    {
        $user = $this->row('SELECT id, role FROM users WHERE id = ?', [$id]);
        if (!$user) {
            $this->fail('Account not found.', 404);
        }
        return $user;
    }

    private function validated(array $in, $self_id): array
    {
        $errors = [];
        $username = $this->text($in['username'] ?? '', 50);
        $email = strtolower($this->text($in['email'] ?? '', 255));
        $role = $this->text($in['role'] ?? '', 20);
        $phone = $this->text($in['phone'] ?? '', 30);
        if ($phone !== '' && !preg_match('/^\+?[0-9 ()-]{7,30}$/', $phone)) {
            $errors['phone'] = 'Use digits only, for example +639171234567.';
        }

        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            $errors['username'] = 'Use 3-50 letters, numbers, dots, dashes or underscores.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (!in_array($role, self::ROLES, true)) {
            $errors['role'] = 'Choose a role.';
        }

        if (!isset($errors['username']) && $this->row('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, (int) $self_id])) {
            $errors['username'] = 'That username is taken.';
        }
        if (!isset($errors['email']) && $this->row('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, (int) $self_id])) {
            $errors['email'] = 'That email is already used by another account.';
        }

        $emp = $in['employee_id'] ?? null;
        $emp = ($emp === null || $emp === '') ? null : (int) $emp;
        if ($emp !== null) {
            $profile = $this->row('SELECT id, user_id FROM employees WHERE id = ?', [$emp]);
            if (!$profile) {
                $errors['employee_id'] = 'That employee does not exist.';
            } elseif ($profile['user_id'] !== null && (int) $profile['user_id'] !== (int) $self_id) {
                $errors['employee_id'] = 'That employee already has an account.';
            }
        }
        if ($role === 'employee' && $emp === null) {
            $errors['employee_id'] = 'An Employee account must be linked to an employee record so they can see their own salary and leave.';
        }

        $monthlySalary = null;
        $salaryEffectiveFrom = null;
        $credits = null;
        $employeeIsActive = null;
        if (array_key_exists('monthly_salary', $in) || ($self_id === null && $role === 'employee')) {
            $rawSalary = $in['monthly_salary'] ?? null;
            if ($role !== 'employee') {
                $errors['monthly_salary'] = 'Monthly salary can only be set for an Employee account.';
            } elseif ($emp === null) {
                $errors['monthly_salary'] = 'Link an employee record before entering a salary.';
            } elseif (!is_numeric($rawSalary) || $rawSalary <= 0 || $rawSalary > 9999999999.99) {
                $errors['monthly_salary'] = 'Enter a monthly salary greater than 0.';
            } else {
                $monthlySalary = round((float) $rawSalary, 2);
            }

            $salaryEffectiveFrom = $this->text($in['salary_effective_from'] ?? '', 10);
            if ($salaryEffectiveFrom === '' || !$this->valid_date($salaryEffectiveFrom)) {
                $errors['salary_effective_from'] = 'Use a valid effective date (YYYY-MM-DD).';
            } elseif ($emp !== null && $monthlySalary !== null) {
                $profile = $this->row('SELECT monthly_salary FROM employees WHERE id = ?', [$emp]);
                $currentSalary = $profile ? (float) $profile['monthly_salary'] : null;
                if ($currentSalary !== null && $currentSalary !== $monthlySalary) {
                    $latest = $this->row(
                        'SELECT id, monthly_salary, effective_from FROM employee_salary_history
                          WHERE employee_id = ? ORDER BY effective_from DESC, id DESC LIMIT 1',
                        [$emp]
                    );
                    $historyCount = (int) $this->scalar(
                        'SELECT COUNT(*) FROM employee_salary_history WHERE employee_id = ?',
                        [$emp]
                    );
                    $isInitialZeroSalary = $currentSalary === 0.0
                        && $historyCount === 1
                        && $latest
                        && (float) $latest['monthly_salary'] === 0.0;
                    if ($latest && $salaryEffectiveFrom < $latest['effective_from'] && !$isInitialZeroSalary) {
                        $errors['salary_effective_from'] = 'A salary change cannot be backdated before the most recent salary history entry.';
                    }
                }
            }
        }

        if ($self_id === null && $role === 'employee') {
            $rawCredits = $in['credits'] ?? null;
            if (!is_array($rawCredits)) {
                $errors['credits'] = 'Enter the employee leave credits.';
            } else {
                $credits = [];
                foreach (['vacation', 'sick', 'special'] as $type) {
                    $value = $rawCredits[$type] ?? null;
                    if (!is_numeric($value) || $value < 0 || $value > 365) {
                        $errors['credits.' . $type] = 'Enter a number between 0 and 365.';
                    } else {
                        $credits[$type] = round((float) $value, 1);
                    }
                }
            }

            if (!array_key_exists('employee_is_active', $in) || !is_bool($in['employee_is_active'])) {
                $errors['employee_is_active'] = 'Choose whether the person is currently employed.';
            } else {
                $employeeIsActive = $in['employee_is_active'];
            }
        }

        if ($errors) {
            $this->fail('Please fix the highlighted fields.', 422, $errors);
        }
        return [
            'username' => $username,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'role' => $role,
            'employee_id' => $emp,
            'monthly_salary' => $monthlySalary,
            'salary_effective_from' => $salaryEffectiveFrom,
            'credits' => $credits,
            'employee_is_active' => $employeeIsActive,
        ];
    }

    private function record_salary(int $employeeId, float $monthlySalary, string $effectiveFrom, int $createdBy): void
    {
        $profile = $this->row('SELECT monthly_salary FROM employees WHERE id = ?', [$employeeId]);
        if (!$profile) {
            return;
        }

        $historyCount = (int) $this->scalar(
            'SELECT COUNT(*) FROM employee_salary_history WHERE employee_id = ?',
            [$employeeId]
        );
        if ((float) $profile['monthly_salary'] === $monthlySalary) {
            if ($historyCount === 0) {
                $this->insert('employee_salary_history', [
                    'employee_id' => $employeeId,
                    'monthly_salary' => $monthlySalary,
                    'effective_from' => $effectiveFrom,
                    'created_by' => $createdBy,
                ]);
            }
            return;
        }

        $latest = $this->row(
            'SELECT id, monthly_salary, effective_from FROM employee_salary_history
              WHERE employee_id = ? ORDER BY effective_from DESC, id DESC LIMIT 1',
            [$employeeId]
        );
        $replaceInitialZeroSalary = (float) $profile['monthly_salary'] === 0.0
            && $historyCount === 1
            && $latest
            && (float) $latest['monthly_salary'] === 0.0
            && $effectiveFrom < $latest['effective_from'];

        $this->run('UPDATE employees SET monthly_salary = ? WHERE id = ?', [$monthlySalary, $employeeId]);
        if ($replaceInitialZeroSalary) {
            $this->run(
                'UPDATE employee_salary_history SET monthly_salary = ?, effective_from = ?, created_by = ? WHERE id = ?',
                [$monthlySalary, $effectiveFrom, $createdBy, $latest['id']]
            );
            return;
        }

        $this->insert('employee_salary_history', [
            'employee_id' => $employeeId,
            'monthly_salary' => $monthlySalary,
            'effective_from' => $effectiveFrom,
            'created_by' => $createdBy,
        ]);
    }

}
