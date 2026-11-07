# SP-EPLMS backend (LavaLust)

JSON API. Run `composer install` in this directory before starting the API. See the top-level README for setup.

- `app/controllers/Base_api.php` shared helpers: JSON, JWT + role checks, audit log, notifications
- `app/models/Api_model.php` shared persistence model; API database reads, writes, and inserts are executed through this model
- `app/controllers/*.php` one controller per area (Auth, Leaves, Employees, Attendance, Salaries, Expenses, Analytics, Users, Settings, Audit, Notifications, Stats, Public_api)
- `app/config/routes.php` every endpoint, grouped by area
- `database/schema.sql` tables; `database/setup.php` creates `.env`, the database, tables and seed data
- Role rules live in each controller's `authenticate([...roles])` call, and the user's role is re-read from the database on every request

Controllers handle HTTP requests, validation, authorization, and responses. Database execution is delegated to `Api_model`; domain-specific SQL is currently supplied by the controllers through the shared persistence methods.

Built on LavaLust 4.6 (the framework code in `scheme/` is unchanged).

For production Gmail OTP delivery, set `MAIL_ENABLED=true`, `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, `MAIL_ENCRYPTION=tls`, and `MAIL_USERNAME` to the sending Gmail address. Set `MAIL_FROM` to that same address and store a Google App Password in `MAIL_PASSWORD` using the host's secret environment settings. Do not use the normal Gmail password. The Docker image installs Composer dependencies from `composer.lock`.
