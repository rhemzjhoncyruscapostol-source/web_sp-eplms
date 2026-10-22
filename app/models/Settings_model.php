<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Api_model.php';

class Settings_model extends Api_model
{
    public function office_headcount(): array
    {
        return $this->rows(
            "SELECT COALESCE(department, 'Unassigned') AS name, COUNT(*) AS headcount
               FROM employees WHERE is_active = 1 GROUP BY name ORDER BY name"
        );
    }

    public function load_setting(string $key, $default = null)
    {
        $value = $this->scalar('SELECT svalue FROM settings WHERE skey = ?', [$key]);
        return $value !== null ? $value : $default;
    }

    public function save_setting(string $key, $value): int
    {
        return $this->run(
            'INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
            [$key, (string) $value]
        );
    }

    public function ledger_status(): array
    {
        $rows = $this->rows('SELECT * FROM expenses ORDER BY id');
        $broken = null;
        $prev = '0000000000000000000000000000000000000000000000000000000000000000';
        foreach ($rows as $r) {
            if ($r['prev_hash'] !== $prev || $r['entry_hash'] !== hash('sha256', implode('|', [
                $r['prev_hash'], $r['expense_date'], $r['description'], $r['category'], $r['office'],
                number_format((float) $r['amount'], 2, '.', ''), (int) $r['created_by'], $r['created_at'],
            ]))) {
                $broken = (int) $r['id'];
                break;
            }
            $prev = $r['entry_hash'];
        }
        return ['entries' => count($rows), 'ok' => $broken === null, 'broken_at' => $broken];
    }
}
