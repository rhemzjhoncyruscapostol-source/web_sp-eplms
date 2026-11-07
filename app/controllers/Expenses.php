<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

/**
 * Budgets and expenses (Accounting).
 *
 * Expenses are append-only and chained: every entry stores the hash of the entry
 * before it, so changing or deleting an old row breaks the chain and shows up as
 * "Not verified". Corrections are made by adding a reversing (negative) entry.
 */
class Expenses extends Base_api
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private const CATEGORIES = [
        'Travel', 'Fuel and Oil', 'Office Supplies', 'Utilities', 'Communication',
        'Repairs and Maintenance', 'Meals and Events', 'Training', 'Equipment', 'Other',
    ];

    private const KEYWORDS = [
        'Travel'                 => ['travel', 'airfare', 'plane', 'ticket', 'hotel', 'accommodation', 'per diem', 'mileage', 'toll', 'ferry'],
        'Fuel and Oil'           => ['fuel', 'gasoline', 'diesel', 'petrol', 'lubricant'],
        'Office Supplies'        => ['paper', 'ink', 'toner', 'supplies', 'ballpen', 'folder', 'stationery', 'bond', 'envelope'],
        'Utilities'              => ['electric', 'water bill', 'utility', 'utilities', 'internet', 'wifi', 'power'],
        'Communication'          => ['load', 'phone', 'sms', 'telephone', 'postage', 'courier', 'mobile'],
        'Repairs and Maintenance' => ['repair', 'maintenance', 'aircon', 'servicing', 'plumbing', 'paint'],
        'Meals and Events'       => ['meal', 'snack', 'catering', 'food', 'refreshment', 'lunch', 'event', 'venue'],
        'Training'               => ['training', 'seminar', 'workshop', 'conference', 'tuition', 'orientation'],
        'Equipment'              => ['laptop', 'computer', 'printer', 'equipment', 'furniture', 'monitor', 'desk', 'chair'],
    ];

    /** SHA-256 over the entry's content and the previous hash. Shared with database/setup.php. */
    public static function chain_hash(array $r): string
    {
        return hash('sha256', implode('|', [
            $r['prev_hash'], $r['expense_date'], $r['description'], $r['category'], $r['office'],
            number_format((float) $r['amount'], 2, '.', ''), (int) $r['created_by'], $r['created_at'],
        ]));
    }

    // ------------------------------------------------------------ expenses

    /** GET /api/expenses?office=&category=&year=  (accounting) */
    public function index()
    {
        $this->api->require_method('GET');
        $this->authenticate(['accounting']);

        $office = $this->text($_GET['office'] ?? '', 120);
        $category = $this->text($_GET['category'] ?? '', 40);
        $year = (int) ($_GET['year'] ?? date('Y'));

        // Verify the whole chain first so each row's status is accurate whatever the filter.
        $prev = self::GENESIS;
        $broken = false;
        $out = [];
        foreach ($this->rows('SELECT * FROM expenses ORDER BY id') as $r) {
            if (!$broken && ($r['prev_hash'] !== $prev || $r['entry_hash'] !== self::chain_hash($r))) {
                $broken = true;
            }
            $prev = $r['entry_hash'];
            if ((int) substr($r['expense_date'], 0, 4) !== $year) continue;
            if ($office !== '' && $r['office'] !== $office) continue;
            if ($category !== '' && $r['category'] !== $category) continue;
            $out[] = [
                'id' => (int) $r['id'], 'expense_date' => $r['expense_date'], 'description' => $r['description'],
                'category' => $r['category'], 'office' => $r['office'], 'amount' => (float) $r['amount'],
                'flagged' => (int) $r['flagged'], 'flag_reason' => $r['flag_reason'],
                'verified' => !$broken, 'hash' => substr($r['entry_hash'], 0, 10),
            ];
        }
        usort($out, function ($a, $b) { return [$b['expense_date'], $b['id']] <=> [$a['expense_date'], $a['id']]; });

        $this->ok(['expenses' => $out, 'ledger_ok' => !$broken, 'categories' => self::CATEGORIES]);
    }

    /** GET /api/expenses/suggest?description=  (accounting) */
    public function suggest()
    {
        $this->api->require_method('GET');
        $this->authenticate(['accounting']);
        $this->ok(['category' => $this->guess_category($this->text($_GET['description'] ?? '', 255))]);
    }

    /** POST /api/expenses  { expense_date, description, office, amount, category? }  (accounting) */
    public function store()
    {
        $this->api->require_method('POST');
        $me = $this->authenticate(['accounting']);
        $in = $this->api->body();

        $errors = [];
        $date = $this->text($in['expense_date'] ?? '', 10);
        $desc = $this->text($in['description'] ?? '', 255);
        $office = $this->text($in['office'] ?? '', 120);
        $category = $this->text($in['category'] ?? '', 40);
        $amount = $in['amount'] ?? null;

        if (!$this->valid_date($date)) $errors['expense_date'] = 'Choose the date of the expense.';
        elseif ($date > date('Y-m-d')) $errors['expense_date'] = 'The date cannot be in the future.';
        if ($desc === '') $errors['description'] = 'Describe the expense.';
        if ($office === '') $errors['office'] = 'Choose the office.';
        if (!is_numeric($amount) || (float) $amount == 0 || abs((float) $amount) > 999999999) {
            $errors['amount'] = 'Enter an amount (use a negative number to reverse an earlier entry).';
        }
        if ($category === '') {
            $category = $this->guess_category($desc);
        } elseif (!in_array($category, self::CATEGORIES, true)) {
            $errors['category'] = 'Choose a category from the list.';
        }
        if ($errors) {
            $this->fail('Please fix the highlighted fields.', 422, $errors);
        }
        $amount = round((float) $amount, 2);

        [$flagged, $reason] = $this->check_anomalies($date, $desc, $category, $office, $amount);

        // Only one writer at a time, so two entries cannot claim the same previous hash.
        if (!(int) $this->scalar("SELECT GET_LOCK('expense_chain', 5)")) {
            $this->fail('The ledger is busy. Try again in a moment.', 503);
        }
        try {
            $prev = $this->scalar('SELECT entry_hash FROM expenses ORDER BY id DESC LIMIT 1') ?: self::GENESIS;
            $row = [
                'expense_date' => $date, 'description' => $desc, 'category' => $category, 'office' => $office,
                'amount' => $amount, 'flagged' => $flagged ? 1 : 0, 'flag_reason' => $reason,
                'created_by' => $me['id'], 'created_at' => date('Y-m-d H:i:s'), 'prev_hash' => $prev,
            ];
            $row['entry_hash'] = self::chain_hash($row);
            $id = $this->insert('expenses', $row);
        } finally {
            $this->scalar("SELECT RELEASE_LOCK('expense_chain')");
        }

        $this->audit('expense_added', sprintf('Expense #%d %s %.2f (%s)%s', $id, $office, $amount, $category, $flagged ? ' - flagged' : ''));
        $this->ok(['message' => $flagged ? 'Expense saved and flagged for review.' : 'Expense saved.', 'id' => $id, 'category' => $category, 'flagged' => $flagged, 'flag_reason' => $reason], 201);
    }

    // ------------------------------------------------------------ budgets

    /** GET /api/budgets?year=  (accounting) */
    public function budgets()
    {
        $this->api->require_method('GET');
        $this->authenticate(['accounting']);
        $year = (int) ($_GET['year'] ?? date('Y'));

        $rows = $this->rows(
            'SELECT b.office, b.amount,
                    COALESCE((SELECT SUM(x.amount) FROM expenses x WHERE x.office = b.office AND YEAR(x.expense_date) = b.fiscal_year), 0) AS spent
               FROM budgets b WHERE b.fiscal_year = ? ORDER BY b.office',
            [$year]
        );
        foreach ($rows as &$r) {
            $r['amount'] = (float) $r['amount'];
            $r['spent'] = (float) $r['spent'];
        }
        $this->ok(['year' => $year, 'budgets' => $rows]);
    }

    /** PUT /api/budgets  { office, fiscal_year, amount }  (accounting) */
    public function save_budget()
    {
        $this->api->require_method('PUT');
        $this->authenticate(['accounting']);
        $in = $this->api->body();

        $office = $this->text($in['office'] ?? '', 120);
        $year = (int) ($in['fiscal_year'] ?? 0);
        $amount = $in['amount'] ?? null;
        $errors = [];
        if ($office === '') $errors['office'] = 'Choose the office.';
        if ($year < 2000 || $year > 2100) $errors['fiscal_year'] = 'Enter a valid year.';
        if (!is_numeric($amount) || $amount < 0 || $amount > 9999999999) $errors['amount'] = 'Enter an amount of 0 or more.';
        if ($errors) {
            $this->fail('Please fix the highlighted fields.', 422, $errors);
        }
        $this->run(
            'INSERT INTO budgets (office, fiscal_year, amount) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE amount = VALUES(amount)',
            [$office, $year, round((float) $amount, 2)]
        );
        $this->audit('budget_saved', sprintf('Budget for %s %d set to %.2f', $office, $year, (float) $amount));
        $this->ok(['message' => 'Budget saved.']);
    }

    // ------------------------------------------------------------ rules (no external AI)

    private function guess_category(string $description): string
    {
        $text = strtolower($description);
        foreach (self::KEYWORDS as $category => $words) {
            foreach ($words as $w) {
                if (strpos($text, $w) !== false) {
                    return $category;
                }
            }
        }
        return 'Other';
    }

    /** Simple, explainable rules. Returns [flagged, reason]. */
    private function check_anomalies(string $date, string $desc, string $category, string $office, float $amount): array
    {
        $why = [];

        if ($this->row(
            'SELECT id FROM expenses WHERE office = ? AND expense_date = ? AND amount = ? AND LOWER(description) = LOWER(?) LIMIT 1',
            [$office, $date, $amount, $desc]
        )) {
            $why[] = 'Possible duplicate of an earlier entry';
        }

        $stats = $this->row('SELECT COUNT(*) AS c, AVG(ABS(amount)) AS a FROM expenses WHERE category = ?', [$category]);
        if ($stats && (int) $stats['c'] >= 3 && abs($amount) > 3 * (float) $stats['a']) {
            $why[] = 'More than 3x the usual ' . $category . ' expense';
        }

        if (abs($amount) >= 100000) {
            $why[] = 'Large single expense';
        }

        $year = (int) substr($date, 0, 4);
        $budget = $this->scalar('SELECT amount FROM budgets WHERE office = ? AND fiscal_year = ?', [$office, $year]);
        if ($budget !== null && $amount > 0) {
            $spent = (float) $this->scalar('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE office = ? AND YEAR(expense_date) = ?', [$office, $year]);
            if ($spent + $amount > (float) $budget) {
                $why[] = 'Takes ' . $office . ' over its ' . $year . ' budget';
            }
        }

        return [count($why) > 0, $why ? mb_substr(implode('; ', $why), 0, 255) : null];
    }
}
