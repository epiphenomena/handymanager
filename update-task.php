<?php
// update-task.php - Update task details (times, notes)

require_once __DIR__ . '/config.php';

$input = getValidatedInput(['task_id', 'tech_name']);

$task = getTaskById((int)$input['task_id']);
if (!$task) {
    sendJsonResponse(['success' => false, 'message' => 'Task not found'], 404);
}

// Techs share a token, so verify the requesting tech owns this task
if (!$input['_isAdmin'] && trim($task['tech_name']) !== trim($input['tech_name'])) {
    sendJsonResponse(['success' => false, 'message' => 'You can only update your own tasks'], 403);
}

$fields = [];

if (isset($input['start_time'])) {
    $startTime = validateDateTime($input['start_time']);
    if ($startTime === false) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid start time format'], 400);
    }
    $fields['start_time'] = $startTime;
}

if (array_key_exists('end_time', $input) && $input['end_time'] !== null && $input['end_time'] !== '') {
    $endTime = validateDateTime($input['end_time']);
    if ($endTime === false) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid end time format'], 400);
    }
    $fields['end_time'] = $endTime;
}

if (array_key_exists('notes', $input)) {
    $fields['notes'] = $input['notes'];
}

if (empty($fields)) {
    sendJsonResponse(['success' => false, 'message' => 'At least one field must be provided'], 400);
}

if (updateTaskPartial((int)$input['task_id'], $fields)) {
    sendJsonResponse(['success' => true, 'message' => 'Task updated']);
}

sendJsonResponse(['success' => false, 'message' => 'Failed to update task'], 500);
