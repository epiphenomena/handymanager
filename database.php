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
const JOB_STATUSES = ['open', 'in_progress', 'ready_for_billing', 'billed', 'paid'];
// Statuses that accept new tasks (and appear in the tech autocomplete)
const JOB_ACTIVE_STATUSES = ['open', 'in_progress'];

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
    }
}

// ---------------------------------------------------------------------------
// Jobs
// ---------------------------------------------------------------------------

// Open a new job from a service call
function createJob($name, $customerName, $phone, $callNotes) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        INSERT INTO jobs (name, customer_name, phone, call_notes, status, opened_at)
        VALUES (:name, :customer_name, :phone, :call_notes, 'open', :opened_at)
    ");
    $stmt->execute([
        'name' => trim($name),
        'customer_name' => trim($customerName ?? '') ?: null,
        'phone' => trim($phone ?? '') ?: null,
        'call_notes' => trim($callNotes ?? '') ?: null,
        'opened_at' => now(),
    ]);
    return (int)$pdo->lastInsertId();
}

function getJobById($jobId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = :id");
    $stmt->execute(['id' => $jobId]);
    return $stmt->fetch() ?: null;
}

// Jobs (non-system) in the given statuses, with task aggregates
function getJobsByStatus(array $statuses) {
    $pdo = getDbConnection();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = $pdo->prepare("
        SELECT j.*,
               COUNT(t.id) AS task_count,
               SUM(CASE WHEN t.closed_at IS NULL THEN 1 ELSE 0 END) AS open_task_count,
               SUM((julianday(t.end_time) - julianday(t.start_time)) * 24.0) AS total_hours,
               MAX(COALESCE(t.end_time, t.start_time)) AS last_activity
        FROM jobs j
        LEFT JOIN tasks t ON t.job_id = j.id
        WHERE j.is_system = 0 AND j.status IN ($placeholders)
        GROUP BY j.id
        ORDER BY COALESCE(MAX(COALESCE(t.end_time, t.start_time)), j.opened_at) DESC
    ");
    $stmt->execute(array_values($statuses));
    return $stmt->fetchAll();
}

// Jobs a tech may log tasks against: open/in-progress jobs plus the system job
function getOpenJobsForTech() {
    $pdo = getDbConnection();
    $placeholders = implode(',', array_fill(0, count(JOB_ACTIVE_STATUSES), '?'));
    $stmt = $pdo->prepare("
        SELECT j.id, j.name, j.is_system
        FROM jobs j
        WHERE j.is_system = 1 OR j.status IN ($placeholders)
        ORDER BY j.is_system DESC,
                 (SELECT MAX(t.start_time) FROM tasks t WHERE t.job_id = j.id) DESC,
                 j.opened_at DESC
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
    $allowed = ['name', 'customer_name', 'phone', 'call_notes', 'admin_notes'];
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
    // Job name is required
    if (array_key_exists('name', $params) && $params['name'] === null) {
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
    if (!$job || $job['is_system']) {
        return [false, 'Job not found'];
    }

    // Closing to new tasks requires all tasks to be finished
    if ($status === 'ready_for_billing') {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE job_id = :id AND closed_at IS NULL");
        $stmt->execute(['id' => $jobId]);
        if ((int)$stmt->fetchColumn() > 0) {
            return [false, 'This job has tasks still in progress'];
        }
    }

    // Set the timestamp for the new status (if not already set) and clear
    // timestamps for any later statuses (handles moving backwards).
    $order = ['ready_for_billing' => 'ready_for_billing_at', 'billed' => 'billed_at', 'paid' => 'paid_at'];
    $sets = ['status = :status'];
    $params = ['status' => $status, 'id' => $jobId];
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
    if (!$reached && in_array($status, JOB_ACTIVE_STATUSES, true)) {
        // Reopening: clear all completion timestamps
        $sets[] = "ready_for_billing_at = NULL";
        $sets[] = "billed_at = NULL";
        $sets[] = "paid_at = NULL";
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

// ---------------------------------------------------------------------------
// Tasks
// ---------------------------------------------------------------------------

// Start a new task on a job. Returns [taskId|false, message].
function createTask($jobId, $techName, $startTime) {
    $job = getJobById($jobId);
    if (!jobAcceptsTasks($job)) {
        return [false, 'That job is not open for new tasks'];
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        INSERT INTO tasks (job_id, created_at, tech_name, start_time)
        VALUES (:job_id, :created_at, :tech_name, :start_time)
    ");
    $stmt->execute([
        'job_id' => $jobId,
        'created_at' => now(),
        'tech_name' => trim($techName),
        'start_time' => $startTime,
    ]);
    $taskId = (int)$pdo->lastInsertId();

    // First task moves an open job to in progress
    if (!$job['is_system'] && $job['status'] === 'open') {
        $pdo->prepare("UPDATE jobs SET status = 'in_progress' WHERE id = :id")->execute(['id' => $jobId]);
    }

    return [$taskId, 'Task started'];
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

// In-progress (not yet completed) tasks for a tech
function getInProgressTasks($techName) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT t.id, t.start_time, j.name AS job_name
        FROM tasks t JOIN jobs j ON j.id = t.job_id
        WHERE t.tech_name = :tech_name AND t.closed_at IS NULL
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
    $allowed = ['start_time', 'end_time', 'notes', 'tech_name'];
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

// All tasks for a job, oldest first (job timeline)
function getTasksForJob($jobId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT *, (julianday(end_time) - julianday(start_time)) * 24.0 AS hours
        FROM tasks
        WHERE job_id = :job_id
        ORDER BY start_time
    ");
    $stmt->execute(['job_id' => $jobId]);
    return $stmt->fetchAll();
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
        ORDER BY j.ready_for_billing_at
    ");
    $stmt->execute(['month' => $month]);
    return $stmt->fetchAll();
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
