<?php
// create-task.php - Start a new task on an open job

require_once __DIR__ . '/config.php';

$input = getValidatedInput(['tech_name', 'job_id', 'start_time']);

$startTime = validateDateTime($input['start_time']);
if ($startTime === false) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid start time format'], 400);
}

// Client-generated UUID makes offline replays idempotent
$clientUuid = isset($input['client_uuid']) && is_string($input['client_uuid'])
    ? substr(trim($input['client_uuid']), 0, 64) ?: null
    : null;

// Requests replayed from the offline queue are accepted even into a job
// closed in the meantime - the admin cleans up afterwards if needed.
$queued = !empty($input['queued']);

// The job must exist and (for live submissions) be open to new tasks -
// this enforces the pick-from-list-only location rule server side.
list($taskId, $message) = createTask((int)$input['job_id'], $input['tech_name'], $startTime, $clientUuid, $queued);

if ($taskId === false) {
    sendJsonResponse(['success' => false, 'message' => $message], 400);
}

sendJsonResponse(['success' => true, 'message' => 'Task started', 'task_id' => $taskId]);
