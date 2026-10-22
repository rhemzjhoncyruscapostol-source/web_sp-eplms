<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Api_model.php';

class Auth_model extends Api_model
{
    public function find_login_user(string $identifier): ?array
    {
        return $this->row(
            'SELECT u.* FROM users u LEFT JOIN employees e ON e.user_id = u.id
              WHERE u.username = ? OR u.email = ? OR e.employee_no = ? LIMIT 1',
            [$identifier, $identifier, $identifier]
        );
    }

    public function active_challenge(string $hash): ?array
    {
        return $this->row(
            'SELECT * FROM otp_challenges WHERE challenge_hash = ? AND used = 0 AND expires_at > NOW() LIMIT 1',
            [$hash]
        );
    }

    public function increment_challenge_attempts(int $id): int
    {
        return $this->run('UPDATE otp_challenges SET attempts = attempts + 1 WHERE id = ?', [$id]);
    }

    public function user(int $id): ?array
    {
        return $this->row('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function mark_challenge_used(int $id): int
    {
        return $this->run('UPDATE otp_challenges SET used = 1 WHERE id = ?', [$id]);
    }

    public function resend_challenge(int $id, string $codeHash): int
    {
        return $this->run(
            'UPDATE otp_challenges SET code_hash = ?, attempts = 0, resends = resends + 1,
                    expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?',
            [$codeHash, $id]
        );
    }

    public function store_challenge(int $userId, string $challengeHash, string $codeHash): int
    {
        return $this->run(
            'INSERT INTO otp_challenges (user_id, challenge_hash, code_hash, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))',
            [$userId, $challengeHash, $codeHash]
        );
    }

    public function stored_refresh_token(string $hash): ?array
    {
        return $this->row(
            'SELECT id FROM refresh_tokens WHERE token = ? AND expires_at > NOW() LIMIT 1',
            [$hash]
        );
    }

    public function active_user_role(int $id): ?array
    {
        return $this->row('SELECT id, role, is_active FROM users WHERE id = ?', [$id]);
    }

    public function profile(int $id): ?array
    {
        return $this->row(
            'SELECT u.id, u.username, u.email, u.phone, u.role,
                    e.id AS employee_id, e.employee_no, e.full_name, e.job_title, e.department, e.date_hired,
                    c.vacation, c.sick, c.special
               FROM users u
          LEFT JOIN employees e ON e.user_id = u.id
          LEFT JOIN leave_credits c ON c.employee_id = e.id
              WHERE u.id = ?',
            [$id]
        );
    }
}
