<?php
// get-locations.php - Get unique locations for autocompletion

require_once 'config.php';
require_once 'database.php';

// Enable CORS for local development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['success' => false, 'message' => 'Only POST requests allowed']);
}

// Get and validate input
$input = getValidatedInput(['token', 'tech_name']);

$token = $input['token'];
$techName = $input['tech_name'];

// Get unique locations sorted by tech and most recently started datetime
try {
    $pdo = getDbConnection();
    
    // Query to get unique locations sorted by tech and most recently started datetime
    // We want locations sorted by the most recent job for each location
    $stmt = $pdo->prepare("
        SELECT DISTINCT location
        FROM jobs 
        WHERE location IS NOT NULL AND location != ''
        ORDER BY 
            (SELECT MAX(start_time) FROM jobs j2 WHERE j2.location = jobs.location) DESC,
            location
    ");
    
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    sendJsonResponse(['success' => true, 'locations' => $results]);
} catch (Exception $e) {
    sendJsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>