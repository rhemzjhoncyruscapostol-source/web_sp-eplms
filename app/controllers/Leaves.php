<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

class Leaves extends Base_api
{
    private const TYPES = ['vacation', 'sick', 'special', 'maternity', 'paternity', 'unpaid'];
    /** Types that use up leave credits. */
    private const CREDIT_TYPES = ['vacation', 'sick', 'special'];
    private const LABELS = [
        'vacation' => 'Vacation', 'sick' => 'Sick', 'special' => 'Special Privilege',
        'maternity' => 'Maternity', 'paternity' => 'Paternity', 'unpaid' => 'Unpaid',
    ];

    // ------------------------------------------------------------ employee side

    /** GET /api/me/leaves  (employee) - requests plus current credits. */
    public function mine()
    {
        $this->api->require_method('GET');
        $this->authenticate(['employee']);
        $emp = $this->my_employee_id();

        $rows = $this->rows(
            'SELECT l.*, COALESCE(r.full_name, ru.username) AS reviewed_by_name
               FROM leave_requests l
          LEFT JOIN users ru ON ru.id = l.reviewed_by
          LEFT JOIN employees r ON r.user_id = ru.id
              WHERE l.employee_id = ?
           ORDER BY l.created_at DESC, l.id DESC',
            [$emp]
        );
        $this->ok(['leaves' => array_map([$this, 'shape'], $rows), 'credits' => $this->credits($emp)]);
    }

    /**
     * POST /api/me/leaves  (employee)
     * multipart/form-data or JSON: leave_type, start_date, end_date, reason, attachment (optional file)
     */
    public function create()
    {
        $this->api->require_method('POST');
        $me = $this->authenticate(['employee']);
        $emp = $this->my_employee_id();
        $in = !empty($_POST) ? $_POST : $this->json_body();

        $errors = [];
        $type = $this->text($in['leave_type'] ?? '', 20);
        $start = $this->text($in['start_date'] ?? '', 10);
        $end = $this->text($in['end_date'] ?? '', 10);
        $reason = $this->text($in['reason'] ?? '', 500);

        if (!in_array($type, self::TYPES, true)) $errors['leave_type'] = 'Choose a type of leave.';
        if (!$this->valid_date($start)) $errors['start_date'] = 'Choose a start date.';
        if (!$this->valid_date($end)) $errors['end_date'] = 'Choose an end date.';
        if ($reason === '') $errors['reason'] = 'Tell HR the reason for your leave.';

        $days = 0;
        if (!$errors) {
            if ($end < $start) {
                $errors['end_date'] = 'The end date cannot be before the start date.';
            } elseif ((strtotime($end) - strtotime($start)) > 366 * 86400) {
                $errors['end_date'] = 'A single request cannot be longer than a year.';
            } else {
                // Sick leave may be filed up to 14 days after the fact; everything else in advance.
                $earliest = date('Y-m-d', strtotime($type === 'sick' ? '-14 days' : 'today'));
                if ($start < $earliest) {
                    $errors['start_date'] = $type === 'sick'
                        ? 'Sick leave can only be filed for the last 14 days or later.'
                        : 'Choose today or a later date.';
                }
                $days = $this->weekdays($start, $end);
                if ($days === 0) {
                    $errors['end_date'] = 'Those dates only cover a weekend, so no leave is needed.';
                }
            }
        }

        // Enough credits left (after counting requests that are still waiting)?
        if (!$errors && in_array($type, self::CREDIT_TYPES, true)) {
            $have = (float) $this->scalar("SELECT $type FROM leave_credits WHERE employee_id = ?", [$emp]);
            $waiting = (float) $this->scalar(
                "SELECT COALESCE(SUM(days), 0) FROM leave_requests
                  WHERE employee_id = ? AND leave_type = ? AND status = 'pending' AND credits_charged = 0",
                [$emp, $type]
            );
            $left = $have - $waiting;
            if ($days > $left) {
                $errors['leave_type'] = sprintf(
                    'Not enough %s leave credits: you have %s day(s) available and asked for %d.',
                    strtolower(self::LABELS[$type]), rtrim(rtrim(number_format($left, 1), '0'), '.') ?: '0', $days
                );
            }
        }

        $file = $this->read_attachment($errors);
        if ($errors) {
            $this->fail('Please fix the highlighted fields.', 422, $errors);
        }

        $overlap = $this->scalar(
            "SELECT COUNT(*) FROM leave_requests
              WHERE employee_id = ? AND status IN ('pending','approved')
                AND start_date <= ? AND end_date >= ?",
            [$emp, $end, $start]
        );
        if ((int) $overlap > 0) {
            $this->fail('You already have a pending or approved leave that overlaps these dates.', 409);
        }

        $id = $this->insert('leave_requests', [
            'employee_id' => $emp,
            'leave_type'  => $type,
            'start_date'  => $start,
            'end_date'    => $end,
            'days'        => $days,
            'reason'      => $reason,
            'attachment'  => $file,
            'status'      => 'pending',
        ]);

        $this->notify_role(['hr'], 'New leave request', sprintf(
            '%s filed %s leave for %d day(s): %s to %s.', $me['full_name'] ?: $me['username'], strtolower(self::LABELS[$type]), $days, $start, $end
        ));
        $this->audit('leave_filed', "Leave request #$id ($type, $start to $end)");
        $this->ok(['message' => 'Leave request sent to HR.', 'id' => $id], 201);
    }

