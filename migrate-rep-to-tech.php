<?php
// migrate-rep-to-tech.php - Database migration script to rename rep_name to tech_name

require_once 'config.php';

echo "Database Migration: rep_name to tech_name\n";
echo "=========================================\n\n";

try {
    $pdo = getDbConnection();
    
    // Check current schema
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
    
    // Validate schema state
    if (!$hasRepName) {
        echo "Error: Column 'rep_name' not found. Migration may have already been performed.\n";
        exit(1);
    }
    
    if ($hasTechName) {
        echo "Error: Column 'tech_name' already exists. Migration may have already been performed.\n";
        exit(1);
    }
    
    // Perform migration in a transaction
    $pdo->beginTransaction();
    
    echo "Renaming rep_name column to tech_name...\n";
    
    // SQLite requires recreating the table for column renaming
    $pdo->exec("CREATE TABLE jobs_new (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL,
        tech_name TEXT NOT NULL,
        start_time TEXT NOT NULL,
        location TEXT NOT NULL,
        end_time TEXT,
        notes TEXT,
        closed_at TEXT
    )");
    
    $pdo->exec("INSERT INTO jobs_new (id, created_at, tech_name, start_time, location, end_time, notes, closed_at)
                SELECT id, created_at, rep_name, start_time, location, end_time, notes, closed_at FROM jobs");
    
    $pdo->exec("DROP TABLE jobs");
    $pdo->exec("ALTER TABLE jobs_new RENAME TO jobs");
    
    $pdo->commit();
    
    echo "Migration completed successfully!\n";
    echo "The rep_name column has been renamed to tech_name.\n";
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>