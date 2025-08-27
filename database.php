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
            rep_name TEXT NOT NULL,
            start_time TEXT NOT NULL,
            location TEXT NOT NULL,
            end_time TEXT,
            notes TEXT,
            closed_at TEXT
        );
    ";
    
    $pdo->exec($sql);
}

// Function to get all in-progress jobs for a rep (where closed_at is null)
function getInProgressJobs($repName) {
    $pdo = getDbConnection();
    
    $stmt = $pdo->prepare("
        SELECT id, start_time, location 
        FROM jobs 
        WHERE rep_name = :rep_name AND closed_at IS NULL
        ORDER BY start_time DESC
    ");
    
    $stmt->execute(['rep_name' => $repName]);
    return $stmt->fetchAll();
}

// Function to create a new job
function createJob($repName, $startTime, $location) {
    $pdo = getDbConnection();
    
    $stmt = $pdo->prepare("
        INSERT INTO jobs (created_at, rep_name, start_time, location)
        VALUES (:created_at, :rep_name, :start_time, :location)
    ");
    
    return $stmt->execute([
        'created_at' => date('Y-m-d H:i:s'),
        'rep_name' => $repName,
        'start_time' => $startTime,
        'location' => $location
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
        'notes' => $notes,
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
        
        if (!empty($filters['rep'])) {
            $whereConditions[] = "rep_name = :rep";
            $params['rep'] = $filters['rep'];
        }
        
        if (!empty($filters['location'])) {
            $whereConditions[] = "location = :location";
            $params['location'] = $filters['location'];
        }
        
        if (!empty($whereConditions)) {
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
        }
    }
    
    // Sort by closed_at DESC (nulls first for in-progress jobs) then by start_time DESC
    $sql = "
        SELECT start_time, end_time, rep_name, location, notes
        FROM jobs
        $whereClause
        ORDER BY 
            CASE WHEN closed_at IS NULL THEN 0 ELSE 1 END,
            COALESCE(closed_at, start_time) DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to get unique reps and locations for filter dropdowns
function getFilterOptions() {
    $pdo = getDbConnection();
    
    // Get unique reps
    $stmt = $pdo->query("SELECT DISTINCT rep_name FROM jobs WHERE rep_name IS NOT NULL ORDER BY rep_name");
    $reps = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get unique locations
    $stmt = $pdo->query("SELECT DISTINCT location FROM jobs WHERE location IS NOT NULL ORDER BY location");
    $locations = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    return ['reps' => $reps, 'locations' => $locations];
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
            (id, created_at, rep_name, start_time, location, end_time, notes, closed_at)
            VALUES (:id, :created_at, :rep_name, :start_time, :location, :end_time, :notes, :closed_at)
        ");
        
        foreach ($jsonData['jobs'] as $job) {
            $stmt->execute([
                'created_at' => $job['created_at'] ?? null,
                'rep_name' => $job['rep_name'] ?? null,
                'start_time' => $job['start_time'] ?? null,
                'location' => $job['location'] ?? null,
                'end_time' => $job['end_time'] ?? null,
                'notes' => $job['notes'] ?? null,
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