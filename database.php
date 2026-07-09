<?php
// database.php - SQLite database operations
//
// Terminology:
//   job  - a unit of customer work, opened by an admin call and closed when
//          marked ready for billing. Identified by a name (customer/location).
//   task - a single tech work entry (start, stop, notes) belonging to a job.

// Database file path (override with HANDYMANAGER_DB env var for testing)
define('DB_FILE', getenv('HANDYMANAGER_DB') ?: __DIR__ . '/handymanager.db');

// Job statuses
//   closed = opened but won't be taken through to paid (abandoned/cancelled).
const JOB_STATUSES = ['open', 'in_progress', 'on_hold', 'ready_for_billing', 'billed', 'paid', 'closed'];
// Statuses that accept new tasks (and appear in the tech autocomplete).
// on_hold and closed are deliberately excluded: they hide the job from techs.
const JOB_ACTIVE_STATUSES = ['open', 'in_progress'];
// Human-readable status labels (used by the admin UI and JSON export).
const STATUS_LABELS = [
    'open' => 'Open',
    'in_progress' => 'In Progress',
    'on_hold' => 'On Hold',
    'ready_for_billing' => 'Ready for Billing',
    'billed' => 'Billed',
    'paid' => 'Paid',
    'closed' => 'Closed',
];

// Initialize database connection
function getDbConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function now() {
    return date('Y-m-d H:i:s');
}

// ---------------------------------------------------------------------------
// Schema migrations
// ---------------------------------------------------------------------------
// Versioned via PRAGMA user_version. Each migration runs once, in order,
// inside a transaction. Add new migrations at the end with the next number.

function initDatabase() {
    $pdo = getDbConnection();
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();

    $migrations = [
        1 => 'migration1_jobs_and_tasks',
        2 => 'migration2_task_client_uuid',
        3 => 'migration3_merge_legacy_clock_job',
        4 => 'migration4_job_closed_status',
        5 => 'migration5_job_tags',
        6 => 'migration6_job_email',
    ];

    foreach ($migrations as $target => $fn) {
        if ($version < $target) {
            $pdo->beginTransaction();
            try {
                $fn($pdo);
                $pdo->exec("PRAGMA user_version = $target");
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Migration $target failed: " . $e->getMessage());
                throw $e;
            }
            $version = $target;
        }
    }
}