    /** DELETE /api/me/leaves/{id}  (employee) - withdraw a request that is still pending. */
    public function cancel($id)
    {
        $this->api->require_method('DELETE');
        $this->authenticate(['employee']);
        $emp = $this->my_employee_id();

        $changed = $this->run(
            "UPDATE leave_requests SET status = 'cancelled' WHERE id = ? AND employee_id = ? AND status = 'pending'",
            [(int) $id, $emp]
        );
        if (!$changed) {
            $this->fail('Only your own pending requests can be cancelled.', 409);
        }
        $this->audit('leave_cancelled', 'Leave request #' . (int) $id . ' cancelled by the employee');
        $this->ok(['message' => 'Leave request cancelled.']);
    }

    // ------------------------------------------------------------ HR / MIS / admin side

    /** GET /api/leaves?status=pending  (admin, hr, mis) */
    public function index()
    {
        $this->api->require_method('GET');
        $this->authenticate(['admin', 'hr', 'mis']);

        $status = $_GET['status'] ?? '';
        $params = [];
        $where = '';
        if (in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $where = 'WHERE l.status = ?';
            $params[] = $status;
        }

        $rows = $this->rows(
            "SELECT l.*, e.full_name, e.job_title, e.department, COALESCE(r.full_name, ru.username) AS reviewed_by_name
               FROM leave_requests l
               JOIN employees e ON e.id = l.employee_id
          LEFT JOIN users ru ON ru.id = l.reviewed_by
          LEFT JOIN employees r ON r.user_id = ru.id
               $where
           ORDER BY (l.status = 'pending') DESC, l.start_date ASC, l.id DESC",
            $params
        );
        $this->ok(['leaves' => array_map([$this, 'shape'], $rows)]);
    }

    /** PATCH /api/leaves/{id}/review  { status: approved|rejected, remarks }  (hr, mis) */
    public function review($id)
    {
        $this->api->require_method('PATCH');
        $me = $this->authenticate(['hr', 'mis']);
        $id = (int) $id;
        $in = $this->json_body();

        $status = $this->text($in['status'] ?? '', 10);
        $remarks = $this->text($in['remarks'] ?? '', 255);
        if (!in_array($status, ['approved', 'rejected'], true)) {
            $this->fail('Choose approve or reject.', 422);
        }
        if ($status === 'rejected' && $remarks === '') {
            $this->fail('Add a short reason so the employee knows why it was rejected.', 422, ['remarks' => 'A reason is required.']);
        }

        $leave = $this->row('SELECT * FROM leave_requests WHERE id = ?', [$id]);
        if (!$leave || $leave['status'] !== 'pending') {
            $this->fail('This request was already reviewed or no longer exists.', 409);
        }

        // Approving a credit-bearing leave takes the days off the employee's balance.
        $charged = 0;
        if ($status === 'approved' && in_array($leave['leave_type'], self::CREDIT_TYPES, true)) {
            $col = $leave['leave_type'];
            $took = $this->run(
                "UPDATE leave_credits SET $col = $col - ?, updated_at = ? WHERE employee_id = ? AND $col >= ?",
                [(int) $leave['days'], date('Y-m-d H:i:s'), (int) $leave['employee_id'], (int) $leave['days']]
            );
            if (!$took) {
                $this->fail('This employee does not have enough credits left for this leave. Adjust their credits in Employee Profiles first.', 409);
            }
            $charged = 1;
        }

        $changed = $this->run(
            "UPDATE leave_requests
                SET status = ?, review_remarks = ?, reviewed_by = ?, reviewed_at = ?, credits_charged = ?
              WHERE id = ? AND status = 'pending'",
            [$status, $remarks !== '' ? $remarks : null, $me['id'], date('Y-m-d H:i:s'), $charged, $id]
        );
        if (!$changed) {
            if ($charged) { // someone else decided first: give the days back
                $col = $leave['leave_type'];
                $this->run("UPDATE leave_credits SET $col = $col + ? WHERE employee_id = ?", [(int) $leave['days'], (int) $leave['employee_id']]);
            }
            $this->fail('This request was already reviewed.', 409);
        }

        $label = self::LABELS[$leave['leave_type']];
        $this->notify_employee((int) $leave['employee_id'], "Leave $status", sprintf(
            'Your %s leave (%s to %s) was %s.%s', strtolower($label), $leave['start_date'], $leave['end_date'], $status, $remarks !== '' ? " Remarks: $remarks" : ''
        ));
        if ($charged) {
            $this->notify_employee((int) $leave['employee_id'], 'Credits updated', sprintf('%d day(s) were deducted from your %s leave credits.', (int) $leave['days'], strtolower($label)));
        }
        $this->audit('leave_' . $status, "Leave request #$id $status");
        $this->ok(['message' => $status === 'approved' ? 'Leave approved.' : 'Leave rejected.']);
    }

