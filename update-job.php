<?php
// update-job.php - Update a job

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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!isset($input['token']) || !isset($input['job_id']) || !isset($input['start_time']) || 
    !isset($input['location'])) {
    sendJsonResponse(['success' => false, 'message' => 'Missing required fields']);
}

$token = $input['token'];
$jobId = $input['job_id'];
$startTime = $input['start_time'];
$endTime = $input['end_time'] ?? null;
$location = $input['location'];
$notes = $input['notes'] ?? null;

// Verify token
if (!verifyToken($token)) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid token']);
}

// Update job in database
$result = updateJob($jobId, $startTime, $endTime, $location, $notes);

if ($result) {
    sendJsonResponse(['success' => true, 'message' => 'Job updated successfully']);
} else {
    sendJsonResponse(['success' => false, 'message' => 'Failed to update job']);
}
?>