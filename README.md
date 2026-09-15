# Enterprise CRM + ERP — Core PHP Edition

Core PHP (no framework), MySQL 8+, Bootstrap 5.3, ready to drop into
XAMPP's `htdocs/`. This is a **parallel implementation** of the same
Foundation + Authentication module previously built as an MVC app — this
version uses the traditional flat-file/`includes/` structure instead.

## Why this exists

An earlier delivery used an MVC pattern (`app/Controllers`, `app/Models`,
a front-controller router). That's a legitimate, well-tested architecture,
but it isn't the traditional "Core PHP" structure most XAMPP tutorials and
teams expect — standalone pages (`login.php`, `dashboard.php`, ...) plus
an `includes/` folder for shared logic. This version rebuilds the same
feature set that way, reusing the same tested security logic (password
hashing, CSRF, session hardening, login lockout, OTP, remember-me) ported
into plain functions instead of classes/controllers.

## Folder structure

```
crm-corephp/
├── admin/              Admin-only pages (require_role('super_admin'))
│   └── index.php       User management: list, search, paginate, suspend/reactivate
├── api/
│   └── session-check.php   AJAX endpoint for session-expiry polling
├── assets/              css/, js/, images/, icons/, fonts/, vendor/
├── includes/
│   ├── bootstrap.php    Loads everything below, in the right order — every
│   │                    page starts with require_once 'includes/bootstrap.php'
│   ├── config.php       Loads .env, defines constants (DB_*, APP_*, etc.)
│   ├── env.php          Dependency-free .env parser
│   ├── database.php     db(): PDO singleton, prepared statements only
│   ├── auth.php         Login/lockout/OTP/remember-me/password-reset logic
│   ├── session.php      Hardened session boot, fixation/hijacking guards
│   ├── csrf.php         CSRF token generate/verify
│   ├── functions.php    Validation helpers, rate limiter, pagination
│   ├── helpers.php      e() escaping, url()/asset() (XAMPP-subfolder-safe), redirect()
│   ├── header.php / navbar.php / sidebar.php / footer.php   Authenticated page shell
│   ├── guest-header.php / guest-footer.php   Shell for login/register/forgot/reset
│   └── alerts.php       Flash message rendering
├── uploads/              images/, documents/, temp/ (PHP execution blocked here)
├── database/
│   ├── database.sql      Import directly into phpMyAdmin
│   └── seed_super_admin.php
├── errors/               403.php, 404.php, 500.php (self-contained, no DB dependency)
├── index.php, login.php, login-otp.php, register.php, logout.php,
│   profile.php, forgot-password.php, reset-password.php, dashboard.php
├── .env.example
├── .htaccess
├── composer.json         Optional — only needed if you wire in PHPMailer
└── README.md
```

## Setup (XAMPP)

1. Copy this whole folder into `xampp/htdocs/` (any folder name — the app
   auto-detects its own install path, so it works whether it's at
   `http://localhost/` or `http://localhost/your-folder-name/`, with zero
   configuration).
2. Import the schema: open phpMyAdmin → Import → `database/database.sql`.
3. Copy `.env.example` to `.env` and fill in your DB credentials (XAMPP's
   default is usually `DB_USERNAME=root`, `DB_PASSWORD=` empty).
4. Create the first Super Admin:
   ```bash
   php database/seed_super_admin.php
   ```
5. Visit `http://localhost/<folder>/login.php`.

**Required writable directories:** `uploads/**`, `logs/`, `cache/` need
write access for the web server user.

## What's implemented

- Super Admin / Employee login, registration, logout
- OTP two-factor login (for accounts with `two_factor_enabled`)
- Forgot/reset password (single-use tokens, no account-enumeration leak)
- Remember-me (selector/validator pattern — raw token never stored)
- Login-attempt lockout (5 failed attempts / 15 min, configurable)
- Profile page: edit details, change password, view own login history
- Admin user management: search, paginate, suspend/reactivate accounts
- Dashboard: KPI card scaffolding + a real DataTable (your actual login
  history, not mock data)
- CSRF protection on every state-changing form
- Session hardening: HttpOnly/SameSite cookies, periodic ID rotation,
  fixation prevention on login, hijacking-fingerprint check, sliding
  auto-logout
- Audit log (generic — every future module can log to it)
- Rate limiting (file-based, no external cache server needed)

## Testing performed

This was tested the same way as the MVC version — against real Apache +
real MySQL (MariaDB), not just PHP's built-in dev server or a SQLite
stand-in:

