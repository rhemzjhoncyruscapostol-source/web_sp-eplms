<?php
/**
 * One-command setup for the HR system database.
 *
 *   php database/setup.php                       create .env, database, tables and the first MIS account
 *   php database/setup.php --demo                same, plus demo employees, accounts, leave, attendance, budgets and expenses
 *   php database/setup.php --mis-password=Secret123!   choose the MIS password instead of a random one
 *
 * Safe to run more than once: existing tables and accounts are left alone.
 */
if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

$root = dirname(__DIR__);
$envFile = $root . '/.env';

// ---------------------------------------------------------------- args
$demo = false;
$misPassword = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--demo') {
        $demo = true;
    } elseif (strpos($arg, '--mis-password=') === 0) {
        $misPassword = substr($arg, strlen('--mis-password='));
    }
}

// ---------------------------------------------------------------- .env
if (!file_exists($envFile)) {
    copy($root . '/.env.example', $envFile);
    echo "Created .env from .env.example\n";
}

function read_env($file)
{
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v);
    }
    return $out;
}

function write_env($file, $key, $value)
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $found = false;
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $line)) {
            $lines[$i] = $key . '=' . $value;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $lines[] = $key . '=' . $value;
    }
    file_put_contents($file, implode("\n", $lines) . "\n");
}

$env = read_env($envFile);
foreach (['APP_KEY', 'JWT_SECRET', 'REFRESH_TOKEN_KEY'] as $key) {
    if (empty($env[$key]) || strlen($env[$key]) < 32) {
        write_env($envFile, $key, bin2hex(random_bytes(32)));
        echo "Generated $key\n";
    }
}
$env = read_env($envFile);

$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$user = $env['DB_USERNAME'] ?? 'root';
$pass = $env['DB_PASSWORD'] ?? '';
$name = $env['DB_NAME'] ?? 'ormindoro_hr';

if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    exit("DB_NAME may only contain letters, numbers and underscores.\n");
}

