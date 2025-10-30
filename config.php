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
?>