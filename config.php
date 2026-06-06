<?php
// config.php - Configuration, auth, and request helpers

// Load configuration from config.json
$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
define('VALID_TOKEN', $config['VALID_TOKEN']);
define('ADMIN_TOKEN', $config['ADMIN_TOKEN']);

// Include database functions and run any pending migrations
require_once __DIR__ . '/database.php';
initDatabase();

// Token checks (constant-time comparison)
function verifyToken($token) {
    return is_string($token) && hash_equals(VALID_TOKEN, $token);
}

function verifyAdminToken($token) {
    return is_string($token) && hash_equals(ADMIN_TOKEN, $token);
}

// Send a JSON response and stop
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Read, validate, and authenticate a JSON POST request.
// The token is verified on every request - it is the only access control.
// Tech and admin tokens are both accepted; $input['_isAdmin'] tells them apart.
function getValidatedInput($requiredFields = [], $requireAdmin = false) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Only POST requests allowed'], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid JSON input'], 400);
    }

    // Authenticate before anything else
    $token = $input['token'] ?? null;
    $input['_isAdmin'] = verifyAdminToken($token);
    if ($requireAdmin && !$input['_isAdmin']) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid admin token'], 401);
    }
    if (!$input['_isAdmin'] && !verifyToken($token)) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid token'], 401);
    }

    // Check for required fields
    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            $missingFields[] = $field;
        }
    }
    if (!empty($missingFields)) {
        sendJsonResponse(['success' => false, 'message' => 'Missing required fields: ' . implode(', ', $missingFields)], 400);
    }

    return $input;
}

// Validate and normalize a datetime string; returns 'Y-m-d H:i:s' or false
function validateDateTime($dateTimeString) {
    if (!is_string($dateTimeString) || trim($dateTimeString) === '') {
        return false;
    }
    try {
        $date = new DateTime($dateTimeString);
    } catch (Exception $e) {
        return false;
    }
    return $date->format('Y-m-d H:i:s');
}

// HTML-escape helper for server-rendered fragments
function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