    /** GET /api/leaves/{id}/attachment - the owner, HR, MIS or Admin only. */
    public function attachment($id)
    {
        $this->api->require_method('GET');
        $me = $this->authenticate(['employee', 'hr', 'mis', 'admin']);

        $leave = $this->row('SELECT employee_id, attachment FROM leave_requests WHERE id = ?', [(int) $id]);
        if (!$leave || !$leave['attachment']) {
            $this->fail('There is no attachment for this request.', 404);
        }
        if ($me['role'] === 'employee' && (int) $leave['employee_id'] !== (int) $me['employee_id']) {
            $this->fail('You do not have access to this file.', 403);
        }

        $path = $this->upload_dir() . basename($leave['attachment']);
        if (!is_file($path)) {
            $this->fail('The file is missing from the server.', 404);
        }
        $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="attachment.' . $ext . '"');
        readfile($path);
        exit;
    }

    // ------------------------------------------------------------ internals

    private function credits(int $employee_id): array
    {
        $c = $this->row('SELECT vacation, sick, special FROM leave_credits WHERE employee_id = ?', [$employee_id])
            ?: ['vacation' => 0, 'sick' => 0, 'special' => 0];
        return ['vacation' => (float) $c['vacation'], 'sick' => (float) $c['sick'], 'special' => (float) $c['special']];
    }

    private function upload_dir(): string
    {
        return ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'leave' . DIRECTORY_SEPARATOR;
    }

    /** Validate and store the optional attachment. Returns the stored file name or null. */
    private function read_attachment(array &$errors)
    {
        if (empty($_FILES['attachment']) || $_FILES['attachment']['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $f = $_FILES['attachment'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors['attachment'] = 'The file could not be uploaded. Try a smaller file.';
            return null;
        }
        if ($f['size'] > 2 * 1024 * 1024) {
            $errors['attachment'] = 'The file is larger than 2 MB.';
            return null;
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png']];
        if (!isset($allowed[$ext])) {
            $errors['attachment'] = 'Attach a PDF, JPG or PNG file.';
            return null;
        }
        if (function_exists('mime_content_type') && !in_array(mime_content_type($f['tmp_name']), $allowed[$ext], true)) {
            $errors['attachment'] = 'That file does not look like a real ' . strtoupper($ext) . '.';
            return null;
        }
        if ($errors) { // do not keep a file for a request that will be rejected
            return null;
        }
        $dir = $this->upload_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            $errors['attachment'] = 'The server cannot store attachments right now.';
            return null;
        }
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . $name)) {
            $errors['attachment'] = 'The file could not be saved.';
            return null;
        }
        return $name;
    }

    private function shape(array $r): array
    {
        foreach (['id', 'employee_id', 'days', 'reviewed_by'] as $k) {
            if (array_key_exists($k, $r)) {
                $r[$k] = $r[$k] !== null ? (int) $r[$k] : null;
            }
        }
        $r['has_attachment'] = !empty($r['attachment']);
        unset($r['attachment']);
        return $r;
    }
}
