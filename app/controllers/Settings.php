<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

class Settings extends Base_api
{
    /** GET /api/settings  (mis, admin) */
    public function index()
    {
        $this->api->require_method('GET');
        $this->authenticate(['mis', 'admin']);

        $offices = $this->rows(
            "SELECT COALESCE(department, 'Unassigned') AS name, COUNT(*) AS headcount
               FROM employees WHERE is_active = 1 GROUP BY name ORDER BY name"
        );
        foreach ($offices as &$o) {
            $o['headcount'] = (int) $o['headcount'];
        }

        $this->ok([
            'default_credits' => [
                'vacation' => (float) $this->setting('credits_vacation', 15),
                'sick'     => (float) $this->setting('credits_sick', 15),
                'special'  => (float) $this->setting('credits_special', 3),
            ],
            'offices' => $offices,
            'notifications' => [
                'outbox_log'    => true,
                'email_enabled' => getenv('MAIL_ENABLED') === 'true',
                'sms_enabled'   => (bool) getenv('SMS_WEBHOOK_URL'),
            ],
            'otp' => ['enabled' => getenv('OTP_ENABLED') !== 'false', 'roles' => ['hr', 'accounting', 'mis', 'admin']],
            'ledger' => $this->ledger_status(),
        ]);
    }

    /** PUT /api/settings  { default_credits: {vacation, sick, special} }  (mis) */
    public function update()
    {
        $this->api->require_method('PUT');
        $this->authenticate(['mis']);
        $in = $this->json_body();

        $c = is_array($in['default_credits'] ?? null) ? $in['default_credits'] : [];
        $clean = [];
        foreach (['vacation', 'sick', 'special'] as $k) {
            $v = $c[$k] ?? null;
            if (!is_numeric($v) || $v < 0 || $v > 365) {
                $this->fail('Credits must be numbers between 0 and 365.', 422, ['default_credits' => 'Credits must be numbers between 0 and 365.']);
            }
            $clean[$k] = round((float) $v, 1);
        }
        foreach ($clean as $k => $v) {
            $this->save_setting("credits_$k", $v);
        }
        $this->audit('settings_changed', sprintf('Default leave credits set to %s / %s / %s', $clean['vacation'], $clean['sick'], $clean['special']));
        $this->ok(['message' => 'Settings saved. They apply to employees added from now on.']);
    }

    private function ledger_status(): array
    {
        require_once __DIR__ . '/Expenses.php';
        $rows = $this->rows('SELECT * FROM expenses ORDER BY id');
        $broken = null;
        $prev = Expenses::GENESIS;
        foreach ($rows as $r) {
            if ($r['prev_hash'] !== $prev || $r['entry_hash'] !== Expenses::chain_hash($r)) {
                $broken = (int) $r['id'];
                break;
            }
            $prev = $r['entry_hash'];
        }
        return ['entries' => count($rows), 'ok' => $broken === null, 'broken_at' => $broken];
    }
}
