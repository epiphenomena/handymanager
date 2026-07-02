<?php
// completed-jobs.php - Admin JSON endpoint: jobs a tech flagged as finished
// ("- job complete", fuzzy-matched in their task notes) whose completion is
// after a given date.
//
// Ping it with the admin token and a date; it returns JSON. Parameters may be
// given as a GET query string, a POST form body, or a POST JSON body:
//
//   GET  completed-jobs.php?token=ADMIN_TOKEN&since=2026-06-01
//   POST {"token":"ADMIN_TOKEN","since":"2026-06-01"}
//
// The admin token is the only access control and is verified on every request.

require_once __DIR__ . '/config.php';

// Read a parameter from the query string, form body, or JSON body (in that
// order), accepting any of the given aliases.
$jsonBody = json_decode(file_get_contents('php://input'), true);
if (!is_array($jsonBody)) {
    $jsonBody = [];
}
$param = function ($keys) use ($jsonBody) {
    foreach ((array)$keys as $k) {
        foreach ([$_GET, $_POST, $jsonBody] as $src) {
            if (isset($src[$k]) && is_string($src[$k]) && trim($src[$k]) !== '') {
                return trim($src[$k]);
            }
        }
    }
    return null;
};

// Auth: admin token only
if (!verifyAdminToken($param('token'))) {
    sendJsonResponse(['success' => false, 'error' => 'Invalid admin token'], 401);
}

// The cutoff date: return jobs completed strictly after this.
$sinceRaw = $param(['since', 'date', 'after']);
if ($sinceRaw === null) {
    sendJsonResponse(['success' => false, 'error' => 'Provide a date, e.g. ?since=2026-06-01'], 400);
}
$since = validateDateTime($sinceRaw);
if ($since === false) {
    sendJsonResponse(['success' => false, 'error' => 'Invalid date: ' . $sinceRaw], 400);
}

$jobs = getCompletedJobsSince($since);
sendJsonResponse([
    'success' => true,
    'since' => $since,
    'count' => count($jobs),
    'jobs' => $jobs,
]);
