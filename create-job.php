<?php
// create-job.php - Create a new job

require_once 'config.php';

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
$input = getValidatedInput(['token', 'tech_name', 'start_time', 'location']);

$token = $input['token'];
$techName = $input['tech_name'];
$startTime = $input['start_time'];
$location = $input['location'];

// Create new job in database
$success = createJob($techName, $startTime, $location);

if ($success) {
    // Get the ID of the newly created job
    $newJobId = getDbConnection()->lastInsertId();
    sendJsonResponse(['success' => true, 'message' => 'Job created successfully', 'job_id' => $newJobId]);
} else {
    sendJsonResponse(['success' => false, 'message' => 'Failed to create job']);
}
?>