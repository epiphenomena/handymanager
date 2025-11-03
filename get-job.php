<?php
// get-job.php - Get job details by ID

require_once 'config.php';

// Enable CORS for local development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    sendJsonResponse(['success' => false, 'message' => 'Method not allowed']);
}

// Get and validate input with admin check
$input = getValidatedInput(['token', 'job_id', 'tech_name']);

$token = $input['token'];
$jobId = $input['job_id'];
$techName = $input['tech_name'];
$isAdmin = $input['_isAdmin'];

// Get job from database
$job = getJobById($jobId);

// If not admin, verify that the tech can access this job
if (!$isAdmin) {
    if (!$job) {
        sendJsonResponse(['success' => false, 'message' => 'Job not found']);
    }

    // Verify the requesting tech can access this job
    // Trim both values to handle any potential whitespace issues
    if (trim($job['tech_name']) !== trim($techName)) {
        sendJsonResponse(['success' => false, 'message' => 'You can only access your own jobs. Debug: Job tech name=\'' . trim($job['tech_name']) . '\', Requesting tech name=\'' . trim($techName) . '\'']);
    }
}

if ($job) {
    sendJsonResponse(['success' => true, 'job' => $job]);
} else {
    sendJsonResponse(['success' => false, 'message' => 'Job not found']);
}
?>