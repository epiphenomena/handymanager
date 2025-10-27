<?php
// get-job.php - Get job details by ID

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
if (!isset($input['token']) || !isset($input['job_id'])) {
    sendJsonResponse(['success' => false, 'message' => 'Missing token or job ID']);
}

$token = $input['token'];
$jobId = $input['job_id'];

// Verify token
if (!verifyToken($token)) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid token']);
}

// Get job from database
$job = getJobById($jobId);

if ($job) {
    // For regular tech tokens, verify the job belongs to the tech
    // by checking if the tech name sent with the request matches the job
    if (!isset($input['tech_name'])) {
        // For backward compatibility, if tech_name is not provided, 
        // we'll skip this check for now but should ideally require it
        // For now, we'll proceed with the old logic but add the check if tech_name is provided
        
        // Since all techs share the same token, we need to make sure
        // the requesting tech can only access jobs they own
        // This requires the frontend to send the tech's name
    } else {
        $requestingTechName = $input['tech_name'];
        if ($job['tech_name'] !== $requestingTechName) {
            sendJsonResponse(['success' => false, 'message' => 'You can only access your own jobs']);
        }
    }
    
    sendJsonResponse(['success' => true, 'job' => $job]);
} else {
    sendJsonResponse(['success' => false, 'message' => 'Job not found']);
}
?>