// ---------------------------------------------------------------- database + tables
try {
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    exit("Could not connect to MySQL: " . $e->getMessage() . "\nCheck DB_HOST / DB_USERNAME / DB_PASSWORD in .env\n");
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$name`");

$schema = file_get_contents(__DIR__ . '/schema.sql');
foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
    $pdo->exec($statement);
}

function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $check->execute([$table, $column]);
    if (!(int) $check->fetchColumn()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

foreach ([
    'taxable_benefits' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
    'non_taxable_benefits' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
    'sss' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
    'philhealth' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
    'pagibig' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
    'withholding_tax' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
] as $column => $definition) {
    add_column_if_missing($pdo, 'salaries', $column, $definition);
}
foreach ([
    'time_in' => 'DATETIME NULL DEFAULT NULL',
    'time_out' => 'DATETIME NULL DEFAULT NULL',
] as $column => $definition) {
    add_column_if_missing($pdo, 'attendance', $column, $definition);
}
echo "Database '$name' and tables are ready.\n";

// ---------------------------------------------------------------- helpers
function make_user(PDO $pdo, $username, $email, $password, $role, $phone = null)
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$username, $email]);
    $existing = $stmt->fetch();
    if ($existing) {
        return (int) $existing['id'];
    }
    $stmt = $pdo->prepare('INSERT INTO users (username, email, phone, password, role) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$username, $email, $phone, password_hash($password, PASSWORD_BCRYPT), $role]);
    return (int) $pdo->lastInsertId();
}

function make_employee(PDO $pdo, $no, $name, $title, $office, $reportsTo, $userId = null, $hired = null)
{
    $stmt = $pdo->prepare(
        'INSERT INTO employees (user_id, employee_no, full_name, job_title, department, reports_to, date_hired) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $no, $name, $title, $office, $reportsTo, $hired]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT IGNORE INTO leave_credits (employee_id, vacation, sick, special) VALUES (?, 15, 15, 3)')->execute([$id]);
    return $id;
}

/** Must match Expenses::chain_hash() in app/controllers/Expenses.php */
function chain_hash(array $r)
{
    return hash('sha256', implode('|', [
        $r['prev_hash'], $r['expense_date'], $r['description'], $r['category'], $r['office'],
        number_format((float) $r['amount'], 2, '.', ''), (int) $r['created_by'], $r['created_at'],
    ]));
}

// ---------------------------------------------------------------- default settings
$st = $pdo->prepare('INSERT IGNORE INTO settings (skey, svalue) VALUES (?, ?)');
foreach (['credits_vacation' => '15', 'credits_sick' => '15', 'credits_special' => '3'] as $k => $v) {
    $st->execute([$k, $v]);
}

$defaultPayrollRules = json_encode([
    'sss' => ['rate' => 0.05, 'minimum_salary_credit' => 5000, 'maximum_salary_credit' => 35000, 'salary_credit_step' => 500],
    'philhealth' => ['rate' => 0.05, 'minimum_salary' => 10000, 'maximum_salary' => 100000, 'employee_share' => 0.5],
    'pagibig' => ['low_salary_ceiling' => 1500, 'low_salary_rate' => 0.01, 'high_salary_rate' => 0.02, 'maximum_fund_salary' => 10000],
    'withholding_tax' => [
        ['up_to' => 20833, 'base_tax' => 0, 'rate' => 0, 'excess_over' => 0],
        ['up_to' => 33332, 'base_tax' => 0, 'rate' => 0.15, 'excess_over' => 20833],
        ['up_to' => 66666, 'base_tax' => 1875, 'rate' => 0.20, 'excess_over' => 33333],
        ['up_to' => 166666, 'base_tax' => 8541.80, 'rate' => 0.25, 'excess_over' => 66667],
        ['up_to' => 666666, 'base_tax' => 33541.80, 'rate' => 0.30, 'excess_over' => 166667],
        ['up_to' => null, 'base_tax' => 183541.80, 'rate' => 0.35, 'excess_over' => 666667],
    ],
], JSON_UNESCAPED_SLASHES);
$schedule = $pdo->prepare('INSERT IGNORE INTO payroll_schedules (effective_from, rules) VALUES (?, ?)');
$schedule->execute(['2025-01-01', $defaultPayrollRules]);

// ---------------------------------------------------------------- first MIS account
$hasMis = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'mis'")->fetch()['c'] > 0;
if (!$hasMis) {
    $generated = $misPassword === null;
    if ($generated) {
        $misPassword = 'Mis-' . bin2hex(random_bytes(5));
    }
    if (strlen($misPassword) < 8) {
        exit("The MIS password must be at least 8 characters.\n");
    }
    make_user($pdo, 'mis', 'mis@ormindoro.local', $misPassword, 'mis');
    echo "\nFirst MIS account created (MIS is the only role that can register other users):\n";
    echo "  username: mis\n  password: $misPassword" . ($generated ? "   <- generated, save it now\n" : "\n");
    echo "  MIS signs in with a one-time code. Change mis@ormindoro.local to a real email in Accounts.\n";
} else {
    echo "An MIS account already exists - skipping.\n";
}

// ---------------------------------------------------------------- demo data
if ($demo) {
    $count = $pdo->query('SELECT COUNT(*) AS c FROM employees')->fetch()['c'];
    if ($count > 0) {
        echo "Employees already exist - skipping demo data.\n";
    } else {
        $demoPass = 'Demo@12345';
        $year = (int) date('Y');

        $uAdmin = make_user($pdo, 'admin', 'admin@ormindoro.local', $demoPass, 'admin');
        $uHr = make_user($pdo, 'hr', 'hr@ormindoro.local', $demoPass, 'hr');
        $uAcc = make_user($pdo, 'accounting', 'accounting@ormindoro.local', $demoPass, 'accounting');
        $uEmp = make_user($pdo, 'employee', 'employee@ormindoro.local', $demoPass, 'employee');
        $uMis = (int) $pdo->query("SELECT id FROM users WHERE role = 'mis' ORDER BY id LIMIT 1")->fetch()['id'];

        $VG = 'Office of the Vice Governor';
        $SEC = 'Office of the Secretary';
        $ADM = 'Administration Office';
        $LEG = 'Legal Office';
        $ACC = 'Accounting Section';
        $MIS = 'MIS Section';

        // Fictional people. Replace them from the MIS panel with the real roster.
        $vg = make_employee($pdo, 'SP-0001', 'Hon. Ricardo M. Villanueva', 'Vice Governor', $VG, null, null, '2022-06-30');
        $chief = make_employee($pdo, 'SP-0002', 'Elena P. Cruz', 'Chief of Staff', $VG, $vg, $uAdmin, '2022-07-04');
        $secretary = make_employee($pdo, 'SP-0003', 'Atty. Maria Luisa S. Panganiban', 'SP Secretary', $SEC, $vg, null, '2019-02-11');
        $hrHead = make_employee($pdo, 'SP-0004', 'Joel T. Magsino', 'Administrative Officer (HR)', $ADM, $chief, $uHr, '2018-03-19');
        $acct = make_employee($pdo, 'SP-0005', 'Cynthia R. Dimaculangan', 'Accountant III', $ACC, $chief, $uAcc, '2017-09-01');
        $mis = make_employee($pdo, 'SP-0006', 'Ramon B. Atienza', 'Information Systems Analyst', $MIS, $chief, $uMis, '2020-01-13');
        $kristine = make_employee($pdo, 'SP-0007', 'Kristine Anne L. Manalo', 'Legislative Staff Officer', $SEC, $secretary, $uEmp, '2021-08-02');
        $paolo = make_employee($pdo, 'SP-0008', 'Paolo D. Ilagan', 'Records Officer', $SEC, $secretary, null, '2021-11-15');
        $grace = make_employee($pdo, 'SP-0009', 'Grace A. Mendoza', 'Committee Clerk', $SEC, $secretary, null, '2023-02-20');
        make_employee($pdo, 'SP-0010', 'Dennis C. Roxas', 'Driver', $ADM, $chief, null, '2019-05-06');
        $legal = make_employee($pdo, 'SP-0011', 'Atty. Benedict J. Alvarez', 'Legal Officer', $LEG, $vg, null, '2020-10-12');
        make_employee($pdo, 'SP-0012', 'Rosalie V. Tolentino', 'Legal Assistant', $LEG, $legal, null, '2022-04-18');
        make_employee($pdo, 'SP-0013', 'Marvin E. Castillo', 'Administrative Aide', $ADM, $hrHead, null, '2023-09-04');
        make_employee($pdo, 'SP-0014', 'Liza M. Pangilinan', 'Bookkeeper', $ACC, $acct, null, '2021-01-25');
        make_employee($pdo, 'SP-0015', 'Noel S. Abad', 'IT Support Staff', $MIS, $mis, null, '2022-08-08');

        // Salaries for the demo employee
        $ins = $pdo->prepare('INSERT INTO salaries (employee_id, period, basic, allowances, deductions, net, remarks, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([sprintf('%d-07', $year), sprintf('%d-08', $year), sprintf('%d-09', $year)] as $p) {
            $ins->execute([$kristine, $p, 32000, 3500, 4150, 31350, 'Regular monthly pay', $uAcc]);
        }

        // Leave: one approved (already deducted from credits), one pending
        $pdo->exec("UPDATE leave_credits SET sick = 13 WHERE employee_id = $kristine");
        $lv = $pdo->prepare('INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, days, reason, status, reviewed_by, review_remarks, reviewed_at, credits_charged) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $lv->execute([$kristine, 'sick', sprintf('%d-09-08', $year), sprintf('%d-09-09', $year), 2, 'Flu with fever, doctor advised rest.', 'approved', $uHr, 'Get well soon.', sprintf('%d-09-08 09:15:00', $year), 1]);
        $lv->execute([$kristine, 'vacation', date('Y-m-d', strtotime('next monday +14 days')), date('Y-m-d', strtotime('next monday +18 days')), 5, 'Family trip.', 'pending', null, null, null, 0]);
        $lv->execute([$paolo, 'special', sprintf('%d-06-12', $year), sprintf('%d-06-12', $year), 1, 'Personal milestone.', 'approved', $uHr, null, sprintf('%d-06-10 10:00:00', $year), 1]);
        $pdo->exec("UPDATE leave_credits SET special = 2 WHERE employee_id = $paolo");

        // Attendance for the last few working days
        $att = $pdo->prepare('INSERT IGNORE INTO attendance (employee_id, work_date, status, recorded_by) VALUES (?, ?, ?, ?)');
        $ids = $pdo->query('SELECT id FROM employees WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
        for ($d = 1; $d <= 20; $d++) {
            $day = date('Y-m-d', strtotime("-$d day"));
            if ((int) date('N', strtotime($day)) >= 6) continue;
            foreach ($ids as $n => $eid) {
                $status = (($n * 7 + $d * 3) % 23 === 0) ? 'absent' : ((($n + $d) % 11 === 0) ? 'late' : 'present');
                $att->execute([$eid, $day, $status, $uHr]);
            }
        }

        // Budgets and a hash-chained expense ledger
        $bud = $pdo->prepare('INSERT INTO budgets (office, fiscal_year, amount) VALUES (?, ?, ?)');
        foreach ([$VG => 600000, $SEC => 450000, $ADM => 500000, $LEG => 300000, $ACC => 250000, $MIS => 350000] as $o => $a) {
            $bud->execute([$o, $year, $a]);
        }
        $samples = [
            [1, $SEC, 'Bond paper and toner', 'Office Supplies', 18500],
            [1, $ADM, 'Fuel for official vehicle', 'Fuel and Oil', 12400],
            [2, $MIS, 'Internet subscription', 'Utilities', 9800],
            [2, $LEG, 'Travel to Calapan for hearing', 'Travel', 15200],
            [3, $SEC, 'Snacks for session', 'Meals and Events', 7600],
            [3, $ADM, 'Aircon servicing', 'Repairs and Maintenance', 11300],
            [4, $MIS, 'Laptop for records unit', 'Equipment', 54900],
            [4, $VG, 'Per diem, regional conference', 'Travel', 28000],
            [5, $ACC, 'Seminar on government accounting', 'Training', 22500],
            [5, $SEC, 'Postage and courier', 'Communication', 3200],
            [6, $ADM, 'Fuel for official vehicle', 'Fuel and Oil', 13100],
            [6, $SEC, 'Bond paper and toner', 'Office Supplies', 19200],
            [7, $LEG, 'Travel to Manila, legal conference', 'Travel', 148000],
            [7, $MIS, 'Internet subscription', 'Utilities', 9800],
        ];
        $prev = str_repeat('0', 64);
        $ex = $pdo->prepare('INSERT INTO expenses (expense_date, description, category, office, amount, flagged, flag_reason, created_by, created_at, prev_hash, entry_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($samples as [$month, $office, $desc, $cat, $amt]) {
            $month = min($month, (int) date('n'));
            $row = [
                'expense_date' => sprintf('%d-%02d-%02d', $year, $month, 10),
                'description' => $desc, 'category' => $cat, 'office' => $office, 'amount' => $amt,
                'created_by' => $uAcc, 'created_at' => sprintf('%d-%02d-%02d 09:00:00', $year, $month, 10), 'prev_hash' => $prev,
            ];
            $row['entry_hash'] = chain_hash($row);
            $flag = $amt >= 100000 ? 1 : 0;
            $ex->execute([$row['expense_date'], $desc, $cat, $office, $amt, $flag, $flag ? 'Large single expense' : null, $uAcc, $row['created_at'], $prev, $row['entry_hash']]);
            $prev = $row['entry_hash'];
        }

        // A couple of notifications
        $nt = $pdo->prepare('INSERT INTO notifications (user_id, title, body) VALUES (?, ?, ?)');
        $nt->execute([$uEmp, 'Salary posted', 'Your salary for the latest month is now available.']);
        $nt->execute([$uEmp, 'Leave approved', 'Your sick leave was approved. Get well soon.']);
        $nt->execute([$uHr, 'New leave request', 'Kristine Anne L. Manalo filed vacation leave for 5 day(s).']);

        echo "\nDemo data added. Every demo account uses the password: $demoPass\n";
        echo "  admin, hr, accounting, employee   (and the MIS account above)\n";
        echo "  HR / Accounting / MIS / Admin also ask for a sign-in code. In development the code is shown on screen\n";
        echo "  and written to runtime/outbox.log.\n";
    }
}

echo "\nDone. Start the API with:  php lava serve\n";
