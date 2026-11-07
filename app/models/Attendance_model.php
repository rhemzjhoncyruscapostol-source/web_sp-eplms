<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Api_model.php';

class Attendance_model extends Api_model
{
    public function for_date(string $date): array
    {
        $rows = $this->rows(
            "SELECT e.id AS employee_id, e.employee_no, e.full_name, e.job_title, e.department,
                    a.status, a.time_in, a.time_out, a.time_in_2, a.time_out_2, a.remarks,
                    EXISTS (SELECT 1 FROM leave_requests l WHERE l.employee_id = e.id AND l.status = 'approved'
                             AND l.leave_type <> 'travel'
                             AND ? BETWEEN l.start_date AND l.end_date) AS approved_leave
               FROM employees e
          LEFT JOIN attendance a ON a.employee_id = e.id AND a.work_date = ?
              WHERE e.is_active = 1
           ORDER BY e.department, e.full_name",
            [$date, $date]
        );
        foreach ($rows as &$row) {
            $row['employee_id'] = (int) $row['employee_id'];
            $row['approved_leave'] = (int) $row['approved_leave'];
        }
        unset($row);
        return $rows;
    }

    public function history_for_employee(int $employeeId, string $fromDate, string $today): array
    {
        return [
            'today' => $this->row(
                'SELECT status, time_in, time_out, time_in_2, time_out_2 FROM attendance WHERE employee_id = ? AND work_date = ?',
                [$employeeId, $today]
            ),
            'records' => $this->rows(
                'SELECT work_date, status, time_in, time_out, time_in_2, time_out_2, remarks FROM attendance
                  WHERE employee_id = ? AND work_date >= ? ORDER BY work_date DESC',
                [$employeeId, $fromDate]
            ),
            'qr_payload' => $this->qr_payload_for_employee($employeeId),
        ];
    }

    public function qr_payload_for_employee(int $employeeId): ?string
    {
        return $this->scalar(
            "SELECT CONCAT('SP-EPLMS:', qr_token) FROM employees WHERE id = ? AND qr_token IS NOT NULL",
            [$employeeId]
        );
    }

    public function employee_for_qr_token(string $token): ?array
    {
        return $this->row(
            "SELECT e.id AS employee_id, e.full_name, e.employee_no
               FROM employees e
               JOIN users u ON u.id = e.user_id
              WHERE e.qr_token = ? AND e.is_active = 1 AND u.is_active = 1 AND u.role = 'employee'",
            [$token]
        );
    }

    public function employee_for_employee_no(string $employeeNo): ?array
    {
        return $this->row(
            "SELECT e.id AS employee_id, e.full_name, e.employee_no
               FROM employees e
               JOIN users u ON u.id = e.user_id
              WHERE e.employee_no = ? AND e.is_active = 1 AND u.is_active = 1 AND u.role = 'employee'",
            [$employeeNo]
        );
    }

    public function record_next_punch(
        int $employeeId,
        string $date,
        string $now,
        int $userId,
        ?array $allowedSlots = null
    ): string
    {
        $this->db->transaction();
        try {
            $existing = $this->row(
                'SELECT id, time_in, time_out, time_in_2, time_out_2
                   FROM attendance WHERE employee_id = ? AND work_date = ? FOR UPDATE',
                [$employeeId, $date]
            );
            $slots = ['time_in', 'time_out', 'time_in_2', 'time_out_2'];
            $nextIndex = null;
            foreach ($slots as $index => $slot) {
                if (!$existing || !$existing[$slot]) {
                    $nextIndex = $index;
                    break;
                }
            }
            if ($nextIndex === null) {
                throw new DomainException('All four attendance punches are already recorded for today.');
            }
            for ($index = $nextIndex + 1; $index < count($slots); $index++) {
                if ($existing && $existing[$slots[$index]]) {
                    throw new DomainException('Attendance punches are out of order. Ask HR or MIS to correct the record.');
                }
            }
            $next = $slots[$nextIndex];
            if ($allowedSlots !== null && !in_array($next, $allowedSlots, true)) {
                throw new DomainException('Record the required attendance punch first.');
            }
            if ($nextIndex > 0 && $now <= $existing[$slots[$nextIndex - 1]]) {
                throw new DomainException('Wait until after the previous punch before recording the next one.');
            }

            if (!$existing) {
                $this->run(
                    "INSERT INTO attendance (employee_id, work_date, status, $next, recorded_by)
                     VALUES (?, ?, 'present', ?, ?)",
                    [$employeeId, $date, $now, $userId]
                );
            } else {
                $this->run(
                    "UPDATE attendance
                        SET $next = ?, status = IF(status = 'absent', 'present', status), recorded_by = ?
                      WHERE id = ? AND $next IS NULL",
                    [$now, $userId, $existing['id']]
                );
                if (!$this->row("SELECT id FROM attendance WHERE id = ? AND $next = ?", [$existing['id'], $now])) {
                    throw new DomainException('Attendance was recorded by another request. Refresh and try again.');
                }
            }
            $this->db->commit();
            return $next;
        } catch (PDOException $e) {
            $this->db->roll_back();
            if ((string) $e->getCode() === '23000') {
                throw new DomainException('Attendance was recorded by another request. Refresh and try again.');
            }
            throw $e;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public function save_entries(string $date, array $entries, int $userId): int
    {
        $saved = 0;
        foreach ($entries as $entry) {
            $employeeId = (int) $entry['employee_id'];
            $this->run(
                'INSERT INTO attendance (employee_id, work_date, status, time_in, time_out, time_in_2, time_out_2, remarks, recorded_by)
                 SELECT id, ?, ?, ?, ?, ?, ?, ?, ? FROM employees WHERE id = ?
                 ON DUPLICATE KEY UPDATE status = VALUES(status), time_in = VALUES(time_in),
                    time_out = VALUES(time_out), time_in_2 = VALUES(time_in_2), time_out_2 = VALUES(time_out_2),
                    remarks = VALUES(remarks), recorded_by = VALUES(recorded_by)',
                [
                    $date, $entry['status'], $entry['time_in'], $entry['time_out'],
                    $entry['time_in_2'], $entry['time_out_2'],
                    $entry['remarks'] !== '' ? $entry['remarks'] : null, $userId, $employeeId,
                ]
            );
            $saved++;
        }
        return $saved;
    }
}
