<?php
// get-latest-tasks.php - Get latest tasks for a tech (history)

require_once __DIR__ . '/config.php';

$input = getValidatedInput(['tech_name']);

$tasks = getLatestTasks(trim($input['tech_name']));

sendJsonResponse(['success' => true, 'tasks' => $tasks]);
