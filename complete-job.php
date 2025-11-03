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

// Get and validate input
$input = getValidatedInput(['token', 'job_id', 'end_time']);

$token = $input['token'];
$jobId = $input['job_id'];
$endTime = $input['end_time'];
$notes = $input['notes'] ?? null;

// Complete job in database
$success = completeJob($jobId, $endTime, $notes);

if ($success) {
    sendJsonResponse(['success' => true, 'message' => 'Job completed successfully']);
} else {
    sendJsonResponse(['success' => false, 'message' => 'Job not found']);
}
?>