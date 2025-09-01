<?php
// get-latest-jobs.php - Get latest jobs for a rep

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
if (!isset($input['token']) || !isset($input['rep_name'])) {
    sendJsonResponse(['success' => false, 'message' => 'Missing token or rep name']);
}

$token = $input['token'];
$repName = $input['rep_name'];

// Verify token
if (!verifyToken($token)) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid token']);
}

// Get latest jobs from database
$jobs = getLatestJobs($repName);

sendJsonResponse(['success' => true, 'jobs' => $jobs]);
?>