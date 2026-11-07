<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

class Attendance extends Base_api
{
    private const STATUSES = ['present', 'late', 'absent', 'on_leave', 'travel'];
    private $attendance;

    public function __construct()
    {
        parent::__construct();
        $this->attendance = $this->call->model('Attendance_model', 'attendance');
    }

    /** GET /api/attendance?date=YYYY-MM-DD   (hr, mis, admin) - every active employee with their status that day. */
    public function index()
    {
        $this->api->require_method('GET');
        $this->authenticate(['hr', 'mis', 'admin', 'attendance_operator']);

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

    public function qr()
    {
        $this->api->require_method('GET');
        $this->authenticate(['employee']);
        $payload = $this->attendance->qr_payload_for_employee($this->my_employee_id());
        if ($payload === null) {
            $this->fail('Your attendance QR code is not available. Please ask HR or MIS for help.', 404);
        }
        $this->ok(['qr_payload' => $payload]);
    }

    public function punch()
    {
        $this->api->require_method('POST');
        $me = $this->authenticate(['employee']);
        $employeeId = $this->my_employee_id();
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        try {
            $slot = $this->attendance->record_next_punch($employeeId, $today, $now, (int) $me['id']);
        } catch (DomainException $e) {
            $this->fail($e->getMessage(), 409);
        }
        $this->audit('attendance_punch_recorded', "Recorded $slot for employee #$employeeId");
        $this->ok(['message' => $this->punch_label($slot) . ' recorded.', 'slot' => $slot, 'time' => $now], 201);
    }

    public function time_in()
    {
        $this->api->require_method('POST');
        $me = $this->authenticate(['employee']);
        $employeeId = $this->my_employee_id();
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        try {
            $slot = $this->attendance->record_next_punch(
                $employeeId, $today, $now, (int) $me['id'], ['time_in', 'time_in_2']
            );
        } catch (DomainException $e) {
            $this->fail($e->getMessage(), 409);
        }
        $this->ok(['message' => $this->punch_label($slot) . ' recorded.', 'slot' => $slot, 'time' => $now], 201);
    }

    public function time_out()
    {
        $this->api->require_method('POST');
        $me = $this->authenticate(['employee']);
        $employeeId = $this->my_employee_id();
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        try {
            $slot = $this->attendance->record_next_punch(
                $employeeId, $today, $now, (int) $me['id'], ['time_out', 'time_out_2']
            );
        } catch (DomainException $e) {
            $this->fail($e->getMessage(), 409);
        }
        $this->ok(['message' => $this->punch_label($slot) . ' recorded.', 'slot' => $slot, 'time' => $now]);
    }

    public function scan()
    {
        $this->api->require_method('POST');
        $me = $this->authenticate(['hr', 'mis', 'attendance_operator']);
        $in = $this->api->body();
        $payload = $this->text($in['qr_payload'] ?? '', 80);
        $employee = null;
        if (preg_match('/^(?:SP-EPLMS:)?([a-f0-9]{64})$/i', $payload, $matches)) {
            $employee = $this->attendance->employee_for_qr_token(strtolower($matches[1]));
        } elseif (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,29}$/', $payload)) {
            $employee = $this->attendance->employee_for_employee_no(strtoupper($payload));
        } else {
            $this->fail('Enter a valid employee number or scan an employee QR code.', 422);
        }
        if (!$employee) {
            $this->fail('This employee number or QR code is invalid, or the employee account is inactive.', 422);
        }
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        try {
            $slot = $this->attendance->record_next_punch((int) $employee['employee_id'], $today, $now, (int) $me['id']);
        } catch (DomainException $e) {
            $this->fail($e->getMessage(), 409);
        }
        $this->audit('attendance_qr_scanned', 'Recorded ' . $slot . ' for employee #' . $employee['employee_id']);
        $this->ok([
            'message' => $employee['full_name'] . ': ' . $this->punch_label($slot) . ' recorded.',
            'employee' => [
                'employee_id' => (int) $employee['employee_id'],
                'full_name' => $employee['full_name'],
                'employee_no' => $employee['employee_no'],
            ],
            'slot' => $slot,
            'time' => $now,
        ], 201);
    }

    /** PUT /api/attendance  { date, entries: [{employee_id, status, remarks}] }  (hr, mis) */
    public function save()
    {
        $this->api->require_method('PUT');
        $me = $this->authenticate(['hr', 'mis']);
        $in = $this->api->body();

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
            $times = [];
            foreach (['time_in', 'time_out', 'time_in_2', 'time_out_2'] as $slot) {
                $times[$slot] = $this->attendance_time($date, $e[$slot] ?? null);
            }
            $previous = null;
            $gap = false;
            foreach ($times as $slot => $time) {
                if ($time === null) {
                    $gap = true;
                } elseif ($gap) {
                    $this->fail('Attendance punches must be recorded in order.', 422);
                }
                if ($time !== null && $previous !== null && $time <= $previous) {
                    $this->fail('Each attendance punch must be later than the previous punch.', 422);
                }
                $previous = $time ?? $previous;
            }
            $validatedEntries[] = [
                'employee_id' => $emp,
                'status' => $status,
                'time_in' => $times['time_in'],
                'time_out' => $times['time_out'],
                'time_in_2' => $times['time_in_2'],
                'time_out_2' => $times['time_out_2'],
                'remarks' => $remarks,
            ];
        }
        $saved = $this->attendance->save_entries($date, $validatedEntries, (int) $me['id']);
        $this->audit('attendance_saved', "Saved $saved attendance record(s) for $date");
        $this->ok(['message' => "Attendance saved for $saved employee(s).", 'saved' => $saved]);
    }

    private function punch_label(string $slot): string
    {
        $labels = [
            'time_in' => 'First time in',
            'time_out' => 'First time out',
            'time_in_2' => 'Second time in',
            'time_out_2' => 'Second time out',
        ];
        return $labels[$slot] ?? 'Attendance punch';
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
