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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input - tech_name is now required for proper access control
if (!isset($input['token']) || !isset($input['job_id']) || !isset($input['tech_name'])) {
    sendJsonResponse(['success' => false, 'message' => 'Missing token, job ID, or tech name']);
}

$token = $input['token'];
$jobId = $input['job_id'];
$techName = $input['tech_name'];

// Check if it's an admin token first
if (verifyAdminToken($token)) {
    // Admin can access any job, proceed directly
    $isAdmin = true;
} else if (verifyToken($token)) {
    // Regular tech token - verify that the tech owns this job
    $job = getJobById($jobId);
    if (!$job) {
        sendJsonResponse(['success' => false, 'message' => 'Job not found']);
    }
    
    // Verify the requesting tech can access this job
    if ($job['tech_name'] !== $techName) {
        sendJsonResponse(['success' => false, 'message' => 'You can only access your own jobs']);
    }
} else {
    // Invalid token
    sendJsonResponse(['success' => false, 'message' => 'Invalid token']);
}

// Get job from database
$job = getJobById($jobId);

if ($job) {
    sendJsonResponse(['success' => true, 'job' => $job]);
} else {
    sendJsonResponse(['success' => false, 'message' => 'Job not found']);
}
?>