<?php
// get-task.php - Get task details by ID

require_once __DIR__ . '/config.php';

$input = getValidatedInput(['task_id', 'tech_name']);

$task = getTaskById((int)$input['task_id']);
if (!$task) {
    sendJsonResponse(['success' => false, 'message' => 'Task not found'], 404);
}

// Techs share a token, so verify the requesting tech owns this task
if (!$input['_isAdmin'] && trim($task['tech_name']) !== trim($input['tech_name'])) {
    sendJsonResponse(['success' => false, 'message' => 'You can only access your own tasks'], 403);
}

sendJsonResponse(['success' => true, 'task' => $task]);
