<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

class Auth extends Base_api
{
    /** Roles that must enter a one-time code after their password. */
    private const OTP_ROLES = ['hr', 'accounting', 'mis', 'admin'];

    /**
     * POST /api/auth/login  { identifier, password }
     * identifier = username, email or Employee ID.
     * Returns tokens, or { otp_required, challenge } for roles that need a code.
     */
    public function login()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('login_' . $this->ip(), 10, 60);

        $body = $this->api->body();
        $identifier = $this->text($body['identifier'] ?? '', 255);
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        if ($identifier === '' || $password === '') {
            $this->fail('Enter your username (or Employee ID) and password.', 422);
        }

        $user = $this->row(
            'SELECT u.* FROM users u LEFT JOIN employees e ON e.user_id = u.id
              WHERE u.username = ? OR u.email = ? OR e.employee_no = ? LIMIT 1',
            [$identifier, $identifier, $identifier]
        );

        // Same work whether or not the account exists, so timing does not reveal valid usernames.
        $hash = $user ? $user['password'] : password_hash('not-a-real-password', PASSWORD_BCRYPT);
        $valid = password_verify($password, $hash);

        if (!$user || !$valid) {
            $this->audit('login_failed', 'Failed sign-in for "' . $identifier . '"', ['id' => $user ? (int) $user['id'] : null, 'username' => $identifier]);
            $this->fail('Incorrect username or password.', 401);
        }
        if ($user['role'] === 'viewer') {
            $this->audit('login_blocked', 'Retired viewer account attempted to sign in', $user);
            $this->fail('Viewer accounts are no longer supported. Public information is available from the home page without signing in.', 403);
        }
        if (!(int) $user['is_active']) {
            $this->audit('login_blocked', 'Sign-in attempt on a deactivated account', $user);
            $this->fail('This account has been deactivated. Please contact MIS.', 403);
        }

