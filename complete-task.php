<?php
// complete-task.php - Complete/close a task

require_once __DIR__ . '/config.php';

$input = getValidatedInput(['tech_name', 'end_time']);

$endTime = validateDateTime($input['end_time']);
if ($endTime === false) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid end time format'], 400);
}

// Tasks are referenced by server id, or by client UUID for tasks that were
// created offline (the client never saw the server id)
if (!empty($input['task_id'])) {
    $task = getTaskById((int)$input['task_id']);
} elseif (!empty($input['task_uuid'])) {
    $task = getTaskByClientUuid($input['task_uuid']);
} else {
    sendJsonResponse(['success' => false, 'message' => 'Missing required fields: task_id or task_uuid'], 400);
}

if (!$task) {
    sendJsonResponse(['success' => false, 'message' => 'Task not found'], 404);
}

// Techs share a token, so verify the requesting tech owns this task
if (!$input['_isAdmin'] && trim($task['tech_name']) !== trim($input['tech_name'])) {
    sendJsonResponse(['success' => false, 'message' => 'You can only complete your own tasks'], 403);
}

if (completeTask((int)$task['id'], $endTime, $input['notes'] ?? null)) {
    sendJsonResponse(['success' => true, 'message' => 'Task completed']);
}

sendJsonResponse(['success' => false, 'message' => 'Failed to complete task'], 500);
