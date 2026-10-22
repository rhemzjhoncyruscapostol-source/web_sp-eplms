<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Shared behaviour for every API controller.
 *
 * Active roles (stored in users.role): admin, hr, employee, mis, accounting.
 * Legacy viewer roles are retired and cannot authenticate.
 */
class Base_api extends Controller
{
    /** @var array|null The authenticated user (set by authenticate()). */
    protected $me = null;

    public function __construct()
    {
        parent::__construct();
        date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Manila');
        $this->call->database();
        $this->data = $this->call->model('Api_model', 'data');
        $this->call->library('api'); // also sends the CORS headers
    }

    // ------------------------------------------------------------ request / response

    /** JSON request body as an array (raw - validate it before use). */
    protected function json_body(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }

    protected function ok($data = [], int $code = 200)
    {
        $this->call->view('api/json', ['payload' => $data, 'status' => $code]);
    }

    protected function fail(string $message, int $code = 400, array $errors = [])
    {
        $body = ['error' => $message, 'status' => $code];
        if ($errors) {
            $body['errors'] = $errors;
        }
        $this->call->view('api/json', ['payload' => $body, 'status' => $code]);
    }

    // ------------------------------------------------------------ database helpers

    protected function rows(string $sql, array $params = []): array
    {
        return $this->data->rows($sql, $params);
    }

    protected function row(string $sql, array $params = [])
    {
        return $this->data->row($sql, $params);
    }

    /** Run INSERT/UPDATE/DELETE. Returns the number of affected rows. */
    protected function run(string $sql, array $params = []): int
    {
        return $this->data->run($sql, $params);
    }

    protected function scalar(string $sql, array $params = [])
    {
        return $this->data->scalar($sql, $params);
    }

    /** INSERT using the query builder so we can read back the new id. */
    protected function insert(string $table, array $fields): int
    {
        return $this->data->insert($table, $fields);
    }

    // ------------------------------------------------------------ auth

    /**
     * Require a valid access token. When $roles is not empty the user must have
     * one of those roles. Sets and returns $this->me.
     */
    protected function authenticate(array $roles = []): array
    {
        $payload = Auth_middleware::payload();
        if ($payload === null) {
            $payload = $this->api->require_jwt();
        }

        // A refresh token is also a signed JWT; it must never work as an access token.
        if (($payload['type'] ?? '') === 'refresh') {
            $this->fail('Unauthorized', 401);
        }

        // Always read the role from the database so role changes and
        // deactivation take effect immediately, not when the token expires.
        $user = $this->row(
            'SELECT u.id, u.username, u.email, u.role, u.is_active,
                    e.id AS employee_id, e.full_name, e.job_title
               FROM users u
          LEFT JOIN employees e ON e.user_id = u.id
              WHERE u.id = ? LIMIT 1',
            [(int) $payload['sub']]
        );

        if (!$user || !(int) $user['is_active']) {
            $this->fail('Your account is not active.', 401);
        }

        if ($user['role'] === 'viewer') {
            $this->fail('Viewer accounts are retired. Public information is available without signing in.', 403);
        }

        if ($roles && !in_array($user['role'], $roles, true)) {
            $this->fail('You do not have access to this resource.', 403);
        }

        $user['id'] = (int) $user['id'];
        $user['employee_id'] = $user['employee_id'] !== null ? (int) $user['employee_id'] : null;
        $user['is_active'] = (int) $user['is_active'];
        $this->me = $user;
        return $user;
    }

    /** Employee profile id of the logged-in user, or a 404 when none is linked. */
    protected function my_employee_id(): int
    {
        if (empty($this->me['employee_id'])) {
            $this->fail('Your account is not linked to an employee record yet. Please ask MIS to link it.', 404);
        }
        return (int) $this->me['employee_id'];
    }

    // ------------------------------------------------------------ validation helpers

