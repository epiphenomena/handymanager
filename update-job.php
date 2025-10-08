<?php
// update-job.php - Endpoint to update job details (location and notes)

require_once 'config.php';

// Enable CORS for local development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    sendJsonResponse(['success' => false, 'message' => 'Method not allowed']);
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate admin token
if (!isset($input['token']) || !verifyAdminToken($input['token'])) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid admin token']);
}

// Validate required parameters
if (!isset($input['job_id'])) {
    sendJsonResponse(['success' => false, 'message' => 'Job ID is required']);
}

$jobId = $input['job_id'];
$location = $input['location'] ?? null;
$notes = $input['notes'] ?? null;

// At least one field must be provided for update
if ($location === null && $notes === null) {
    sendJsonResponse(['success' => false, 'message' => 'At least one field (location or notes) must be provided for update']);
}

// Update the job in database
try {
    $result = updateJobPartial($jobId, $location, $notes);
    
    if ($result) {
        sendJsonResponse(['success' => true, 'message' => 'Job updated successfully']);
    } else {
        sendJsonResponse(['success' => false, 'message' => 'Failed to update job']);
    }
} catch (Exception $e) {
    sendJsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}