<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Api_model.php';

class Users_model extends Api_model
{
    public function list_users(): array
    {
        return $this->rows(
            'SELECT u.id, u.username, u.email, u.phone, u.role, u.is_active, u.created_at,
                    e.id AS employee_id, e.full_name, e.department
               FROM users u LEFT JOIN employees e ON e.user_id = u.id
           ORDER BY u.created_at DESC, u.id DESC'
        );
    }

    public function find(int $id): ?array
    {
        return $this->row('SELECT id, role FROM users WHERE id = ?', [$id]);
    }

    public function username_taken(string $username, ?int $self_id): bool
    {
        return (bool) $this->row('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, (int) $self_id]);
    }

    public function email_taken(string $email, ?int $self_id): bool
    {
        return (bool) $this->row('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, (int) $self_id]);
    }

    public function employee_profile(int $employee_id): ?array
    {
        return $this->row('SELECT id, user_id FROM employees WHERE id = ?', [$employee_id]);
    }

    public function unlink_employee(int $user_id): int
    {
        return $this->run('UPDATE employees SET user_id = NULL WHERE user_id = ?', [$user_id]);
    }

    public function attach_employee(int $user_id, int $employee_id): int
    {
        return $this->run('UPDATE employees SET user_id = ? WHERE id = ?', [$user_id, $employee_id]);
    }

    public function revoke_refresh_tokens(int $user_id): int
    {
        return $this->run('DELETE FROM refresh_tokens WHERE user_id = ?', [$user_id]);
    }
}
