<?php
// get-latest-jobs.php - Get latest jobs for a tech

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
$input = getValidatedInput(['token', 'tech_name']);

$token = $input['token'];
$techName = $input['tech_name'];

// Get latest jobs from database
$jobs = getLatestJobs($techName);

sendJsonResponse(['success' => true, 'jobs' => $jobs]);
?>