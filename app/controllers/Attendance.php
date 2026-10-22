<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

class Attendance extends Base_api
{
    private const STATUSES = ['present', 'late', 'absent', 'on_leave'];
    private $attendance;

    public function __construct()
    {
        parent::__construct();
        $this->attendance = $this->call->model('Attendance_model', 'attendance');
    }

    /** GET /api/attendance?date=YYYY-MM-DD  (hr, mis, admin) - every active employee with their status that day. */
    public function index()
    {
        $this->api->require_method('GET');
        $this->authenticate(['hr', 'mis', 'admin']);

        $date = $this->text($_GET['date'] ?? '', 10);
        if (!$this->valid_date($date)) {
            $date = date('Y-m-d');
        }

        $rows = $this->attendance->for_date($date);
        $this->ok(['date' => $date, 'records' => $rows]);
    }

    /** GET /api/me/attendance - the authenticated employee's recent punches. */
    public function mine()
    {
        $this->api->require_method('GET');
        $this->authenticate(['employee']);
        $employeeId = $this->my_employee_id();
        $today = date('Y-m-d');
        $history = $this->attendance->history_for_employee($employeeId, date('Y-m-d', strtotime('-30 days')), $today);
        $this->ok($history);
    }

    public function time_in()
    {
        $this->api->require_method('POST');
        $me = $this->authenticate(['employee']);
        $employeeId = $this->my_employee_id();
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        try {
            $this->attendance->record_time_in($employeeId, $today, $now, (int) $me['id']);
        } catch (DomainException $e) {
            $this->fail($e->getMessage(), 409);
        }
        $this->ok(['message' => 'Time in recorded.', 'time_in' => $now], 201);
    }

    public function time_out()
    {
        $this->api->require_method('POST');
        $employeeId = $this->my_employee_id_from_auth();
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        try {
            $this->attendance->record_time_out($employeeId, $today, $now);
        } catch (DomainException $e) {
            $this->fail($e->getMessage(), 409);
        }
        $this->ok(['message' => 'Time out recorded.', 'time_out' => $now]);
    }

    /** PUT /api/attendance  { date, entries: [{employee_id, status, remarks}] }  (hr, mis) */
    public function save()
    {
        $this->api->require_method('PUT');
        $me = $this->authenticate(['hr', 'mis']);
        $in = $this->json_body();

        $date = $this->text($in['date'] ?? '', 10);
        if (!$this->valid_date($date) || $date > date('Y-m-d')) {
            $this->fail('Choose today or an earlier date.', 422);
        }
        $entries = is_array($in['entries'] ?? null) ? $in['entries'] : [];
        if (!$entries || count($entries) > 1000) {
            $this->fail('Nothing to save.', 422);
        }

        $validatedEntries = [];
        foreach ($entries as $e) {
            $emp = (int) ($e['employee_id'] ?? 0);
            $status = $this->text($e['status'] ?? '', 10);
            if (!$emp || !in_array($status, self::STATUSES, true)) {
                continue;
            }
            $remarks = $this->text($e['remarks'] ?? '', 255);
            $timeIn = $this->attendance_time($date, $e['time_in'] ?? null);
            $timeOut = $this->attendance_time($date, $e['time_out'] ?? null);
            if ($timeIn !== null && $timeOut !== null && $timeOut < $timeIn) {
                $this->fail('Time out must be later than time in.', 422);
            }
            $validatedEntries[] = [
                'employee_id' => $emp,
                'status' => $status,
                'time_in' => $timeIn,
                'time_out' => $timeOut,
                'remarks' => $remarks,
            ];
        }
        $saved = $this->attendance->save_entries($date, $validatedEntries, (int) $me['id']);
        $this->audit('attendance_saved', "Saved $saved attendance record(s) for $date");
        $this->ok(['message' => "Attendance saved for $saved employee(s).", 'saved' => $saved]);
    }

    private function my_employee_id_from_auth(): int
    {
        $this->authenticate(['employee']);
        return $this->my_employee_id();
    }

    private function attendance_time(string $date, $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)) {
            $this->fail('Enter a valid attendance time.', 422);
        }
        $time = strlen($value) === 5 ? $value . ':00' : $value;
        $dateTime = $date . ' ' . $time;
        if ($dateTime > date('Y-m-d H:i:s')) {
            $this->fail('Attendance times cannot be in the future.', 422);
        }
        return $dateTime;
    }
}