- Full syntax lint: 31/31 PHP files clean (caught and fixed one real bug:
  `const` declared inside a function body, which is invalid PHP)
- Real `database.sql` imported into actual MySQL and schema verified
- End-to-end HTTP flows tested against the live app: login (success/
  failure), CSRF rejection, OTP 2FA (wrong code → correct code →
  dashboard), forgot/reset password (old password rejected, new one
  works, token is single-use), remember-me (cookie-only auto-login from
  a fresh session), login lockout (5 fails → blocked, including against
  the *correct* password while locked), profile update, change password,
  duplicate-email and weak-password rejection on registration, admin
  user search/pagination/suspend-reactivate, and role-based access
  control (a non-admin employee correctly gets 403 on `admin/index.php`)
- **XAMPP subfolder deployment tested for real**, not assumed: a second
  Apache vhost simulating `htdocs/project-name/` confirmed every
  generated link/redirect carries the correct subfolder prefix, and that
  the full login flow and all security protections (`.env`, `includes/`,
  `composer.json` all correctly blocked) hold identically under that
  deployment mode
- One real bug fixed during this pass: `composer.json` was directly
  downloadable (real file, no protection) — added to the root
  `.htaccess` deny list

## Module 2: Lead Management

Built and tested against real Apache + real MySQL, same rigor as the
Foundation/Auth module.

**New files:** `database/migration_002_leads.sql` (leads, lead_activities,
lead_documents tables), `includes/leads.php` (CRUD, timeline logging,
missed/cancelled automation), `includes/uploads.php` (secure file upload
handling, reusable by future modules), `leads/index.php` (search/filter/
paginate), `leads/form.php` (create/edit), `leads/view.php` (detail +
timeline + document upload), `leads/document-download.php` (authenticated
file serving).

**Implements the spec's follow-up automation exactly**: logging a
follow-up with no clear next step leaves a lead's `next_follow_up_at` in
the past: once overdue, it's automatically marked **Missed**; after 3
missed cycles (configurable), it's automatically marked **Cancelled**.
There's no cron in this environment, so this runs on-demand (a visible
"Process Missed Follow-ups" button, plus opportunistically on every list
view) — wire the same `process_missed_leads()` function to an actual cron
job in production instead.

**Documents are never directly web-accessible.** `uploads/` denies all
direct requests; every document is served through
`leads/document-download.php`, which requires login and logs the download
to the audit trail. Upload validation checks the actual file content via
`finfo` (not the client-supplied Content-Type, which is trivially
spoofed) against an allow-list per category.

### Bugs found and fixed while building this module

Testing this module (not just linting it) surfaced four real bugs — all
different failure classes, which is exactly why each module gets tested
end-to-end before moving to the next rather than just written and assumed
correct:

1. **Undefined array key warnings broke redirects.** `leads/form.php`
   accessed `$_POST['budget']`/`$_POST['deadline']` directly instead of
   with `??`. When those optional fields were omitted, the resulting
   PHP warnings (visible with `APP_DEBUG=true`) printed *before*
   `header('Location: ...')` was called, so the redirect silently failed
   (headers already sent) and the form just re-rendered instead of
   submitting. Fixed by using `??` consistently.
2. **A systemic, serious URL-generation bug**: `base_path()` computed the
   app's install location from the *currently executing script's own*
   path. That's correct for root-level pages, but for anything inside a
   subfolder — `leads/form.php`, `admin/index.php` — it incorrectly
   treated the page's own folder as the app root, producing links like
   `/leads/leads/view.php`. Caught by following the app's actual
   generated redirect rather than hardcoding the expected URL in a test.
   Fixed by computing the base path from the fixed relationship between
   the project's own folder and `DOCUMENT_ROOT`, independent of which
   script is running.
