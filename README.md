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

Render free web services block outbound SMTP. For OTP delivery on Render, use Resend's HTTPS API: set `MAIL_ENABLED=true`, `RESEND_API_KEY` to a Resend API key, and `MAIL_FROM` to a sender address verified in Resend. Store the API key in the host's secret environment settings. SMTP settings remain available for hosts that permit SMTP; do not use a normal Gmail password. The Docker image installs Composer dependencies from `composer.lock`.