// Migration 1: split the legacy single "jobs" table into jobs + tasks.
function migration1_jobs_and_tasks($pdo) {
    // Is there a legacy table to migrate? (fresh installs have nothing)
    $hasLegacy = false;
    $row = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='jobs'")->fetch();
    if ($row) {
        // Old schema had a "location" column directly on jobs
        $cols = $pdo->query("PRAGMA table_info(jobs)")->fetchAll();
        foreach ($cols as $col) {
            if ($col['name'] === 'location') {
                $hasLegacy = true;
                break;
            }
        }
    }

    if ($hasLegacy) {
        $pdo->exec("ALTER TABLE jobs RENAME TO legacy_jobs");
    }

    $pdo->exec("
        CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,                          -- official job name (customer / location)
            customer_name TEXT,
            phone TEXT,
            call_notes TEXT,                             -- notes from the opening call
            admin_notes TEXT,                            -- ongoing admin notes / progress tracking
            status TEXT NOT NULL DEFAULT 'open',         -- open|in_progress|ready_for_billing|billed|paid
            is_system INTEGER NOT NULL DEFAULT 0,        -- 1 for the permanent Clock in/out job
            opened_at TEXT NOT NULL,
            ready_for_billing_at TEXT,
            billed_at TEXT,
            paid_at TEXT
        )
    ");

    $pdo->exec("
        CREATE TABLE tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL,
            tech_name TEXT NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT,
            notes TEXT,
            closed_at TEXT
        )
    ");

    $pdo->exec("CREATE INDEX idx_tasks_job_id ON tasks(job_id)");
    $pdo->exec("CREATE INDEX idx_tasks_tech_name ON tasks(tech_name)");
    $pdo->exec("CREATE INDEX idx_jobs_status ON jobs(status)");

    // Permanent system job for clocking in/out (never closes, hidden from job lists)
    $pdo->prepare("INSERT INTO jobs (name, status, is_system, opened_at) VALUES ('Clock in/out', 'open', 1, :now)")
        ->execute(['now' => now()]);

    if ($hasLegacy) {
        // Group legacy entries by location: each distinct location becomes one job,
        // and every legacy entry becomes a task under it.
        $locations = $pdo->query("
            SELECT TRIM(location) AS loc, MIN(created_at) AS first_created
            FROM legacy_jobs
            GROUP BY TRIM(location)
            ORDER BY first_created
        ")->fetchAll();

        $insertJob = $pdo->prepare("
            INSERT INTO jobs (name, status, opened_at) VALUES (:name, 'in_progress', :opened_at)
        ");
        $insertTask = $pdo->prepare("
            INSERT INTO tasks (job_id, created_at, tech_name, start_time, end_time, notes, closed_at)
            VALUES (:job_id, :created_at, :tech_name, :start_time, :end_time, :notes, :closed_at)
        ");

        foreach ($locations as $location) {
            $insertJob->execute([
                'name' => $location['loc'],
                'opened_at' => $location['first_created'] ?: now(),
            ]);
            $jobId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("SELECT * FROM legacy_jobs WHERE TRIM(location) = :loc ORDER BY start_time");
            $stmt->execute(['loc' => $location['loc']]);
            foreach ($stmt->fetchAll() as $entry) {
                $insertTask->execute([
                    'job_id' => $jobId,
                    'created_at' => $entry['created_at'],
                    'tech_name' => trim($entry['tech_name']),
                    'start_time' => $entry['start_time'],
                    'end_time' => $entry['end_time'],
                    'notes' => $entry['notes'],
                    'closed_at' => $entry['closed_at'],
                ]);
            }
        }

        // One-time cleanup: migrated jobs with no task activity in the last
        // 60 days go straight to ready for billing, so old history doesn't
        // clutter the tech picker or the Active tab. Jobs with a dangling
        // unfinished task are left in progress for the admin to review
        // (a job must not be closed while a task has no end time).
        $stmt = $pdo->prepare("
            UPDATE jobs
            SET status = 'ready_for_billing', ready_for_billing_at = :now
            WHERE is_system = 0 AND status = 'in_progress'
              AND id NOT IN (
                  SELECT job_id FROM tasks
                  WHERE COALESCE(end_time, start_time) > :cutoff
              )
              AND id NOT IN (
                  SELECT job_id FROM tasks WHERE end_time IS NULL
              )
        ");
        $stmt->execute([
            'now' => now(),
            'cutoff' => date('Y-m-d H:i:s', strtotime('-60 days')),
        ]);
    }
}

// Migration 2: client-generated UUID on tasks so offline replays are
// idempotent (a queued create retried after a lost response can't duplicate).
function migration2_task_client_uuid($pdo) {
    $pdo->exec("ALTER TABLE tasks ADD COLUMN client_uuid TEXT");
    $pdo->exec("CREATE UNIQUE INDEX idx_tasks_client_uuid ON tasks(client_uuid) WHERE client_uuid IS NOT NULL");
}

// Migration 3: the legacy db used the location "Clocked in/out", which
// migration 1 turned into a regular job instead of recognizing it as clock
// time. Move those tasks onto the system Clock in/out job and remove the
// stray job(s).
function migration3_merge_legacy_clock_job($pdo) {
    $clockId = $pdo->query("SELECT id FROM jobs WHERE is_system = 1 LIMIT 1")->fetchColumn();
    if (!$clockId) {
        return;
    }
    $stmt = $pdo->query("
        SELECT id FROM jobs
        WHERE is_system = 0
          AND LOWER(TRIM(name)) IN ('clocked in/out', 'clock in/out', 'clocked in / out', 'clock in / out')
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $strayId) {
        $pdo->prepare("UPDATE tasks SET job_id = :clock_id WHERE job_id = :stray_id")
            ->execute(['clock_id' => $clockId, 'stray_id' => $strayId]);
        $pdo->prepare("DELETE FROM jobs WHERE id = :stray_id")
            ->execute(['stray_id' => $strayId]);
    }
}

// Migration 4: a job-level closed_at, supporting the "closed" status for
// jobs opened but not taken through to paid.
function migration4_job_closed_status($pdo) {
    $pdo->exec("ALTER TABLE jobs ADD COLUMN closed_at TEXT");
}

// Migration 5: a curated tag vocabulary (tags) and a many-to-many link to
// jobs (job_tags). Tag names are case-insensitively unique so "HVAC" and
// "hvac" can't both exist. Seeded with the common skill/license tags.
function migration5_job_tags($pdo) {
    $pdo->exec("
        CREATE TABLE tags (
            id   INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL COLLATE NOCASE UNIQUE
        )
    ");
    $pdo->exec("
        CREATE TABLE job_tags (
            job_id INTEGER NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
            tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
            PRIMARY KEY (job_id, tag_id)
        )
    ");
    $seed = $pdo->prepare("INSERT INTO tags (name) VALUES (:name)");
    foreach (['Plumbing', 'Electrical', 'HVAC'] as $name) {
        $seed->execute(['name' => $name]);
    }
}

// Migration 6: an optional customer email captured when logging a call.
function migration6_job_email($pdo) {
    $pdo->exec("ALTER TABLE jobs ADD COLUMN email TEXT");
}

// ---------------------------------------------------------------------------
// Jobs
// ---------------------------------------------------------------------------

// Open a new job from a service call
// $openedAt lets the caller backdate a call logged after the fact
// (a validated 'Y-m-d H:i:s' string); defaults to the current time.
function createJob($name, $customerName, $phone, $email, $callNotes, $openedAt = null) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        INSERT INTO jobs (name, customer_name, phone, email, call_notes, status, opened_at)
        VALUES (:name, :customer_name, :phone, :email, :call_notes, 'open', :opened_at)
    ");
    $stmt->execute([
        'name' => trim($name),
        'customer_name' => trim($customerName ?? '') ?: null,
        'phone' => trim($phone ?? '') ?: null,
        'email' => trim($email ?? '') ?: null,
        'call_notes' => trim($callNotes ?? '') ?: null,
        'opened_at' => $openedAt ?: now(),
    ]);
    return (int)$pdo->lastInsertId();
}

function getJobById($jobId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = :id");
    $stmt->execute(['id' => $jobId]);
    return $stmt->fetch() ?: null;
}

// Jobs (non-system) in the given statuses, with task aggregates.
// $search does a partial, token-based match on the job name: each
// whitespace-separated token must appear (any order). $tagId, when set,
// restricts to jobs carrying that tag. $flag ('on_location' | 'job_complete')
// narrows to jobs with a tech currently clocked in, or a tech's "job complete"
// note (fuzzy) - the two active-jobs review filters.
//
// Tags, clocked-in techs, and task notes are pulled via correlated subqueries,
// NOT a LEFT JOIN: a one-to-many join would multiply rows and break the task
// COUNT/SUM aggregates.
//
// Each returned row also carries:
//   on_location_techs - comma-separated names of techs with an open task
//   on_location       - bool: is anyone clocked in
//   job_complete      - bool: does any task note say "job complete" (fuzzy)
function getJobsByStatus(array $statuses, $search = '', $tagId = null, $flag = '') {
    $pdo = getDbConnection();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $where = ['j.is_system = 0', "j.status IN ($placeholders)"];
    $params = array_values($statuses);
    foreach (preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) as $token) {
        $where[] = 'LOWER(j.name) LIKE ?';
        $params[] = '%' . mb_strtolower($token) . '%';
    }
    if ($tagId !== null && $tagId !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM job_tags jt WHERE jt.job_id = j.id AND jt.tag_id = ?)';
        $params[] = (int)$tagId;
    }
    $stmt = $pdo->prepare("
        SELECT j.*,
               COUNT(t.id) AS task_count,
               SUM(CASE WHEN t.id IS NOT NULL AND t.end_time IS NULL THEN 1 ELSE 0 END) AS open_task_count,
               SUM((julianday(t.end_time) - julianday(t.start_time)) * 24.0) AS total_hours,
               MAX(COALESCE(t.end_time, t.start_time)) AS last_activity,
               (SELECT GROUP_CONCAT(tg.name, ', ')
                FROM job_tags jt JOIN tags tg ON tg.id = jt.tag_id
                WHERE jt.job_id = j.id) AS tags,
               (SELECT GROUP_CONCAT(DISTINCT tk.tech_name)
                FROM tasks tk WHERE tk.job_id = j.id AND tk.end_time IS NULL) AS on_location_techs,
               (SELECT GROUP_CONCAT(tk.notes, char(30))
                FROM tasks tk WHERE tk.job_id = j.id
                  AND tk.notes IS NOT NULL AND TRIM(tk.notes) <> '') AS task_notes_concat
        FROM jobs j
        LEFT JOIN tasks t ON t.job_id = j.id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY j.id
        ORDER BY COALESCE(MAX(COALESCE(t.end_time, t.start_time)), j.opened_at) DESC
    ");
    $stmt->execute($params);
    $jobs = $stmt->fetchAll();

    // Derive the two review flags. "job complete" needs the fuzzy PHP matcher,
    // so each task note is checked separately (notes are joined with an ASCII
    // record separator that can't appear in typed text, then split back apart).
    foreach ($jobs as &$job) {
        $job['on_location'] = !empty($job['on_location_techs']);
        $job['job_complete'] = false;
        if (!empty($job['task_notes_concat'])) {
            foreach (explode(chr(30), $job['task_notes_concat']) as $note) {
                if (taskNoteSaysComplete($note)) {
                    $job['job_complete'] = true;
                    break;
                }
            }
        }
    }
    unset($job);

    if ($flag === 'on_location') {
        $jobs = array_values(array_filter($jobs, fn($j) => $j['on_location']));
    } elseif ($flag === 'job_complete') {
        $jobs = array_values(array_filter($jobs, fn($j) => $j['job_complete']));
    }
    return $jobs;
}

// ---------------------------------------------------------------------------
// Tags (a curated, admin-managed vocabulary; many-to-many with jobs)
// ---------------------------------------------------------------------------

// The whole tag vocabulary, alphabetical
function getAllTags() {
    $pdo = getDbConnection();
    return $pdo->query("SELECT id, name FROM tags ORDER BY name COLLATE NOCASE")->fetchAll();
}

// Tags attached to one job (rows: id, name), alphabetical
function getTagsForJob($jobId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT tg.id, tg.name
        FROM job_tags jt JOIN tags tg ON tg.id = jt.tag_id
        WHERE jt.job_id = :id
        ORDER BY tg.name COLLATE NOCASE
    ");
    $stmt->execute(['id' => $jobId]);
    return $stmt->fetchAll();
}

// Replace a job's tags with the given tag ids. Does NOT create tags - the
// vocabulary is managed only through createTag/renameTag/deleteTag - so any
// id that isn't a real tag is silently ignored.
function setJobTags($jobId, array $tagIds) {
    $pdo = getDbConnection();
    $pdo->prepare("DELETE FROM job_tags WHERE job_id = :id")->execute(['id' => $jobId]);
    if (empty($tagIds)) {
        return;
    }
    // Keep only ids that exist, de-duplicated
    $ids = array_values(array_unique(array_map('intval', $tagIds)));
    $insert = $pdo->prepare("
        INSERT OR IGNORE INTO job_tags (job_id, tag_id)
        SELECT :job_id, :tag_id WHERE EXISTS (SELECT 1 FROM tags WHERE id = :tag_id)
    ");
    foreach ($ids as $tagId) {
        $insert->execute(['job_id' => $jobId, 'tag_id' => $tagId]);
    }
}

// Create a tag. Returns [id, message]; id is false if the name is empty or a
// case-insensitive duplicate of an existing tag.
function createTag($name) {
    $name = trim($name);
    if ($name === '') {
        return [false, 'Tag name is required'];
    }
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO tags (name) VALUES (:name)");
    $stmt->execute(['name' => $name]);
    if ($stmt->rowCount() === 0) {
        return [false, 'That tag already exists'];
    }
    return [(int)$pdo->lastInsertId(), 'Tag added'];
}

// Rename a tag. Returns [bool ok, string message].
function renameTag($id, $name) {
    $name = trim($name);
    if ($name === '') {
        return [false, 'Tag name is required'];
    }
    $pdo = getDbConnection();
    try {
        $stmt = $pdo->prepare("UPDATE tags SET name = :name WHERE id = :id");
        $stmt->execute(['name' => $name, 'id' => $id]);
    } catch (PDOException $e) {
        // NOCASE UNIQUE violation -> a tag by that name already exists
        return [false, 'That tag already exists'];
    }
    return [true, 'Tag renamed'];
}

// Delete a tag. Its job associations are removed by the FK cascade.
function deleteTag($id) {
    $pdo = getDbConnection();
    return $pdo->prepare("DELETE FROM tags WHERE id = :id")->execute(['id' => $id]);
}

// Jobs a tech may log tasks against: open/in-progress jobs plus the system job
function getOpenJobsForTech() {
    $pdo = getDbConnection();
    $placeholders = implode(',', array_fill(0, count(JOB_ACTIVE_STATUSES), '?'));
    $stmt = $pdo->prepare("
        SELECT j.id, j.name, j.is_system
        FROM jobs j
        WHERE j.is_system = 1 OR j.status IN ($placeholders)
        ORDER BY j.is_system DESC, j.name COLLATE NOCASE
    ");
    $stmt->execute(array_values(JOB_ACTIVE_STATUSES));
    return $stmt->fetchAll();
}

// True if the job can accept new tasks
function jobAcceptsTasks($job) {
    return $job && ($job['is_system'] || in_array($job['status'], JOB_ACTIVE_STATUSES, true));
}

// Update editable job fields (admin)
function updateJobFields($jobId, array $fields) {
    $allowed = ['name', 'customer_name', 'phone', 'email', 'call_notes', 'admin_notes', 'opened_at'];
    $setClauses = [];
    $params = ['id' => $jobId];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $fields)) {
            $setClauses[] = "$field = :$field";
            $params[$field] = trim($fields[$field] ?? '') ?: null;
        }
    }
    if (empty($setClauses)) {
        return false;
    }
    // Job name and opened_at are required (NOT NULL) - reject blanks
    if (array_key_exists('name', $params) && $params['name'] === null) {
        return false;
    }
    if (array_key_exists('opened_at', $params) && $params['opened_at'] === null) {
        return false;
    }
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("UPDATE jobs SET " . implode(', ', $setClauses) . " WHERE id = :id AND is_system = 0");
    return $stmt->execute($params);
}

// Transition a job to a new status, maintaining the status timestamps.
// Returns [bool success, string message].
function setJobStatus($jobId, $status) {
    if (!in_array($status, JOB_STATUSES, true)) {
        return [false, 'Invalid status'];
    }
    $job = getJobById($jobId);
    if (!$job) {
        return [false, 'Job not found'];
    }
    // The Clock in/out job is permanent: never billed, never closed
    if ($job['is_system']) {
        return [false, 'The Clock in/out job cannot change status'];
    }

    // Closing to new tasks requires all tasks to be finished (have an end time)
    if ($status === 'ready_for_billing') {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT tech_name, start_time FROM tasks WHERE job_id = :id AND end_time IS NULL");
        $stmt->execute(['id' => $jobId]);
        $openTasks = $stmt->fetchAll();
        if (!empty($openTasks)) {
            $list = implode(', ', array_map(function ($t) {
                return $t['tech_name'] . ' (started ' . date('M j g:i A', strtotime($t['start_time'])) . ')';
            }, $openTasks));
            return [false, "Tasks still in progress: $list. Add an end time (or delete the task) first."];
        }
    }

    $sets = ['status = :status'];
    $params = ['status' => $status, 'id' => $jobId];

    if ($status === 'closed') {
        // Closed is a side exit from any stage: stamp closed_at, leave the
        // billing timestamps as a historical record of how far it got.
        $sets[] = "closed_at = COALESCE(closed_at, :now)";
        $params['now'] = now();
    } else {
        // Moving to any non-closed status clears the closed marker.
        $sets[] = "closed_at = NULL";

        // Set the timestamp for the new status (if not already set) and clear
        // timestamps for any later statuses (handles moving backwards).
        $order = ['ready_for_billing' => 'ready_for_billing_at', 'billed' => 'billed_at', 'paid' => 'paid_at'];
        $reached = false;
        foreach ($order as $st => $col) {
            if ($reached) {
                $sets[] = "$col = NULL";
            } elseif ($st === $status) {
                $sets[] = "$col = COALESCE($col, :now)";
                $params['now'] = now();
                $reached = true;
            }
        }
        if (!$reached) {
            // open / in_progress / on_hold: clear all completion timestamps
            $sets[] = "ready_for_billing_at = NULL";
            $sets[] = "billed_at = NULL";
            $sets[] = "paid_at = NULL";
        }
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare("UPDATE jobs SET " . implode(', ', $sets) . " WHERE id = :id");
    $stmt->execute($params);
    return [true, 'Status updated'];
}

// Delete a job and all of its tasks
function deleteJob($jobId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("DELETE FROM jobs WHERE id = :id AND is_system = 0");
    return $stmt->execute(['id' => $jobId]);
}

// Known customers and locations for the call-log autocomplete.
// Customers carry their most recent location, phone, and email so the form
// can prefill them. Locations are derived from job names ("Customer - Location").
function getCallSuggestions() {
    $pdo = getDbConnection();
    $rows = $pdo->query("
        SELECT name, customer_name, phone, email FROM jobs
        WHERE is_system = 0 ORDER BY opened_at
    ")->fetchAll();
    $customers = [];
    $locations = [];
    foreach ($rows as $row) {
        $customer = trim($row['customer_name'] ?? '');
        $name = trim($row['name']);
        if ($customer !== '') {
            $location = '';
            $prefix = $customer . ' - ';
            if (strpos($name, $prefix) === 0) {
                $location = trim(substr($name, strlen($prefix)));
            }
            // Rows are oldest-first, so later jobs overwrite: latest details win
            $key = mb_strtolower($customer);
            $customers[$key] = [
                'name' => $customer,
                'location' => $location ?: ($customers[$key]['location'] ?? ''),
                'phone' => trim($row['phone'] ?? '') ?: ($customers[$key]['phone'] ?? ''),
                'email' => trim($row['email'] ?? '') ?: ($customers[$key]['email'] ?? ''),
            ];
            if ($location !== '') {
                $locations[$location] = true;
            }
        } else {
            // Legacy migrated jobs: the whole name is the location
            $locations[$name] = true;
        }
    }
    $customers = array_values($customers);
    usort($customers, function ($a, $b) {
        return strnatcasecmp($a['name'], $b['name']);
    });
    ksort($locations, SORT_NATURAL | SORT_FLAG_CASE);
    return ['customers' => $customers, 'locations' => array_keys($locations)];
}

// ---------------------------------------------------------------------------
// Tasks
// ---------------------------------------------------------------------------

// Start a new task on a job. Returns [taskId|false, message].
// $clientUuid makes the call idempotent (offline replay can retry safely).
// $allowClosed accepts the task into a closed job - used for work queued
// offline before the job was marked ready for billing; admin cleans up later.
function createTask($jobId, $techName, $startTime, $clientUuid = null, $allowClosed = false) {
    $pdo = getDbConnection();

    // Replayed request that already succeeded? Return the existing task.
    if ($clientUuid !== null) {
        $existing = getTaskByClientUuid($clientUuid);
        if ($existing) {
            return [(int)$existing['id'], 'Task already synced'];
        }
    }

    $job = getJobById($jobId);
    if (!$job || (!$allowClosed && !jobAcceptsTasks($job))) {
        return [false, 'That job is not open for new tasks'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO tasks (job_id, created_at, tech_name, start_time, client_uuid)
        VALUES (:job_id, :created_at, :tech_name, :start_time, :client_uuid)
    ");
    $stmt->execute([
        'job_id' => $jobId,
        'created_at' => now(),
        'tech_name' => trim($techName),
        'start_time' => $startTime,
        'client_uuid' => $clientUuid,
    ]);
    $taskId = (int)$pdo->lastInsertId();

    // First task moves an open job to in progress
    if (!$job['is_system'] && $job['status'] === 'open') {
        $pdo->prepare("UPDATE jobs SET status = 'in_progress' WHERE id = :id")->execute(['id' => $jobId]);
    }

    return [$taskId, 'Task started'];
}

// Look up a task by its client-generated UUID (offline-created tasks are
// referenced by UUID because the client never saw the server id)
function getTaskByClientUuid($clientUuid) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT t.*, j.name AS job_name, j.is_system AS job_is_system
        FROM tasks t JOIN jobs j ON j.id = t.job_id
        WHERE t.client_uuid = :uuid
    ");
    $stmt->execute(['uuid' => $clientUuid]);
    return $stmt->fetch() ?: null;
}

function getTaskById($taskId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT t.*, j.name AS job_name, j.is_system AS job_is_system
        FROM tasks t JOIN jobs j ON j.id = t.job_id
        WHERE t.id = :id
    ");
    $stmt->execute(['id' => $taskId]);
    return $stmt->fetch() ?: null;
}

// In-progress (no end time yet) tasks for a tech
function getInProgressTasks($techName) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT t.id, t.start_time, j.name AS job_name
        FROM tasks t JOIN jobs j ON j.id = t.job_id
        WHERE t.tech_name = :tech_name AND t.end_time IS NULL
        ORDER BY t.start_time DESC
    ");
    $stmt->execute(['tech_name' => $techName]);
    return $stmt->fetchAll();
}

// Latest tasks for a tech (history view)
function getLatestTasks($techName, $limit = 20) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT t.id, t.start_time, t.end_time, t.notes, j.name AS job_name
        FROM tasks t JOIN jobs j ON j.id = t.job_id
        WHERE t.tech_name = :tech_name
        ORDER BY
            CASE WHEN t.end_time IS NULL THEN 0 ELSE 1 END,
            COALESCE(t.end_time, t.start_time) DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':tech_name', $techName, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// Complete/close a task
function completeTask($taskId, $endTime, $notes) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        UPDATE tasks
        SET end_time = :end_time, notes = :notes, closed_at = :closed_at
        WHERE id = :id
    ");
    return $stmt->execute([
        'end_time' => $endTime,
        'notes' => $notes !== null ? trim($notes) : null,
        'closed_at' => now(),
        'id' => $taskId,
    ]);
}

// Partially update a task (only provided fields)
function updateTaskPartial($taskId, $fields) {
    $allowed = ['start_time', 'end_time', 'notes', 'tech_name', 'closed_at'];
    $setClauses = [];
    $params = ['id' => $taskId];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $fields) && $fields[$field] !== null) {
            $setClauses[] = "$field = :$field";
            $params[$field] = is_string($fields[$field]) && $field !== 'notes'
                ? trim($fields[$field]) : $fields[$field];
        }
    }
    if (empty($setClauses)) {
        return false;
    }
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("UPDATE tasks SET " . implode(', ', $setClauses) . " WHERE id = :id");
    return $stmt->execute($params);
}

function deleteTask($taskId) {
    $pdo = getDbConnection();
    return $pdo->prepare("DELETE FROM tasks WHERE id = :id")->execute(['id' => $taskId]);
}

// All tasks for a job, newest first (job timeline is reverse chronological)
function getTasksForJob($jobId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT *, (julianday(end_time) - julianday(start_time)) * 24.0 AS hours
        FROM tasks
        WHERE job_id = :job_id
        ORDER BY start_time DESC
    ");
    $stmt->execute(['job_id' => $jobId]);
    return $stmt->fetchAll();
}

// Clock in/out entries (tasks on the system job), optionally filtered by
// tech and/or month, newest first
function getClockTasks($tech = '', $month = '') {
    $where = ['j.is_system = 1'];
    $params = [];
    if ($tech !== '') {
        $where[] = 't.tech_name = :tech';
        $params['tech'] = $tech;
    }
    if ($month !== '') {
        $where[] = "strftime('%Y-%m', t.start_time) = :month";
        $params['month'] = $month;
    }
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT t.*, (julianday(t.end_time) - julianday(t.start_time)) * 24.0 AS hours
        FROM tasks t JOIN jobs j ON j.id = t.job_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.start_time DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Months ('YYYY-MM') that have clock in/out entries, newest first
function getClockTaskMonths() {
    $pdo = getDbConnection();
    return $pdo->query("
        SELECT DISTINCT strftime('%Y-%m', t.start_time) AS month
        FROM tasks t JOIN jobs j ON j.id = t.job_id
        WHERE j.is_system = 1
        ORDER BY month DESC
    ")->fetchAll(PDO::FETCH_COLUMN);
}

// ---------------------------------------------------------------------------
// Job export (full detail: job fields + every task + summary). Shared by the
// admin JSON/text export and the completed-jobs endpoint.
// ---------------------------------------------------------------------------

function jobExportData($jobId) {
    $job = getJobById($jobId);
    if (!$job) {
        return null;
    }
    $tasks = getTasksForJob($jobId);
    $totalHours = 0;
    foreach ($tasks as $task) {
        $totalHours += (float)($task['hours'] ?? 0);
    }
    $tags = array_column(getTagsForJob($jobId), 'name');
    return [
        'job' => [
            'id' => (int)$job['id'],
            'name' => $job['name'],
            'status' => $job['status'],
            'status_label' => STATUS_LABELS[$job['status']] ?? $job['status'],
            'customer_name' => $job['customer_name'],
            'phone' => $job['phone'],
            'email' => $job['email'],
            'tags' => $tags,
            'call_notes' => $job['call_notes'],
            'admin_notes' => $job['admin_notes'],
            'opened_at' => $job['opened_at'],
            'ready_for_billing_at' => $job['ready_for_billing_at'],
            'billed_at' => $job['billed_at'],
            'paid_at' => $job['paid_at'],
            'closed_at' => $job['closed_at'],
        ],
        'tasks' => array_map(function ($task) {
            return [
                'tech_name' => $task['tech_name'],
                'start_time' => $task['start_time'],
                'end_time' => $task['end_time'],
                'hours' => $task['hours'] !== null ? round((float)$task['hours'], 2) : null,
                'notes' => $task['notes'],
            ];
        }, $tasks),
        'summary' => [
            'task_count' => count($tasks),
            'total_hours' => round($totalHours, 2),
        ],
    ];
}

// ---------------------------------------------------------------------------
// "Job complete" detection (techs flag a finished job in their task notes)
// ---------------------------------------------------------------------------

// Fuzzy check: does a task note say the job is complete? Techs jot something
// like "- job complete". This tolerates:
//   - case            ("Job Complete", "JOB COMPLETE")
//   - punctuation     ("- job complete", "job/complete", "job. completed")
//   - spacing / run-on ("jobcomplete", "jobcompletd")
//   - minor spelling  ("job complet", "job completd", "completed the job")
// It requires BOTH a "job" word and a "complete"-like word close together so
// unrelated notes that merely mention "complete" - or say "incomplete", the
// opposite - don't match.
function taskNoteSaysComplete($notes) {
    if ($notes === null) {
        return false;
    }
    $lower = strtolower($notes);

    // Punctuation/space-insensitive form catches run-together and punctuated
    // variants: "- job complete", "jobcomplete", "job.completed" all collapse
    // to a string containing "jobcomplet".
    $collapsed = preg_replace('/[^a-z]/', '', $lower);
    if ($collapsed !== '' && strpos($collapsed, 'jobcomplet') !== false) {
        return true;
    }

    // Word-based fuzzy match: handles spelling and word order (e.g.
    // "completed the job") by pairing a "job" word with a "complete"-like word
    // within a few positions.
    $words = preg_split('/[^a-z]+/', $lower, -1, PREG_SPLIT_NO_EMPTY);
    $jobAt = [];
    $doneAt = [];
    foreach ($words as $i => $w) {
        if ($w === 'job' || $w === 'jobs') {
            $jobAt[] = $i;
            continue;
        }
        if (strpos($w, 'incompl') !== false) {
            continue; // "incomplete" is the opposite of done
        }
        if (strncmp($w, 'compl', 5) === 0 || levenshtein($w, 'complete') <= 2) {
            $doneAt[] = $i;
        }
    }
    foreach ($jobAt as $j) {
        foreach ($doneAt as $d) {
            if (abs($d - $j) <= 3) {
                return true;
            }
        }
    }
    return false;
}

// Jobs the admin has moved to "ready for billing" after $since ('Y-m-d H:i:s',
// compared against ready_for_billing_at), newest first. These are the jobs the
// admin has reviewed and approved for invoicing (after seeing the tech's "job
// complete" flag on the active list). Each record is the full jobExportData()
// shape (job fields + every task + summary); the tech's completion note is
// visible within the tasks. Powers the completed-jobs.php billing feed.
function getReadyForBillingJobsSince($since) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT id FROM jobs
        WHERE is_system = 0
          AND status = 'ready_for_billing'
          AND ready_for_billing_at > :since
        ORDER BY ready_for_billing_at DESC
    ");
    $stmt->execute(['since' => $since]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $jid) {
        $out[] = jobExportData((int)$jid);
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Reports
// ---------------------------------------------------------------------------

// Jobs completed (reached ready for billing) per month, with status breakdown
function reportJobsPerMonth() {
    $pdo = getDbConnection();
    return $pdo->query("
        SELECT month,
               COUNT(*) AS job_count,
               SUM(CASE WHEN status = 'ready_for_billing' THEN 1 ELSE 0 END) AS ready_count,
               SUM(CASE WHEN status = 'billed' THEN 1 ELSE 0 END) AS billed_count,
               SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
               SUM(hours) AS total_hours
        FROM (
            SELECT j.id, j.status,
                   strftime('%Y-%m', j.ready_for_billing_at) AS month,
                   (SELECT SUM((julianday(t.end_time) - julianday(t.start_time)) * 24.0)
                    FROM tasks t WHERE t.job_id = j.id) AS hours
            FROM jobs j
            WHERE j.is_system = 0 AND j.ready_for_billing_at IS NOT NULL
        )
        GROUP BY month
        ORDER BY month DESC
    ")->fetchAll();
}

// Jobs completed in a given month ('YYYY-MM')
function reportJobsForMonth($month) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT j.*,
               (SELECT COUNT(*) FROM tasks t WHERE t.job_id = j.id) AS task_count,
               (SELECT SUM((julianday(t.end_time) - julianday(t.start_time)) * 24.0)
                FROM tasks t WHERE t.job_id = j.id) AS total_hours
        FROM jobs j
        WHERE j.is_system = 0 AND strftime('%Y-%m', j.ready_for_billing_at) = :month
        ORDER BY j.ready_for_billing_at DESC
    ");
    $stmt->execute(['month' => $month]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Customer lookup
// ---------------------------------------------------------------------------

// SQL expression for a job's customer identity: the customer_name, or the
// job name for legacy jobs that predate the customer_name field.
const CUSTOMER_EXPR = "COALESCE(NULLIF(TRIM(customer_name), ''), name)";

// Customers matching a fuzzy query (each whitespace-separated token must
// appear, in any order). Empty query returns all customers.
function searchCustomers($query) {
    $pdo = getDbConnection();
    $expr = CUSTOMER_EXPR;
    $where = ['is_system = 0'];
    $params = [];
    $tokens = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($tokens as $i => $token) {
        $where[] = "LOWER($expr) LIKE :tok$i";
        $params["tok$i"] = '%' . mb_strtolower($token) . '%';
    }
    $stmt = $pdo->prepare("
        SELECT $expr AS customer,
               COUNT(*) AS job_count,
               MAX(opened_at) AS last_opened
        FROM jobs
        WHERE " . implode(' AND ', $where) . "
        GROUP BY customer
        ORDER BY customer COLLATE NOCASE
        LIMIT 50
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// All jobs for one customer (by the customer identity expression), newest
// first, with task aggregates.
function getJobsForCustomer($customer) {
    $pdo = getDbConnection();
    $expr = CUSTOMER_EXPR;
    $stmt = $pdo->prepare("
        SELECT j.*,
               (SELECT COUNT(*) FROM tasks t WHERE t.job_id = j.id) AS task_count,
               (SELECT SUM((julianday(t.end_time) - julianday(t.start_time)) * 24.0)
                FROM tasks t WHERE t.job_id = j.id) AS total_hours
        FROM jobs j
        WHERE j.is_system = 0 AND $expr = :customer
        ORDER BY j.opened_at DESC
    ");
    $stmt->execute(['customer' => $customer]);
    return $stmt->fetchAll();
}

// Months ('YYYY-MM') that have any tasks, newest first
function getTaskMonths() {
    $pdo = getDbConnection();
    return $pdo->query("
        SELECT DISTINCT strftime('%Y-%m', start_time) AS month
        FROM tasks ORDER BY month DESC
    ")->fetchAll(PDO::FETCH_COLUMN);
}

// All tech names that have logged tasks
function getTechNames() {
    $pdo = getDbConnection();
    return $pdo->query("SELECT DISTINCT tech_name FROM tasks ORDER BY tech_name")->fetchAll(PDO::FETCH_COLUMN);
}

// Tasks for a tech in a given month ('YYYY-MM'), with job names
function reportTasksForTech($techName, $month) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT t.*, j.name AS job_name, j.is_system AS job_is_system,
               (julianday(t.end_time) - julianday(t.start_time)) * 24.0 AS hours
        FROM tasks t JOIN jobs j ON j.id = t.job_id
        WHERE t.tech_name = :tech_name AND strftime('%Y-%m', t.start_time) = :month
        ORDER BY t.start_time
    ");
    $stmt->execute(['tech_name' => $techName, 'month' => $month]);
    return $stmt->fetchAll();
}

// All tasks whose start date falls within [$startDate, $endDate] (inclusive,
// 'YYYY-MM-DD'), across every tech and job (including clock in/out), with job
// names, oldest first.
function reportTasksByDateRange($startDate, $endDate) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT t.*, j.name AS job_name, j.is_system AS job_is_system,
               (julianday(t.end_time) - julianday(t.start_time)) * 24.0 AS hours
        FROM tasks t JOIN jobs j ON j.id = t.job_id
        WHERE date(t.start_time) BETWEEN :start AND :end
        ORDER BY t.start_time
    ");
    $stmt->execute(['start' => $startDate, 'end' => $endDate]);
    return $stmt->fetchAll();
}
