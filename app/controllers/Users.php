<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

/**
 * Account management. There is no public registration: only MIS creates accounts.
 */
class Users extends Base_api
{
    private const ROLES = ['admin', 'hr', 'employee', 'mis', 'accounting'];

    /** GET /api/users  (mis, admin read-only) */
    public function index()
    {
        $this->api->require_method('GET');
        $this->authenticate(['mis', 'admin']);

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

    /** POST /api/users  { username, email, password, role, employee_id? }  (mis) */
    public function store()
    {
        $this->api->require_method('POST');
        $this->authenticate(['mis']);
        $in = $this->json_body();

        $data = $this->validated($in, null);
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';
        if (strlen($password) < 8) {
            $this->fail('Please fix the highlighted fields.', 422, ['password' => 'Use at least 8 characters.']);
        }

        $id = $this->insert('users', [
            'username' => $data['username'],
            'email'    => $data['email'],
            'phone'    => $data['phone'],
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'role'     => $data['role'],
        ]);
        if ($data['employee_id'] !== null) {
            $this->run('UPDATE employees SET user_id = ? WHERE id = ?', [$id, $data['employee_id']]);
        }
        $this->audit('user_created', 'Created account ' . $data['username'] . ' (' . $data['role'] . ')');
        $this->ok(['message' => 'Account created.', 'id' => $id], 201);
    }

    /** PUT /api/users/{id}  { username, email, role, is_active, employee_id }  (mis) */
    public function update($id)
    {
        $this->api->require_method('PUT');
        $me = $this->authenticate(['mis']);
        $id = (int) $id;
        $this->find($id);
        $in = $this->json_body();

        $data = $this->validated($in, $id);
        $active = !empty($in['is_active']) ? 1 : 0;

        if ($id === $me['id'] && ($data['role'] !== $me['role'] || !$active)) {
            $this->fail('You cannot change your own role or deactivate your own account.', 409);
        }

        $this->run(
            'UPDATE users SET username = ?, email = ?, phone = ?, role = ?, is_active = ?, updated_at = ? WHERE id = ?',
            [$data['username'], $data['email'], $data['phone'], $data['role'], $active, date('Y-m-d H:i:s'), $id]
        );

        // Re-link the employee profile.
        $this->run('UPDATE employees SET user_id = NULL WHERE user_id = ?', [$id]);
        if ($data['employee_id'] !== null) {
            $this->run('UPDATE employees SET user_id = ? WHERE id = ?', [$id, $data['employee_id']]);
        }
        if (!$active) {
            $this->run('DELETE FROM refresh_tokens WHERE user_id = ?', [$id]);
        }
        $this->audit('user_updated', 'Updated account ' . $data['username'] . ' (' . $data['role'] . ($active ? '' : ', disabled') . ')');
        $this->ok(['message' => 'Account updated.']);
    }

    /** POST /api/users/{id}/password  { password }  (mis) */
    public function reset_password($id)
    {
        $this->api->require_method('POST');
        $this->authenticate(['mis']);
        $id = (int) $id;
        $this->find($id);

        $in = $this->json_body();
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

    /** DELETE /api/users/{id}  (mis) */
    public function destroy($id)
    {
        $this->api->require_method('DELETE');
        $me = $this->authenticate(['mis']);
        $id = (int) $id;
        $this->find($id);
        if ($id === $me['id']) {
            $this->fail('You cannot delete your own account.', 409);
        }
        $this->run('DELETE FROM refresh_tokens WHERE user_id = ?', [$id]);
        $this->run('UPDATE employees SET user_id = NULL WHERE user_id = ?', [$id]);
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

        if ($errors) {
            $this->fail('Please fix the highlighted fields.', 422, $errors);
        }
        return ['username' => $username, 'email' => $email, 'phone' => $phone !== '' ? $phone : null, 'role' => $role, 'employee_id' => $emp];
    }
}
