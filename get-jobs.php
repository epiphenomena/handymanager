<?php
// get-jobs.php - Get in-progress jobs for a rep

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

// Load data
$data = initDataStorage();

// Filter in-progress jobs for this rep (where closed_at is null)
$jobs = [];
if (isset($data['jobs'])) {
    foreach ($data['jobs'] as $job) {
        if ($job['rep_name'] === $repName && !isset($job['closed_at'])) {
            // Only return the fields needed for the list
            $jobs[] = [
                'id' => $job['id'],
                'start_time' => $job['start_time'],
                'location' => $job['location']
            ];
        }
    }
}

// Sort by start_time descending
usort($jobs, function($a, $b) {
    return strtotime($b['start_time']) - strtotime($a['start_time']);
});

sendJsonResponse(['success' => true, 'jobs' => $jobs]);
?>