3. **Ambiguous SQL columns.** `leads` and `users` both have `deleted_at`,
   `status`, `mobile`, and `email` columns. Once the leads list query
   joined `users` (for the assignee's name), unqualified references to
   those columns in the `WHERE` clause became genuinely ambiguous to
   MySQL, which correctly rejected the query. Fixed by qualifying every
   filter column with `leads.`.
4. **A real MySQL/PDO limitation**: with real (non-emulated) prepared
   statements — which this project deliberately uses for security — MySQL's
   native protocol doesn't allow the same named placeholder to be bound
   more than once in a single query. The search filter's `:search`
   appeared four times (once per searched column) and threw "Invalid
   parameter number." Fixed by using a uniquely-named placeholder per
   occurrence, all bound to the same value.

After all four fixes: full CRUD, timeline/follow-up logging, real file
upload (including a malicious-file-content rejection test — a `.php` file
renamed to `.pdf` was correctly rejected by content inspection, not just
extension), authenticated-only document download, search/filter/
pagination, and the missed → cancelled escalation were all re-verified
end-to-end over real HTTP against real MySQL, plus a full regression pass
confirming the auth module, security protections, and CSRF enforcement
were untouched by this addition.

## Dedicated bug-hunt pass

A deliberate adversarial pass, separate from normal feature testing —
static analysis for known bug classes plus active exploitation attempts,
not just re-confirming known-good paths. Found and fixed 5 real issues:

1. **The exact same "repeated named placeholder" bug found in Leads was
   also lurking in `admin/index.php`'s user search** (`:q` used twice)
   — a previously-undiscovered latent bug, since earlier admin-panel
   testing only ever loaded the page with an empty search. Caught by
   scanning every prepared statement in the codebase for repeated
   placeholders, not just the file that had already shown the bug once.
2. **A real, more subtle issue**: PHP's default `upload_max_filesize`
   (2MB) and `post_max_size` (8MB) are both smaller than this app's
   intended 10MB upload limit. Anything in between silently got dropped
   by PHP itself — and critically, PHP empties `$_POST` (including the
   CSRF token) when `post_max_size` is exceeded, so the failure showed up
   as a confusing "session expired" CSRF error instead of a clear "file
   too large" one. Fixed three ways: self-configured PHP's limits via
   `.htaccess` (`php_value` directives, honored by mod_php/XAMPP with no
   manual config needed), added specific error messages for
   size-related upload failures, and added a dedicated check that
   detects the "PHP wiped $_POST" scenario before it's mistaken for CSRF
   failure. Verified with files on both sides of the boundary (9MB
   accepted, 11MB cleanly rejected with the right message).
3. **Active XSS injection attempts** against lead fields (`<script>`,
   `<img onerror>`) and the admin/leads search boxes (reflected-XSS
   pattern) — all correctly neutralized, either stripped at input
   (`sanitize_string()`) or entity-encoded at output (`e()`). Verified
   by checking the actual rendered HTML, not just reading the code.
4. **Active SQL injection attempts** via login email, search boxes, and
   numeric ID parameters (`1' OR '1'='1'`, `UNION SELECT ...`) — all
   safe: IDs are hard-cast to `int` before use, and every other query
   uses real prepared statements. Confirmed the ID-injection case
   specifically returns exactly one correct record, not an injected
   result set.
5. **A cleanup-script bug of my own that had already shipped**: an
   overly broad `find uploads -type f -not -name ".gitkeep" -delete`
   (meant to clear test upload files between test runs) also deleted
   `uploads/.htaccess` — the file that keeps uploaded documents from
   being directly downloadable without authentication. This had already
   gone out in the previous delivery. Caught by testing direct file
   access as part of this pass rather than assuming an earlier passing
   test still held; recreated the file, verified direct access is
   blocked again (403) while the authenticated download path still
   works (200), and fixed the cleanup command to explicitly exclude
   `.htaccess` going forward.

Also verified clean in this pass: every `$_POST`/`$_GET`/`$_FILES`
access across the whole codebase uses `??`/`isset()`/`empty()` (the
class of bug that broke lead creation earlier), every `JOIN` query's
columns are properly table-qualified, edge-case lead IDs (negative,
zero, non-numeric, huge, non-existent) are all handled gracefully, and
the Leads module works correctly under the XAMPP-subfolder simulation
(not just the auth module, which was the only thing tested there
before).

## What's next

Per the spec's module-by-module process: **Project Management** next
(create project, assign employee, deadline/buffer date, progress,
documents, timeline — reusing the same `includes/uploads.php` and
timeline pattern built for Leads), then Client Management, GST Billing,
Accounts, Employee Module, and Reports — each built and tested before
moving to the next.

## Modules 3–7: Clients, Projects, Employees, GST Billing, Accounts, Reports

**Important difference from the modules above: these were NOT tested
against a live Apache/MySQL server.** This build environment has no PHP
interpreter or MySQL instance available, so unlike Foundation/Auth and
Leads — which were verified with real end-to-end HTTP flows against a
real database — this pass was a thorough **static/structural review
only**: every helper function called was confirmed to exist with a
matching signature, every SQL column referenced was cross-checked
against its migration file, all braces/parens were balance-checked,
and every value passed through `e()` was checked for the
int/decimal-vs-string `strict_types` mismatch that would throw a
`TypeError`. That review did catch and fix one real, non-trivial bug
(below), but it is not a substitute for running the app. **Test each
module end-to-end against a real XAMPP/MySQL instance before relying on
it** — add a client, create a project against it, raise an invoice,
record a payment, log an expense — the same way every earlier module in
this project was actually verified.

**New files:** `database/migration_003_clients.sql` through
`migration_006_accounts.sql`; `includes/clients.php`,
`includes/employees.php`, `includes/projects.php`, `includes/billing.php`,
`includes/accounts.php`, `includes/reports.php`; and matching
`clients/`, `employees/`, `projects/`, `billing/`, `accounts/`,
`reports/` page folders. Dashboard's KPI cards and all six sidebar
items now point at real pages instead of "Soon" placeholders.

**GST Billing** computes CGST+SGST vs. IGST per invoice by comparing
the client's state to `COMPANY_STATE` (set in `.env`), assigns
gap-free invoice numbers per Indian financial year (Apr–Mar) via a
row-locked sequence table, and recalculates payment status
(`sent`/`partially_paid`/`paid`/`overdue`) from actual recorded
payments rather than trusting a manually-set status to stay in sync.
**Accounts** tracks expenses and reads Billing's `invoice_payments` for
the income side of a combined ledger, rather than keeping a second
copy of payment records. **Reports** is pure read-side aggregation —
no new tables — charted with Chart.js.

### Bug found during the structural review

**A self-inflicted CSP regression.** When the Bootstrap-CDN-blocked-by-CSP
issue was fixed earlier (allowing `cdn.jsdelivr.net` in `style-src`/
`script-src`), the new modules built afterward used inline
`<script>` blocks and inline event-handler attributes (`onclick`,
`onchange`, `onsubmit`) — all silently blocked by a `script-src`
without `'unsafe-inline'`. Re-auditing the whole project for this
pattern also turned up that **`dashboard.php`'s own pre-existing
DataTables init script had the identical problem from the start**,
independent of anything built in this pass. Fixed properly (not by
loosening the policy): added a per-request CSP nonce (`csp_nonce()` in
`includes/helpers.php`), applied it to every legitimate inline
`<script>` tag, and replaced every inline event-handler attribute with
`addEventListener` bindings in nonced script blocks instead. Confirmed
by grep that zero inline handlers and zero un-nonced `<script>` tags
remain anywhere in the project.

## CRM/ERP Improvement Batch: GST split, lead/client meetings, Accounts graph, attendance, notifications

Nine targeted, backward-compatible changes — existing theme, layout,
navigation, and database were otherwise left untouched.

- **GST Report** (`accounts/gst_report.php`) now separates invoices into
  **GST Entered** and **Non-GST** tables (`split_gst_invoices()` in
  `includes/billing.php`, built on the existing `invoice_is_gst()`
  check) so non-GST invoices never show padded 0 CGST/SGST/IGST
  columns. Same separation applies to print/PDF and to the new Excel
  export (`accounts/gst_report_export.php` — no spreadsheet library
  available/installable here, so it streams an HTML table as `.xls`,
  which Excel opens natively).
- **Leads** can optionally schedule a meeting right on the create form
  (`leads/form.php`) — the lead is always saved regardless. **Leads and
  Clients** now support a full meeting history: `database/migration_035_meeting_lead_client_link.sql`
  adds nullable `lead_id`/`client_id` to the existing `meetings` table
  (previously a standalone HR scheduler with no CRM link at all), and
  `includes/meeting_widget.php` is a shared "Schedule Next Meeting" +
  history card used by both `leads/view.php` and `clients/view.php` —
  every call inserts a new meeting row, never overwriting a prior one.
- **Accounts dashboard** (`accounts/index.php`) gained a Financial
  Overview graph (Income/Expenses/Net/Outstanding Receivables/GST)
  with a period filter (This Week/Month/Previous Month/Last 3 or 6
  Months/Custom), built with Chart.js — already used by
  `reports/index.php`, so no new library was introduced.
- **Employees** (`employees/index.php`) gained a "Today's Status"
  Present/Absent column, reusing the existing attendance system
  (`attendance_for_date()`/`mark_attendance()` in `includes/hr.php`)
  rather than a new one; it's a per-day lookup, not a profile field.
- **Notifications**: `group_notifications_by_day()` in
  `includes/notifications.php` groups Today/Yesterday/Day Before
  Yesterday/Older, used by both the full notifications list
  (`notifications/index.php`) and a new dismissible popup shown once
  per login session on `dashboard.php`.

