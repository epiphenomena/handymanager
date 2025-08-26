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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!isset($input['token']) || !isset($input['rep_name']) || !isset($input['start_time']) || !isset($input['location'])) {
    sendJsonResponse(['success' => false, 'message' => 'Missing required fields']);
}

$token = $input['token'];
$repName = $input['rep_name'];
$startTime = $input['start_time'];
$location = $input['location'];

// Verify token
if (!verifyToken($token)) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid token']);
}

// Load data
$data = initDataStorage();

// Initialize jobs array if it doesn't exist
if (!isset($data['jobs'])) {
    $data['jobs'] = [];
}

// Create new job
$jobId = generateId();
$newJob = [
    'id' => $jobId,
    'created_at' => date('Y-m-d H:i:s'),
    'rep_name' => $repName,
    'start_time' => $startTime,
    'location' => $location
];

$data['jobs'][] = $newJob;

// Save data
saveData($data);

sendJsonResponse(['success' => true, 'message' => 'Job created successfully', 'job_id' => $jobId]);
?>