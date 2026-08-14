<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_method('POST');

$userId = require_auth();
session_write_close();
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
