<?php
// config.php - Configuration and utility functions

// Hardcoded token for authentication
define('VALID_TOKEN', 'handyman');
define('ADMIN_TOKEN', 'admin123'); // Separate token for admin access

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