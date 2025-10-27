<?php
// update-job.php - Endpoint to update job details (all fields)

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

// Validate required parameters
if (!isset($input['token']) || !isset($input['job_id'])) {
    sendJsonResponse(['success' => false, 'message' => 'Token and Job ID are required']);
}

$token = $input['token'];
$jobId = $input['job_id'];
$location = $input['location'] ?? null;
$notes = $input['notes'] ?? null;
$start_time = $input['start_time'] ?? null;
$end_time = $input['end_time'] ?? null;
$tech_name = $input['tech_name'] ?? null;

// At least one field must be provided for update
if ($location === null && $notes === null && $start_time === null && $end_time === null && $tech_name === null) {
    sendJsonResponse(['success' => false, 'message' => 'At least one field must be provided for update']);
}

// Check if it's an admin token first
if (verifyAdminToken($token)) {
    // Admin can update any job, proceed directly
} else if (verifyToken($token)) {
    // Regular tech token - verify that the tech owns this job
    // Get the job to check ownership
    $job = getJobById($jobId);
    if (!$job) {
        sendJsonResponse(['success' => false, 'message' => 'Job not found']);
    }
    
    // For tech users, since we have a shared token, we need to check that the 
    // tech name sent in the request matches the job's tech name (security check)
    // This assumes the frontend is sending the original tech name from the job data
    if (!isset($input['job_tech_name'])) {
        sendJsonResponse(['success' => false, 'message' => 'Job tech name is required for verification']);
    }
    
    $requestTechName = $input['job_tech_name'];
    if ($job['tech_name'] !== $requestTechName) {
        sendJsonResponse(['success' => false, 'message' => 'You can only update your own jobs']);
    }
} else {
    // Invalid token
    sendJsonResponse(['success' => false, 'message' => 'Invalid token']);
}

// Validate date/time formats if provided
if ($start_time !== null) {
    $start_time = validateAndFormatDateTime($start_time);
    if ($start_time === false) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid start time format']);
    }
}

if ($end_time !== null) {
    $end_time = validateAndFormatDateTime($end_time);
    if ($end_time === false) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid end time format']);
    }
}

// Update the job in database
try {
    $result = updateJobPartial($jobId, $location, $notes, $start_time, $end_time, $tech_name);
    
    if ($result) {
        sendJsonResponse(['success' => true, 'message' => 'Job updated successfully']);
    } else {
        sendJsonResponse(['success' => false, 'message' => 'Failed to update job']);
    }
} catch (Exception $e) {
    sendJsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

// Function to validate and format date/time
function validateAndFormatDateTime($dateTimeString) {
    // Try to parse the date/time string
    $date = new DateTime($dateTimeString);
    if ($date === false) {
        return false;
    }
    // Return in ISO 8601 format
    return $date->format('Y-m-d H:i:s');
}