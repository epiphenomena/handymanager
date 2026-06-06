<?php
// complete-task.php - Complete/close a task

require_once __DIR__ . '/config.php';

$input = getValidatedInput(['task_id', 'tech_name', 'end_time']);

$endTime = validateDateTime($input['end_time']);
if ($endTime === false) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid end time format'], 400);
}

$task = getTaskById((int)$input['task_id']);
if (!$task) {
    sendJsonResponse(['success' => false, 'message' => 'Task not found'], 404);
}

// Techs share a token, so verify the requesting tech owns this task
if (!$input['_isAdmin'] && trim($task['tech_name']) !== trim($input['tech_name'])) {
    sendJsonResponse(['success' => false, 'message' => 'You can only complete your own tasks'], 403);
}

if (completeTask((int)$input['task_id'], $endTime, $input['notes'] ?? null)) {
    sendJsonResponse(['success' => true, 'message' => 'Task completed']);
}

sendJsonResponse(['success' => false, 'message' => 'Failed to complete task'], 500);
