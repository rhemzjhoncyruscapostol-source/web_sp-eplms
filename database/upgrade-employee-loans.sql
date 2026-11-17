-- Apply once to an existing SP-EPLMS database before using Employee Loans.
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
