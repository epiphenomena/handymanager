<?php
// get-open-jobs.php - Jobs a tech can log tasks against (open jobs + Clock in/out)

require_once __DIR__ . '/config.php';

$input = getValidatedInput(['tech_name']);

$jobs = getOpenJobsForTech();

sendJsonResponse(['success' => true, 'jobs' => $jobs]);
