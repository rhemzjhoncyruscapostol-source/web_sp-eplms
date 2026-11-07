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

Render free web services block outbound SMTP. For Gmail API delivery without a custom sending domain:

1. In Google Cloud Console, create a project, enable the Gmail API, and configure its OAuth consent screen.
2. Create an OAuth client and add `https://developers.google.com/oauthplayground` as an authorized redirect URI. In [Google OAuth 2.0 Playground](https://developers.google.com/oauthplayground/), enable **Use your own OAuth credentials**, enter that client ID and secret, request only `https://www.googleapis.com/auth/gmail.send`, authorize the sender account, and exchange the code for tokens.
3. In Render's secret environment settings, set `MAIL_ENABLED=true`, `GMAIL_CLIENT_ID`, `GMAIL_CLIENT_SECRET`, `GMAIL_REFRESH_TOKEN`, and `GMAIL_SENDER`. Use the same Gmail account that authorized the scope as `GMAIL_SENDER`. Never put OAuth credentials in source control or share them in chat.

Google classifies `gmail.send` as a sensitive scope. Public apps may need OAuth verification, and an OAuth consent app left in Testing can issue refresh tokens that expire after seven days. Complete Google's applicable verification/publishing requirements before relying on this for production delivery. See [Gmail API scopes](https://developers.google.com/workspace/gmail/api/auth/scopes) and [Google OAuth 2.0](https://developers.google.com/identity/protocols/oauth2).

Gmail API is preferred when all four Gmail settings are present; a partially configured Gmail API provider fails explicitly rather than silently falling back. If it is not configured, the backend can use Resend's HTTPS API by setting `RESEND_API_KEY` and `MAIL_FROM` to a verified sender, or authenticated SMTP on hosts that permit it. Do not use a normal Gmail password. The Docker image installs Composer dependencies from `composer.lock`.
