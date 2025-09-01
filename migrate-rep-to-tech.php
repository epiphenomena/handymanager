<?php
// migrate-rep-to-tech.php - Database migration script to rename rep_name to tech_name
//
// Usage:
//   php migrate-rep-to-tech.php
//
// This script migrates the database schema from the old structure using 'rep_name'
// to the new structure using 'tech_name'. It should be run once after updating
// the application code to use the new schema.
//
// WARNING: Always backup your database before running this migration!
//

require_once 'config.php';

echo "Database Migration: rep_name to tech_name\n";
echo "=========================================\n\n";

echo "WARNING: This script will modify your database!\n";
echo "Please ensure you have a backup before proceeding.\n\n";

// Check if running from command line
if (php_sapi_name() !== 'cli') {
    echo "This script should be run from the command line.\n";
    echo "Usage: php migrate-rep-to-tech.php\n";
    exit(1);
}

// Ask for confirmation
echo "Do you want to proceed with the migration? (type 'yes' to confirm): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim($line) !== 'yes') {
    echo "Migration cancelled.\n";
    exit(0);
}

echo "\nStarting database migration from rep_name to tech_name...\n";

try {
    $pdo = getDbConnection();
    
    // Check if the old column exists
    $stmt = $pdo->prepare("PRAGMA table_info(jobs)");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $hasRepName = false;
    $hasTechName = false;
    
    foreach ($columns as $column) {
        if ($column['name'] === 'rep_name') {
            $hasRepName = true;
        }
        if ($column['name'] === 'tech_name') {
            $hasTechName = true;
        }
    }
    
    if (!$hasRepName) {
        echo "Column 'rep_name' not found. Migration may have already been performed or database is not in expected state.\n";
        exit(1);
    }
    
    if ($hasTechName) {
        echo "Column 'tech_name' already exists. Migration may have already been performed.\n";
        exit(1);
    }
    
    // Begin transaction
    $pdo->beginTransaction();
    
    echo "Renaming rep_name column to tech_name...\n";
    
    // SQLite doesn't support direct column renaming, so we need to:
    // 1. Create a new table with the updated schema
    // 2. Copy data from the old table to the new table
    // 3. Drop the old table
    // 4. Rename the new table to the original name
    
    // Step 1: Create new table with tech_name
    $sql = "
        CREATE TABLE jobs_new (
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
    
    echo "Created new table with tech_name column...\n";
    
    // Step 2: Copy data from old table to new table
    $sql = "
        INSERT INTO jobs_new (id, created_at, tech_name, start_time, location, end_time, notes, closed_at)
        SELECT id, created_at, rep_name, start_time, location, end_time, notes, closed_at
        FROM jobs;
    ";
    $pdo->exec($sql);
    
    echo "Copied data from jobs to jobs_new...\n";
    
    // Step 3: Drop the old table
    $pdo->exec("DROP TABLE jobs;");
    
    echo "Dropped old jobs table...\n";
    
    // Step 4: Rename the new table to the original name
    $pdo->exec("ALTER TABLE jobs_new RENAME TO jobs;");
    
    // Step 5: Recreate any indexes that might have been on the original table
    // (In this case, we don't have any indexes, but this is good practice)
    
    // Commit transaction
    $pdo->commit();
    
    echo "\nDatabase migration completed successfully!\n";
    echo "The rep_name column has been renamed to tech_name.\n";
    echo "You can now safely use the updated application.\n";
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    echo "Error during migration: " . $e->getMessage() . "\n";
    echo "The migration has been rolled back. Your database should be in its original state.\n";
    exit(1);
}
?>