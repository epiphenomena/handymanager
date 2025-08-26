#!/usr/bin/env php
<?php
// dev-server.php - Simple development server for the PHP backend

// This script starts a PHP built-in server for development purposes
// Usage: php dev-server.php [port]

$port = $argv[1] ?? 8000;
$host = 'localhost';

echo "Starting PHP development server...\n";
echo "Document root: " . getcwd() . "\n";
echo "Server running at http://$host:$port/\n";
echo "Press Ctrl+C to stop.\n\n";

// Start the PHP built-in server
passthru("php -S $host:$port -t .");
?>