# SVU Faculty Portal — API

Laravel 12 + MySQL/MariaDB backend for the six tutor features in `../faculty-portal`. The design is in
[../BACKEND_PLAN.md](../BACKEND_PLAN.md).

## Run it locally (XAMPP)

Requirements: PHP 8.2+, Composer, MySQL 8 or MariaDB 10.4+ (XAMPP's works). 

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the database: start MySQL from the XAMPP control panel, then run:

```bash
C:/xampp/mysql/bin/mysql.exe -u root -e "CREATE DATABASE svu_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

Build tables and sample data (mirrors `faculty-portal/src/data/mock.ts`):

```bash
php artisan migrate:fresh --seed
```

Start the API on http://localhost:8000:

```bash
php artisan serve
```

Background work (e-mail sending, closing finished sessions, reminders) needs two more terminals:

```bash
php artisan queue:work
```

```bash
php artisan schedule:work
```

E-mail goes to `storage/logs/laravel.log` (`MAIL_MAILER=log`) until SMTP is configured in `.env`.

To open the portal from other devices on your network, or to put it on a server, see [../HOSTING.md](../HOSTING.md):
Laravel then serves the built React app (`public/spa`) and the API from one address. On a fresh install with no demo
seed, create the first admin with `php artisan portal:create-user --role=admin`.

### Test accounts (development only)

All seeded accounts use the password `Password1`.

| Username | Role | Notes |
|---|---|---|
| `t_samer` | Tutor | Owns every seeded class except WAP C3 |
| `t_mona` | Tutor | Owns WAP C3, useful for checking a tutor can't see another tutor's class |
| `m_bit` | Program manager | Reviews BIT and ITE |
| `m_mws` | Program manager | Reviews MWS |
| `admin` | Admin | Reviews everything |

## Tests

```bash
php artisan test
```

Tests run on in-memory SQLite (see `phpunit.xml`) and cover every endpoint group: auth, the course picker,
sessions with the finance-report flow, uploads and files, exams, and e-mail. `ReviewRegressionTest` locks in the fixes
from the API review.

## How the SPA authenticates

Sanctum in SPA mode, using a session cookie with CSRF protection and no tokens in `localStorage`:

1. `GET /sanctum/csrf-cookie`
2. `POST /api/v1/auth/login` with `{ login, password, remember }`, sending the `X-XSRF-TOKEN` header taken from the `XSRF-TOKEN` cookie.
3. Every later request sends cookies (`credentials: 'include'`) and `Accept: application/json`.

In development the SPA calls the API through the Vite proxy (`faculty-portal/vite.config.ts` forwards `/api` and
`/sanctum` to `127.0.0.1:8000`), so the browser sees a single origin and the cookie needs no cross-site rules. Calling the
API directly from another origin also works: `config/cors.php` allows credentials for the origins in
`CORS_ALLOWED_ORIGINS` (default `FRONTEND_URL`). Keep `SESSION_DOMAIN=null` for localhost.

`SANCTUM_STATEFUL_DOMAINS` in `.env` must include the SPA origin (`localhost:5173` by default).
Send `Accept-Language: ar` or `en` to choose the language of error messages.

## Conventions
- **Lists** (`sessions`, `files`, `exams`, `emails`, `manage/*`) are paginated: `{ data, links, meta }`, with `?perPage=` (1–200, default 50) and `?page=`. `my/offerings` and the lookups return plain arrays.
- **Uploads** are all-or-nothing: if any file in a request is refused, none are stored and every upload can be reused.
- **Refused content:** programs, scripts and web pages are refused whatever their file name, because the type is detected from the file's bytes.
- **Quick uploads:** `uploads/direct` accepts up to 40 MB (`DIRECT_UPLOAD_MAX_KB`), matching XAMPP's `upload_max_filesize`. Larger files use chunks.
- **File permissions:** only the person who uploaded a file can hide, show, rename or delete it. Co-tutors of the course can see and download it.
- **Login info:** `lastLogin` / `lastIp` are the login *before* the current one, as in the SVUIS welcome box.
- **Password changes:** a password reset signs the account out everywhere. Changing the password from the profile signs out every other device.

## Endpoints (`/api/v1`)

| Area | Endpoints |
|---|---|
| Auth | `POST auth/login` · `POST auth/logout` · `GET auth/me` · `POST auth/forgot` · `POST auth/forgot/verify` · `POST auth/reset` · `POST account-requests` |
| Profile | `PATCH me` · `PUT me/password` · `PATCH me/preferences` |
| 1 · Course page | `GET terms` · `GET programs` · `GET exam-centers` · `GET my/offerings?term=&program=` · `GET offerings/{id}` |
| 2 · Schedule session | `GET sessions?term=&offering=&class=&status=&from=&to=` · `POST sessions` · `PATCH/DELETE sessions/{id}` · `POST sessions/{id}/report` · `GET sessions/{id}/join` |
| 3 · Upload session | `POST sessions/recorded` · `POST sessions/{id}/recording` |
| Uploads | `POST uploads` → `PUT uploads/{id}/chunks/{n}` → `POST uploads/{id}/complete` · `GET uploads/{id}` (resume) · `POST uploads/direct` (≤ 40 MB) |
| 4 · Files | `GET files?term=&offering=&class=&kind=&q=` · `POST files` · `PATCH/DELETE files/{id}` · `GET files/{id}/download` |
| 5 · Exams | `GET exams?term=&program=&offering=&from=&to=` · `POST exams/check` · `POST exams` · `DELETE exams/{id}` · `GET exams/export` (CSV) |
| 6 · E-mail | `GET emails` · `GET classes/{id}/recipients` · `POST emails` |
| Manager | `GET manage/sessions?status=` · `POST manage/sessions/{id}/approve\|reject\|pay` · `GET manage/exams?status=` · `POST manage/exams/{id}/approve\|reject` |
| Admin | `GET manage/account-requests?status=` · `POST manage/account-requests/{id}/approve\|reject` · `GET manage/classes?term=&program=` · `GET manage/tutors` · `PATCH manage/classes/{id}` |

Errors: `422` validation (`errors.field[]`), `403` not your class, `409` invalid status change, `401` not signed in.

## Where things live

| Path | What |
|---|---|
| `routes/api.php` | All API routes |
| `routes/web.php` | Every other URL returns the built React app (`public/spa/index.html`) |
| `app/Http/Controllers/Api/V1/` | One controller per feature |
| `app/Http/Resources/` | JSON shapes, matching `faculty-portal/src/data/types.ts` |
| `app/Enums/SessionStatus.php` | Session status flow (SVUIS legend) and allowed transitions |
| `app/Support/Uploads.php` | Turns a finished chunked upload into a stored file |
| `app/Jobs/SendClassEmail.php` | Sends class e-mail in BCC batches |
| `app/Console/Commands/` | Scheduled jobs (close finished sessions/exams, reminders, purge stale uploads) and `portal:create-user` |
| `config/portal.php` | Limits: durations, file sizes, allowed types, e-mail rate |
| `lang/{ar,en}/` | Error and validation messages |
