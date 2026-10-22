<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Api_model.php';

class Attendance_model extends Api_model
{
    public function for_date(string $date): array
    {
        $rows = $this->rows(
            "SELECT e.id AS employee_id, e.employee_no, e.full_name, e.job_title, e.department,
                    a.status, a.time_in, a.time_out, a.remarks,
                    EXISTS (SELECT 1 FROM leave_requests l WHERE l.employee_id = e.id AND l.status = 'approved'
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
                'SELECT status, time_in, time_out FROM attendance WHERE employee_id = ? AND work_date = ?',
                [$employeeId, $today]
            ),
            'records' => $this->rows(
                'SELECT work_date, status, time_in, time_out, remarks FROM attendance
                  WHERE employee_id = ? AND work_date >= ? ORDER BY work_date DESC',
                [$employeeId, $fromDate]
            ),
        ];
    }

    public function record_time_in(int $employeeId, string $date, string $now, int $userId): void
    {
        $existing = $this->row(
            'SELECT id, time_in FROM attendance WHERE employee_id = ? AND work_date = ?',
            [$employeeId, $date]
        );
        if ($existing) {
            if ($existing['time_in']) {
                throw new DomainException('Time in is already recorded for today.');
            }
            $this->run(
                "UPDATE attendance SET time_in = ?, status = IF(status = 'absent', 'present', status), recorded_by = ? WHERE id = ? AND time_in IS NULL",
                [$now, $userId, $existing['id']]
            );
            if (!$this->row('SELECT id FROM attendance WHERE id = ? AND time_in = ?', [$existing['id'], $now])) {
                throw new DomainException('Time in was recorded by another request. Refresh your attendance.');
            }
            return;
        }

        try {
            $this->run(
                "INSERT INTO attendance (employee_id, work_date, status, time_in, recorded_by)
                 VALUES (?, ?, 'present', ?, ?)",
                [$employeeId, $date, $now, $userId]
            );
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            throw new DomainException('Time in was recorded by another request. Refresh your attendance.');
        }
    }

    public function record_time_out(int $employeeId, string $date, string $now): void
    {
        $record = $this->row(
            'SELECT id, time_in, time_out FROM attendance WHERE employee_id = ? AND work_date = ?',
            [$employeeId, $date]
        );
        if (!$record || !$record['time_in']) {
            throw new DomainException('Record your time in before time out.');
        }
        if ($record['time_out']) {
            throw new DomainException('Time out is already recorded for today.');
        }
        if (!$this->run('UPDATE attendance SET time_out = ? WHERE id = ? AND time_out IS NULL', [$now, $record['id']])) {
            throw new DomainException('Time out was recorded by another request. Refresh your attendance.');
        }
    }

    public function save_entries(string $date, array $entries, int $userId): int
    {
        $saved = 0;
        foreach ($entries as $entry) {
            $employeeId = (int) $entry['employee_id'];
            $this->run(
                'INSERT INTO attendance (employee_id, work_date, status, time_in, time_out, remarks, recorded_by)
                 SELECT id, ?, ?, ?, ?, ?, ? FROM employees WHERE id = ?
                 ON DUPLICATE KEY UPDATE status = VALUES(status), time_in = VALUES(time_in),
                    time_out = VALUES(time_out), remarks = VALUES(remarks), recorded_by = VALUES(recorded_by)',
                [
                    $date, $entry['status'], $entry['time_in'], $entry['time_out'],
                    $entry['remarks'] !== '' ? $entry['remarks'] : null, $userId, $employeeId,
                ]
            );
            $saved++;
        }
        return $saved;
    }
}