        if (getenv('OTP_ENABLED') !== 'false' && in_array($user['role'], self::OTP_ROLES, true)) {
            $this->start_otp($user);
        }
        $this->finish_login($user);
    }

    /** POST /api/auth/verify-otp  { challenge, code } */
    public function verify_otp()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('otp_' . $this->ip(), 20, 60);

        $body = $this->api->body();
        $challenge = $this->text($body['challenge'] ?? '', 64);
        $code = preg_replace('/\D/', '', (string) ($body['code'] ?? ''));

        $row = $this->row(
            'SELECT * FROM otp_challenges WHERE challenge_hash = ? AND used = 0 AND expires_at > NOW() LIMIT 1',
            [hash('sha256', $challenge)]
        );
        if (!$row) {
            $this->fail('That code has expired. Sign in again to get a new one.', 401);
        }
        if ((int) $row['attempts'] >= 5) {
            $this->fail('Too many wrong codes. Sign in again to get a new one.', 429);
        }
        $this->run('UPDATE otp_challenges SET attempts = attempts + 1 WHERE id = ?', [(int) $row['id']]);

        $user = $this->row('SELECT * FROM users WHERE id = ?', [(int) $row['user_id']]);
        if (!$user || !(int) $user['is_active'] || $user['role'] === 'viewer') {
            $this->run('UPDATE otp_challenges SET used = 1 WHERE id = ?', [(int) $row['id']]);
            $this->fail('This account is not active.', 403);
        }
        if (strlen($code) !== 6 || !hash_equals($row['code_hash'], $this->code_hash($code))) {
            $this->audit('otp_failed', 'Wrong sign-in code', $user);
            $this->fail('That code is not correct.', 401);
        }
        $this->run('UPDATE otp_challenges SET used = 1 WHERE id = ?', [(int) $row['id']]);
        $this->finish_login($user);
    }

    /** POST /api/auth/resend-otp  { challenge } - at most 3 times per sign-in. */
    public function resend_otp()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('otp_resend_' . $this->ip(), 10, 60);

        $challenge = $this->text($this->api->body()['challenge'] ?? '', 64);
        $row = $this->row(
            'SELECT * FROM otp_challenges WHERE challenge_hash = ? AND used = 0 AND expires_at > NOW() LIMIT 1',
            [hash('sha256', $challenge)]
        );
        if (!$row) {
            $this->fail('This sign-in has expired. Start again.', 401);
        }
        if ((int) $row['resends'] >= 3) {
            $this->fail('You have requested too many codes. Start again.', 429);
        }
        $user = $this->row('SELECT * FROM users WHERE id = ?', [(int) $row['user_id']]);
        if (!$user || !(int) $user['is_active'] || $user['role'] === 'viewer') {
            $this->run('UPDATE otp_challenges SET used = 1 WHERE id = ?', [(int) $row['id']]);
            $this->fail('This account is not active.', 403);
        }
        $code = $this->new_code();
        $this->run(
            'UPDATE otp_challenges SET code_hash = ?, attempts = 0, resends = resends + 1,
                    expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?',
            [$this->code_hash($code), (int) $row['id']]
        );
        $this->send_code($user, $code);
        $out = ['message' => 'A new code was sent.'];
        if (getenv('APP_ENV') === 'development') {
            $out['dev_code'] = $code;
        }
        $this->ok($out);
    }

    /** POST /api/auth/refresh  { refresh_token } - rotates the refresh token and keeps the real role. */
    public function refresh()
    {
        $this->api->require_method('POST');
        $body = $this->api->body();
        $refresh = is_string($body['refresh_token'] ?? null) ? $body['refresh_token'] : '';

        $payload = $this->api->validate_jwt($refresh);
        if (!$payload || ($payload['type'] ?? '') !== 'refresh') {
            $this->fail('Your session has expired. Please sign in again.', 401);
        }

        $hashed = hash_hmac('sha256', $refresh, config_item('refresh_token_key'));
        $stored = $this->row('SELECT id FROM refresh_tokens WHERE token = ? AND expires_at > NOW() LIMIT 1', [$hashed]);
        if (!$stored) {
            $this->fail('Your session has expired. Please sign in again.', 401);
        }

        $this->api->revoke_refresh_token($refresh);

        $user = $this->row('SELECT id, role, is_active FROM users WHERE id = ?', [(int) $payload['sub']]);
        if (!$user || !(int) $user['is_active'] || $user['role'] === 'viewer') {
            $this->fail('This account is not active.', 401);
        }

        $tokens = $this->api->issue_tokens(['id' => (int) $user['id'], 'role' => $user['role']]);
        $this->ok(['tokens' => $tokens]);
    }

    /** POST /api/auth/logout  { refresh_token } */
    public function logout()
    {
        $this->api->require_method('POST');
        $body = $this->api->body();
        if (is_string($body['refresh_token'] ?? null) && $body['refresh_token'] !== '') {
            $this->api->revoke_refresh_token($body['refresh_token']);
        }
        $this->ok(['message' => 'Signed out.']);
    }

    /** GET /api/auth/me */
    public function me()
    {
        $this->api->require_method('GET');
        $user = $this->authenticate();
        $this->ok(['user' => $this->profile($user['id'])]);
    }

    // ------------------------------------------------------------ internals

    private function finish_login(array $user)
    {
        $tokens = $this->api->issue_tokens(['id' => (int) $user['id'], 'role' => $user['role']]);
        $this->audit('login', 'Signed in', $user);
        $this->ok(['user' => $this->profile((int) $user['id']), 'tokens' => $tokens]);
    }

    private function start_otp(array $user)
    {
        $challenge = bin2hex(random_bytes(16));
        $code = $this->new_code();
        $this->run(
            'INSERT INTO otp_challenges (user_id, challenge_hash, code_hash, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))',
            [(int) $user['id'], hash('sha256', $challenge), $this->code_hash($code)]
        );
        $this->send_code($user, $code);

        $out = [
            'otp_required' => true,
            'challenge'    => $challenge,
            'expires_in'   => 300,
            'sent_to'      => $this->mask_email($user['email']),
        ];
        // Development convenience only: lets you test without an email/SMS provider.
        if (getenv('APP_ENV') === 'development') {
            $out['dev_code'] = $code;
        }
        $this->ok($out);
    }

    private function send_code(array $user, string $code): void
    {
        // Deliberately not stored as an in-app notification.
        $this->deliver($user['email'], $user['phone'] ?? null, 'Your sign-in code', "Your SP-EPLMS sign-in code is $code. It expires in 5 minutes. Never share it.");
    }

    private function new_code(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function code_hash(string $code): string
    {
        return hash_hmac('sha256', $code, config_item('jwt_secret'));
    }

    private function mask_email(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return substr($name, 0, 1) . str_repeat('*', max(2, strlen($name) - 1)) . '@' . $domain;
    }

    private function profile(int $user_id): array
    {
        $p = $this->row(
            'SELECT u.id, u.username, u.email, u.phone, u.role,
                    e.id AS employee_id, e.employee_no, e.full_name, e.job_title, e.department, e.date_hired,
                    c.vacation, c.sick, c.special
               FROM users u
          LEFT JOIN employees e ON e.user_id = u.id
          LEFT JOIN leave_credits c ON c.employee_id = e.id
              WHERE u.id = ?',
            [$user_id]
        );
        foreach (['vacation', 'sick', 'special'] as $k) {
            $p[$k] = $p[$k] !== null ? (float) $p[$k] : null;
        }
        return $p;
    }
}
