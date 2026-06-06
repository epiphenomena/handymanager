# CLAUDE.md — notes for future iteration

Read README.md first for the architecture. These are the invariants and
gotchas that aren't obvious from the code.

## Invariants — do not break

- **Token on every request.** The token check (tech or admin) is the ONLY
  security. Every endpoint must verify it before doing anything:
  `getValidatedInput()` for JSON endpoints, the explicit
  `verifyAdminToken($_POST['token'])` block in admin.php for htmx fragments.
- **Tech location lockdown.** Techs can only log tasks against an id from
  `getOpenJobsForTech()` (open/in-progress jobs + the `is_system` Clock
  in/out job). `create-task.php` must keep rejecting anything else —
  client-side validation alone is not enough.
- **"In progress" = `end_time IS NULL`.** `closed_at` is just an audit
  timestamp; never use it for business logic. Any path that sets an end
  time must also set `closed_at` (see complete-task.php, update-task.php,
  and the save-task/add-task actions in admin.php).
- **Ready for billing closes a job**: no new tasks, hidden from techs.
  Tasks with no end time block the transition. `on_hold` also hides/blocks
  but is reversible and lives in the "active" admin group.
- **Schema changes need a migration**: append a numbered entry to
  `$migrations` in `initDatabase()` (database.php). Migrations run
  automatically on first request via `PRAGMA user_version` — there is no
  manual migration step. Status values are TEXT, so new statuses don't
  need a migration.
- **The tech pages stay dead simple.** Big buttons, native inputs, inline
  JS, no frameworks. The admin side uses htmx; the tech side does not.

## Gotchas learned the hard way

- **Service worker must never intercept POSTs.** Re-creating a request
  drops the body → "Invalid JSON input" everywhere. It once "worked" only
  because the SW failed to install (bad icon paths in cache.addAll).
- **Caching:** techs keep the PWA open for days. Freshness comes from
  .htaccess `Cache-Control: no-cache` + SW `cache: 'no-store'` on GETs.
  If you add external assets, pin versions (htmx is pinned in admin.php).
- **Secrets in the web root:** config.json and *.db are only protected by
  .htaccess deny rules. Keep those rules intact if you touch .htaccess.
- **fputcsv quotes** headers/fields containing spaces — mind string
  expectations in tools/smoke-test.sh.
- iOS autocomplete taps: use `mousedown` (fires before blur, not during
  scroll), keep inputs/suggestions at font-size ≥16px to avoid iOS zoom.

## Workflow

- `rake seed` → sample db; `rake dev` → server on :8000 against it;
  `rake test` → tools/smoke-test.sh (every feature should add checks here);
  `rake pulldb` → copy production db down.
- `rake sync` deploys via rsync to DreamHost. Excludes *.db, config.json,
  tools/, Rakefile. Production migrates itself on the next request.
- `HANDYMANAGER_DB` env var overrides the db path everywhere.
- Tech-visible API responses are JSON; admin fragment responses are HTML
  rendered server-side (always escape with `h()`).
