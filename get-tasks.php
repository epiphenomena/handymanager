<?php
// get-tasks.php - Get in-progress tasks for a tech

require_once __DIR__ . '/config.php';

$input = getValidatedInput(['tech_name']);

$tasks = getInProgressTasks(trim($input['tech_name']));

sendJsonResponse(['success' => true, 'tasks' => $tasks]);
