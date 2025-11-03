<?php
// config.php - Configuration and utility functions

// Load configuration from config.json
$config = json_decode(file_get_contents('config.json'), true);
define('VALID_TOKEN', $config['VALID_TOKEN']);
define('ADMIN_TOKEN', $config['ADMIN_TOKEN']);

// Include database functions
require_once 'database.php';

// Initialize database on first load
initDatabase();

// Function to verify token
function verifyToken($token) {
    return $token === VALID_TOKEN;
}

// Function to verify admin token
function verifyAdminToken($token) {
    return $token === ADMIN_TOKEN;
}

// Function to send JSON response
function sendJsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Function to get and validate JSON input, verify token, and check required fields
function getValidatedInput($requiredFields = [], $requireAdmin = false) {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    // Check if JSON was decoded successfully
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid JSON input']);
    }

    // Check for required fields
    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field])) {
            $missingFields[] = $field;
        }
    }

    if (!empty($missingFields)) {
        $missingFieldsList = implode(', ', $missingFields);
        sendJsonResponse(['success' => false, 'message' => "Missing required fields: $missingFieldsList"]);
    }

    // Verify token for access control
    $token = $input['token'] ?? null;
    $input['_isAdmin'] = false;

    if (verifyAdminToken($token)) {
        // Admin can access, return input with admin flag
        $input['_isAdmin'] = true;
    } else if ($requireAdmin) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid admin token']);
    } else if (!verifyToken($token)) {
        // Regular tech token, return input with admin flag as false
        sendJsonResponse(['success' => false, 'message' => 'Invalid token']);
    }

    return $input;
}


?>