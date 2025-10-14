<?php
// database.php - SQLite database operations

// Database file path
define('DB_FILE', __DIR__ . '/handymanager.db');

// Initialize database connection
function getDbConnection() {
    try {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        throw $e;
    }
}

// Initialize database schema
function initDatabase() {
    $pdo = getDbConnection();

    $sql = "
        CREATE TABLE IF NOT EXISTS jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            tech_name TEXT NOT NULL,
            start_time TEXT NOT NULL,
            location TEXT NOT NULL,
            end_time TEXT,
            notes TEXT,
            closed_at TEXT
        );
    ";

    $pdo->exec($sql);
}

// Function to get all in-progress jobs for a tech (where closed_at is null)
function getInProgressJobs($techName) {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("
        SELECT id, start_time, location
        FROM jobs
        WHERE tech_name = :tech_name AND closed_at IS NULL
        ORDER BY start_time DESC
    ");

    $stmt->execute(['tech_name' => $techName]);
    return $stmt->fetchAll();
}

// Function to get the latest 10 jobs for a tech (including in-progress jobs)
function getLatestJobs($techName, $limit = 20) {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("
        SELECT id, start_time, end_time, location, notes
        FROM jobs
        WHERE tech_name = :tech_name
        ORDER BY
        CASE WHEN end_time IS NULL THEN 0 ELSE 1 END,  -- Jobs without end_time first
            CASE
                WHEN end_time IS NULL THEN start_time  -- Sort jobs without end_time by start_time
                ELSE end_time  -- Sort jobs with end_time by end_time
            END DESC
        LIMIT :limit
    ");

    $stmt->bindValue(':tech_name', $techName, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

// Function to get a job by ID
function getJobById($jobId) {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("
        SELECT id, start_time, end_time, location, notes
        FROM jobs
        WHERE id = :job_id
    ");

    $stmt->execute(['job_id' => $jobId]);
    return $stmt->fetch();
}

// Function to update a job
function updateJob($jobId, $startTime, $endTime, $location, $notes) {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("
        UPDATE jobs
        SET start_time = :start_time, end_time = :end_time, location = :location, notes = :notes
        WHERE id = :job_id
    ");

    return $stmt->execute([
        'start_time' => $startTime,
        'end_time' => $endTime,
        'location' => trim($location),
        'notes' => $notes,
        'job_id' => $jobId
    ]);
}

// Function to partially update a job (only specific fields)
function updateJobPartial($jobId, $location = null, $notes = null, $start_time = null, $end_time = null, $tech_name = null) {
    $pdo = getDbConnection();

    $setClauses = [];
    $params = ['job_id' => $jobId];

    if ($location !== null) {
        $setClauses[] = "location = :location";
        $params['location'] = trim($location);
    }

    if ($notes !== null) {
        $setClauses[] = "notes = :notes";
        $params['notes'] = $notes;
    }

    if ($start_time !== null) {
        $setClauses[] = "start_time = :start_time";
        $params['start_time'] = $start_time;
    }

    if ($end_time !== null) {
        $setClauses[] = "end_time = :end_time";
        $params['end_time'] = $end_time;
    }

    if ($tech_name !== null) {
        $setClauses[] = "tech_name = :tech_name";
        $params['tech_name'] = trim($tech_name);
    }

    if (empty($setClauses)) {
        return false; // Nothing to update
    }

    $sql = "UPDATE jobs SET " . implode(", ", $setClauses) . " WHERE id = :job_id";
    $stmt = $pdo->prepare($sql);

    return $stmt->execute($params);
}

// Function to create a new job
function createJob($techName, $startTime, $location) {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("
        INSERT INTO jobs (created_at, tech_name, start_time, location)
        VALUES (:created_at, :tech_name, :start_time, :location)
    ");

    return $stmt->execute([
        'created_at' => date('Y-m-d H:i:s'),
        'tech_name' => trim($techName),
        'start_time' => $startTime,
        'location' => trim($location)
    ]);
}

// Function to complete/close a job
function completeJob($jobId, $endTime, $notes) {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("
        UPDATE jobs
        SET end_time = :end_time, notes = :notes, closed_at = :closed_at
        WHERE id = :job_id
    ");

    return $stmt->execute([
        'end_time' => $endTime,
        'notes' => trim($notes),
        'closed_at' => date('Y-m-d H:i:s'),
        'job_id' => $jobId
    ]);
}

// Function to get all jobs with filtering and sorting
function getAllJobs($filters = []) {
    $pdo = getDbConnection();

    $whereClause = "";
    $params = [];

    // Apply filters if provided
    if (!empty($filters)) {
        $whereConditions = [];

        if (!empty($filters['date_from'])) {
            $whereConditions[] = "DATE(start_time) >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $whereConditions[] = "DATE(start_time) <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        if (!empty($filters['tech'])) {
            $whereConditions[] = "tech_name = :tech";
            $params['tech'] = trim($filters['tech']);
        }

        if (!empty($filters['location'])) {
            if (is_array($filters['location'])) {
                // Handle multiple locations filter
                $locationPlaceholders = [];
                foreach ($filters['location'] as $index => $location) {
                    $locationPlaceholders[] = ":location_$index";
                    $params["location_$index"] = trim($location);
                }
                $whereConditions[] = "location IN (" . implode(',', $locationPlaceholders) . ")";
            } else {
                // Handle single location filter
                $whereConditions[] = "location = :location";
                $params['location'] = trim($filters['location']);
            }
        }

        if (!empty($whereConditions)) {
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
        }
    }

    // Sort by:
    // 1. Jobs without end_time at the top
    // 2. Then jobs with end_time, newest first
    // 3. For jobs without end_time, sort by start_time, newest first
    $sql = "
        SELECT id, start_time, end_time, tech_name, location, notes
        FROM jobs
        $whereClause
        ORDER BY
            CASE WHEN end_time IS NULL THEN 0 ELSE 1 END,  -- Jobs without end_time first
            CASE
                WHEN end_time IS NULL THEN start_time  -- Sort jobs without end_time by start_time
                ELSE end_time  -- Sort jobs with end_time by end_time
            END DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to get unique techs and locations for filter dropdowns
function getFilterOptions() {
    $pdo = getDbConnection();

    // Get unique techs
    $stmt = $pdo->query("SELECT DISTINCT tech_name FROM jobs WHERE tech_name IS NOT NULL ORDER BY tech_name");
    $techs = array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN));

    // Get unique locations
    $stmt = $pdo->query("SELECT DISTINCT location FROM jobs WHERE location IS NOT NULL ORDER BY location");
    $locations = array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN));

    return ['techs' => $techs, 'locations' => $locations];
}

// Function to delete a job by ID
function deleteJob($jobId) {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("
        DELETE FROM jobs
        WHERE id = :job_id
    ");

    return $stmt->execute(['job_id' => $jobId]);
}

// Function to migrate existing JSON data to SQLite (if needed)
function migrateFromJson() {
    $jsonFile = __DIR__ . '/handymanager-data.json';

    if (!file_exists($jsonFile)) {
        return;
    }

    $jsonData = json_decode(file_get_contents($jsonFile), true);

    if (!$jsonData || !isset($jsonData['jobs']) || empty($jsonData['jobs'])) {
        return;
    }

    $pdo = getDbConnection();

    // Begin transaction for better performance
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            INSERT OR IGNORE INTO jobs
            (id, created_at, tech_name, start_time, location, end_time, notes, closed_at)
            VALUES (:id, :created_at, :tech_name, :start_time, :location, :end_time, :notes, :closed_at)
        ");

        foreach ($jsonData['jobs'] as $job) {
            $stmt->execute([
                'id' => $job['id'] ?? null,
                'created_at' => $job['created_at'] ?? null,
                'tech_name' => isset($job['tech_name']) ? trim($job['tech_name']) : null,
                'start_time' => $job['start_time'] ?? null,
                'location' => isset($job['location']) ? trim($job['location']) : null,
                'end_time' => $job['end_time'] ?? null,
                'notes' => isset($job['notes']) ? trim($job['notes']) : null,
                'closed_at' => $job['closed_at'] ?? null
            ]);
        }

        $pdo->commit();

        // Optionally rename the old JSON file as backup
        rename($jsonFile, $jsonFile . '.backup');
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Migration failed: " . $e->getMessage());
        throw $e;
    }
}
?>