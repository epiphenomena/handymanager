<?php
// get-filter-options.php - Get filter options for admin dashboard

require_once 'config.php';

// Enable CORS for local development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse(['success' => false, 'message' => 'Only GET requests allowed']);
}

// Include database functions
require_once 'database.php';

// Get filter options from database
$options = getFilterOptions();

sendJsonResponse(['success' => true, 'options' => $options]);
?>