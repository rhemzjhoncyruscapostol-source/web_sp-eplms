CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(100) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(30) NULL DEFAULT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','hr','employee','mis','accounting') NOT NULL DEFAULT 'employee',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY username_unique (username),
  UNIQUE KEY email_unique (email),
  KEY role_idx (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS refresh_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  token TEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  jti TEXT NOT NULL,
  PRIMARY KEY (id),
  KEY user_id_idx (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employees (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL DEFAULT NULL,
  employee_no VARCHAR(30) NULL DEFAULT NULL,
  full_name VARCHAR(150) NOT NULL,
  job_title VARCHAR(120) NOT NULL,
  department VARCHAR(120) NULL DEFAULT NULL,
  reports_to INT UNSIGNED NULL DEFAULT NULL,
  date_hired DATE NULL DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY emp_user_unique (user_id),
  UNIQUE KEY emp_no_unique (employee_no),
  KEY reports_to_idx (reports_to),
  CONSTRAINT fk_emp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_boss FOREIGN KEY (reports_to) REFERENCES employees (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS salaries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT UNSIGNED NOT NULL,
  period CHAR(7) NOT NULL,
  basic DECIMAL(12,2) NOT NULL DEFAULT 0,
  allowances DECIMAL(12,2) NOT NULL DEFAULT 0,
  taxable_benefits DECIMAL(12,2) NOT NULL DEFAULT 0,
  non_taxable_benefits DECIMAL(12,2) NOT NULL DEFAULT 0,
  deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
  sss DECIMAL(12,2) NOT NULL DEFAULT 0,
  philhealth DECIMAL(12,2) NOT NULL DEFAULT 0,
  pagibig DECIMAL(12,2) NOT NULL DEFAULT 0,
  withholding_tax DECIMAL(12,2) NOT NULL DEFAULT 0,
  net DECIMAL(12,2) NOT NULL DEFAULT 0,
  remarks VARCHAR(255) NULL DEFAULT NULL,
  created_by INT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY employee_period_unique (employee_id, period),
  CONSTRAINT fk_sal_emp FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leave_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT UNSIGNED NOT NULL,
  leave_type ENUM('vacation','sick','special','maternity','paternity','unpaid') NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  days INT UNSIGNED NOT NULL DEFAULT 1,
  reason VARCHAR(500) NOT NULL,
  attachment VARCHAR(255) NULL DEFAULT NULL,
  credits_charged TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  reviewed_by INT UNSIGNED NULL DEFAULT NULL,
  review_remarks VARCHAR(255) NULL DEFAULT NULL,
  reviewed_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY leave_emp_idx (employee_id),
  KEY leave_status_idx (status),
  CONSTRAINT fk_leave_emp FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leave_credits (
  employee_id INT UNSIGNED NOT NULL,
  vacation DECIMAL(5,1) NOT NULL DEFAULT 0,
  sick DECIMAL(5,1) NOT NULL DEFAULT 0,
  special DECIMAL(5,1) NOT NULL DEFAULT 0,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (employee_id),
  CONSTRAINT fk_credit_emp FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  status ENUM('present','late','absent','on_leave') NOT NULL,
  time_in DATETIME NULL DEFAULT NULL,
  time_out DATETIME NULL DEFAULT NULL,
  remarks VARCHAR(255) NULL DEFAULT NULL,
  recorded_by INT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY employee_day_unique (employee_id, work_date),
  KEY work_date_idx (work_date),
  CONSTRAINT fk_att_emp FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_loans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT UNSIGNED NOT NULL,
  loan_name VARCHAR(120) NOT NULL,
  principal DECIMAL(12,2) NOT NULL,
  installment DECIMAL(12,2) NOT NULL,
  balance DECIMAL(12,2) NOT NULL,
  status ENUM('active','paid','cancelled') NOT NULL DEFAULT 'active',
  remarks VARCHAR(255) NULL DEFAULT NULL,
  created_by INT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY loan_employee_status_idx (employee_id, status),
  CONSTRAINT fk_loan_emp FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loan_repayments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  loan_id INT UNSIGNED NOT NULL,
  salary_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY loan_salary_unique (loan_id, salary_id),
  CONSTRAINT fk_repayment_loan FOREIGN KEY (loan_id) REFERENCES employee_loans (id) ON DELETE RESTRICT,
  CONSTRAINT fk_repayment_salary FOREIGN KEY (salary_id) REFERENCES salaries (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_schedules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  effective_from DATE NOT NULL,
  rules LONGTEXT NOT NULL,
  created_by INT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY payroll_schedule_effective_date (effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  body VARCHAR(500) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY notif_user_idx (user_id, is_read),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL DEFAULT NULL,
  username VARCHAR(100) NULL DEFAULT NULL,
  role VARCHAR(20) NULL DEFAULT NULL,
  action VARCHAR(60) NOT NULL,
  detail VARCHAR(500) NULL DEFAULT NULL,
  ip VARCHAR(45) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY audit_time_idx (created_at),
  KEY audit_action_idx (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS otp_challenges (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  challenge_hash CHAR(64) NOT NULL,
  code_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  resends TINYINT UNSIGNED NOT NULL DEFAULT 0,
  used TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY challenge_unique (challenge_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(60) NOT NULL,
  svalue VARCHAR(255) NOT NULL,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS budgets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  office VARCHAR(120) NOT NULL,
  fiscal_year SMALLINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY office_year_unique (office, fiscal_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expenses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  expense_date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  category VARCHAR(40) NOT NULL,
  office VARCHAR(120) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  flagged TINYINT(1) NOT NULL DEFAULT 0,
  flag_reason VARCHAR(255) NULL DEFAULT NULL,
  created_by INT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  prev_hash CHAR(64) NOT NULL,
  entry_hash CHAR(64) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY entry_hash_unique (entry_hash),
  KEY expense_date_idx (expense_date),
  KEY expense_office_idx (office)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
