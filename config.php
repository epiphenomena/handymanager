<?php
// config.php - Configuration and utility functions

// Hardcoded token for authentication
define('VALID_TOKEN', 'handymanager-secret-token');

// Data file path
define('DATA_FILE', __DIR__ . '/handymanager-data.json');

// Function to initialize data storage
function initDataStorage() {
    if (!file_exists(DATA_FILE)) {
        file_put_contents(DATA_FILE, json_encode([]));
    }
    return json_decode(file_get_contents(DATA_FILE), true);
}

// Function to save data
function saveData($data) {
    file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

// Function to generate a unique ID
function generateId() {
    return uniqid();
}

// Function to verify token
function verifyToken($token) {
    return $token === VALID_TOKEN;
}

// Function to send JSON response
function sendJsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
?>