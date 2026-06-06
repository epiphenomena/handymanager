# handymanager

A simple PWA + backend for running a small handyman service: techs log their
work from their phones, the office opens jobs from service calls and tracks
them through billing.

## Terminology

- **Job** — a unit of customer work. Opened when the office logs a service
  call, identified by a name built from the customer name + location
  (e.g. `Smith - 412 Oak Ave`). A job moves through statuses:
  `open → in progress → ready for billing → billed → paid`, plus an
  **on hold** status that hides the job from the tech picker (and blocks
  new tasks) without closing it.
  Once marked **ready for billing** a job is closed to new tasks and
  disappears from the tech job picker. A repeat customer at the same
  location gets a *new* job opened after the previous one closed.
  A permanent system job, **Clock in/out**, always appears in the tech
  picker for shop/non-job time.
- **Task** — a single tech work entry (start, stop, notes) belonging to a
  job. What used to be called a "job" in the old schema is now a task.
  A task is "in progress" when it has no end time; any task without an
  end time blocks marking its job ready for billing.

## Architecture

### Tech PWA (mobile, deliberately minimal)

- `index.html` — settings (token + tech name in localStorage), list of the
  tech's in-progress tasks, history with edit.
- `new-task.html` — start a task. The job is picked from a **locked
  autocomplete** of open jobs + Clock in/out; freeform locations are not
  accepted (enforced server-side too — `create-task.php` only accepts the id
  of a job that is open to tasks).
- `complete-task.html` — end time + notes, closes the task.
- `edit-task.html` — fix times/notes on the tech's own tasks.

Tech JSON endpoints (all POST, token verified on every request):
`get-open-jobs.php`, `create-task.php`, `get-tasks.php`,
`get-latest-tasks.php`, `get-task.php`, `complete-task.php`,
`update-task.php`.

### Admin dashboard — `admin.php`

Server-rendered PHP + [htmx](https://htmx.org). `GET` serves the page shell;
every data interaction is a `POST` returning an HTML fragment, with the admin
token (kept in localStorage) attached and verified on **every** request.

- **Active / Billing / Paid** — jobs grouped by status (active includes
  open, in progress, and on hold). Cards show status, task counts, hours,
  and the admin job notes.
- **Job detail** — timeline of the opening call and every task; editable job
  notes; status transition buttons (ready for billing / billed / paid, plus
  on hold and reopen); edit job details; add tasks directly (for work a tech
  reported outside the app); edit/delete tasks; delete job; export the whole
  job as JSON (for feeding to an AI) or formatted Markdown.
- **Log Call** — opens a job: customer name, location (together they become
  the official job name), phone, call notes. Customer/location autocomplete
  from past jobs (freeform allowed); picking a known customer prefills
  their last location and phone.
- **Reports** — jobs completed per month (with status breakdown, hours and a
  per-month drill-down) and tasks per tech per month. All exportable as CSV.

### Standalone call log — `log-call.php`

A simplified single-purpose page for the administrative assistant: token
gate on first visit, then just the call form. Same autocomplete/prefill
behavior as the admin Log Call tab.

### Backend

Hand-written vanilla PHP, no dependencies, SQLite storage.

- `config.php` — tokens (from `config.json`), auth + request helpers.
- `database.php` — schema migrations and all queries.

Two tokens in `config.json`: `VALID_TOKEN` (techs) and `ADMIN_TOKEN`
(office/admin). Token checks are the only security and run on every request.
`config.json` is gitignored, excluded from deploys, and blocked from web
access by `.htaccess` (as are the `*.db` files, which live in the web root).

### Caching

Deployed updates must show up immediately (techs keep the PWA open for
days). Three layers handle this:

1. `.htaccess` sets `Cache-Control: no-cache, must-revalidate` on
   html/css/js/manifest so browsers revalidate every load (ETag/304 keeps
   unchanged files cheap).
2. The service worker fetches all GETs with `cache: 'no-store'` (only the
   static icons are cache-first) and never intercepts POSTs.
3. Page JS is inline in the html files; css links carry a `?v=` param as a
   belt-and-suspenders fallback.

### Schema & migrations

`database.php` runs versioned migrations automatically on first request,
tracked with `PRAGMA user_version`. Migration 1 splits the legacy single
`jobs` table into `jobs` + `tasks` (the old table is preserved as
`legacy_jobs`); each distinct legacy location becomes one job. To add a
schema change, append a numbered migration in `initDatabase()`.

```
jobs:  id, name, customer_name, phone, call_notes, admin_notes,
       status, is_system, opened_at, ready_for_billing_at, billed_at, paid_at
tasks: id, job_id, created_at, tech_name, start_time, end_time, notes, closed_at
```

## Development & testing

The database file can be overridden with the `HANDYMANAGER_DB` env var, so
local testing never touches real data.

```bash
rake seed     # build handymanager-test.db with sample data
rake dev      # dev server on :8000 using the test db
rake test     # API smoke tests against a throwaway db (tools/smoke-test.sh)
rake pulldb   # copy the PRODUCTION db down for local inspection
              # (backs up any local copy first; never pushed back)
```

Or run against whatever db you like:

```bash
HANDYMANAGER_DB=some.db php dev-server.php 8080
```

Default tokens for local dev are in `config.json` (gitignored).

## Deployment

```bash
rake sync
```

Deploys via rsync. `*.db` and `config.json` are excluded in both directions —
production data and production tokens are never overwritten. The schema
migration runs automatically on the first request after deploying.
