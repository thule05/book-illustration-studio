<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_method('POST');

$userId = require_auth();
session_write_close();

// XAMPP Apache defaults to a 120-second PHP execution limit, while a single
// billed image request may legitimately run longer and Portraits performs the
// items sequentially. Extend only this endpoint, and let it finish if the
// browser refreshes so persisted state never gets stranded by a disconnect.
$executionTimeout = max(1, (int) env('PIPELINE_EXECUTION_TIMEOUT_SECONDS', '600'));
if (!function_exists('set_time_limit') || set_time_limit($executionTimeout) === false) {
    json_error(500, 'The server cannot configure the pipeline execution timeout.');
}
ignore_user_abort(true);

$body = read_json_body();

$projectId = isset($body['project_id']) ? (int) $body['project_id'] : 0;
$userStyle = isset($body['user_style']) ? (string) $body['user_style'] : null;
$requestedStep = isset($body['step']) ? strtolower(trim((string) $body['step'])) : null;

if ($projectId <= 0) {
    json_error(400, 'project_id is required.');
}

$projectService = new ProjectService($pdo);
$provider = ProviderFactory::create();
$pipeline = new PipelineService($pdo, $provider, $projectService);

$result = $pipeline->runStep($projectId, $userId, $userStyle, $requestedStep);

json_response(200, $result);
