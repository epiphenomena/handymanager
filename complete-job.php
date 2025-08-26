<?php
// complete-job.php - Complete/close a job

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
if (!isset($input['token']) || !isset($input['job_id']) || !isset($input['end_time'])) {
    sendJsonResponse(['success' => false, 'message' => 'Missing required fields']);
}

$token = $input['token'];
$jobId = $input['job_id'];
$endTime = $input['end_time'];
$notes = isset($input['notes']) ? $input['notes'] : null;

// Verify token
if (!verifyToken($token)) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid token']);
}

// Complete job in database
$success = completeJob($jobId, $endTime, $notes);

if ($success) {
    sendJsonResponse(['success' => true, 'message' => 'Job completed successfully']);
} else {
    sendJsonResponse(['success' => false, 'message' => 'Job not found']);
}
?>