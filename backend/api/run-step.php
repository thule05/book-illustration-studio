<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_method('POST');

$userId = require_auth();
$body = read_json_body();

$projectId = isset($body['project_id']) ? (int) $body['project_id'] : 0;
$userStyle = isset($body['user_style']) ? (string) $body['user_style'] : null;

if ($projectId <= 0) {
    json_error(400, 'project_id is required.');
}

$projectService = new ProjectService($pdo);
$provider = ProviderFactory::create();
$pipeline = new PipelineService($pdo, $provider, $projectService);

$result = $pipeline->runStep($projectId, $userId, $userStyle);

json_response(200, $result);
