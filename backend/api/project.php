<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_method('GET');

$userId = require_auth();
$projectId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($projectId <= 0) {
    json_error(400, 'Project id is required.');
}

$projectService = new ProjectService($pdo);
$detail = $projectService->getDetail($projectId, $userId);

if ($detail === null) {
    json_error(404, 'Project not found.');
}

json_response(200, $detail);
