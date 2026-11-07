-- Apply once to an existing SP-EPLMS database before deploying travel requests.
ALTER TABLE leave_requests
  MODIFY leave_type ENUM('vacation','sick','special','maternity','paternity','unpaid','travel') NOT NULL;

ALTER TABLE attendance
  MODIFY status ENUM('present','late','absent','on_leave','travel') NOT NULL;