    protected function valid_date(string $value): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $value);
        return $d && $d->format('Y-m-d') === $value;
    }

    /** Clean a string input: trims and caps the length. Returns '' for non-strings. */
    protected function text($value, int $max = 255): string
    {
        if (!is_string($value)) {
            return '';
        }
        return mb_substr(trim($value), 0, $max);
    }

    // ------------------------------------------------------------ audit, notifications, settings

    protected function ip(): string
    {
        return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    }

    /** Write one line to the audit log. $actor defaults to the logged-in user. */
    protected function audit(string $action, string $detail = '', ?array $actor = null): void
    {
        $actor = $actor ?? $this->me;
        $this->insert('audit_log', [
            'user_id'  => $actor['id'] ?? null,
            'username' => $actor['username'] ?? null,
            'role'     => $actor['role'] ?? null,
            'action'   => $action,
            'detail'   => mb_substr($detail, 0, 500),
            'ip'       => $this->ip(),
        ]);
    }

    /** In-app notification, plus email/SMS delivery when those are configured. */
    protected function notify(int $user_id, string $title, string $body): void
    {
        $this->insert('notifications', [
            'user_id' => $user_id,
            'title'   => mb_substr($title, 0, 150),
            'body'    => mb_substr($body, 0, 500),
        ]);
        $u = $this->row('SELECT email, phone FROM users WHERE id = ?', [$user_id]);
        if ($u) {
            $this->deliver($u['email'], $u['phone'], $title, $body);
        }
    }

    protected function notify_role(array $roles, string $title, string $body): void
    {
        $marks = implode(',', array_fill(0, count($roles), '?'));
        foreach ($this->rows("SELECT id FROM users WHERE is_active = 1 AND role IN ($marks)", $roles) as $u) {
            $this->notify((int) $u['id'], $title, $body);
        }
    }

    protected function notify_employee(int $employee_id, string $title, string $body): void
    {
        $uid = $this->scalar('SELECT user_id FROM employees WHERE id = ?', [$employee_id]);
        if ($uid) {
            $this->notify((int) $uid, $title, $body);
        }
    }

    /**
     * Always appends to runtime/outbox.log (handy in development). Real delivery
     * is opt-in: MAIL_ENABLED=true uses PHP mail(); SMS_WEBHOOK_URL posts JSON to your SMS gateway.
     */
    protected function deliver($email, $phone, string $title, string $body): void
    {
        $dir = ROOT_DIR . 'runtime';
        if (is_dir($dir) || @mkdir($dir, 0775, true)) {
            @file_put_contents(
                $dir . '/outbox.log',
                sprintf("[%s] email=%s phone=%s | %s | %s\n", date('c'), $email ?: '-', $phone ?: '-', $title, $body),
                FILE_APPEND | LOCK_EX
            );
        }
        if (getenv('MAIL_ENABLED') === 'true' && $email) {
            @mail($email, $title, $body, 'From: ' . (getenv('MAIL_FROM') ?: 'no-reply@localhost'));
        }
        $hook = getenv('SMS_WEBHOOK_URL');
        if ($hook && $phone) {
            $ctx = stream_context_create(['http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => json_encode(['to' => $phone, 'message' => $title . ': ' . $body]),
                'timeout' => 4,
            ]]);
            @file_get_contents($hook, false, $ctx);
        }
    }

    protected function setting(string $key, $default = null)
    {
        $v = $this->scalar('SELECT svalue FROM settings WHERE skey = ?', [$key]);
        return $v !== null ? $v : $default;
    }

    protected function save_setting(string $key, $value): void
    {
        $this->run(
            'INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
            [$key, (string) $value]
        );
    }

    /** Give a new employee the default leave credits from System Settings. */
    protected function give_default_credits(int $employee_id): void
    {
        $this->run(
            'INSERT IGNORE INTO leave_credits (employee_id, vacation, sick, special) VALUES (?, ?, ?, ?)',
            [
                $employee_id,
                (float) $this->setting('credits_vacation', 15),
                (float) $this->setting('credits_sick', 15),
                (float) $this->setting('credits_special', 3),
            ]
        );
    }

    /** Monday-to-Friday days between two dates, inclusive. */
    protected function weekdays(string $start, string $end): int
    {
        $d = new DateTime($start);
        $e = new DateTime($end);
        $n = 0;
        while ($d <= $e) {
            if ((int) $d->format('N') < 6) {
                $n++;
            }
            $d->modify('+1 day');
        }
        return $n;
    }
}
