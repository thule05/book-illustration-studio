<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$userId = require_auth();
$projectService = new ProjectService($pdo);

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    json_response(200, [
        'projects' => $projectService->listForUser($userId),
    ]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $title = trim((string) ($body['title'] ?? ''));
    $bookText = trim((string) ($body['book_text'] ?? ''));

    if ($title === '') {
        json_error(400, 'Title is required.');
    }

    if ($bookText === '') {
        json_error(400, 'Book text is required.');
    }

    $storageRoot = dirname(__DIR__) . '/storage';

    try {
        $pdo->beginTransaction();

        $insert = $pdo->prepare(
            'INSERT INTO projects (user_id, title, book_text, status)
             VALUES (:user_id, :title, :book_text, \'draft\')'
        );
        $insert->execute([
            'user_id' => $userId,
            'title' => $title,
            'book_text' => $bookText,
        ]);

        $projectId = (int) $pdo->lastInsertId();
        $relativeBookPath = "books/{$projectId}.txt";
        $absoluteBookPath = $storageRoot . '/' . $relativeBookPath;

        $bookDir = dirname($absoluteBookPath);
        if (!is_dir($bookDir) && !mkdir($bookDir, 0775, true) && !is_dir($bookDir)) {
            throw new RuntimeException('Could not create book storage directory.');
        }

        if (file_put_contents($absoluteBookPath, $bookText) === false) {
            throw new RuntimeException('Could not save book text file.');
        }

        $update = $pdo->prepare(
            'UPDATE projects SET book_file_path = :book_file_path WHERE id = :id'
        );
        $update->execute([
            'book_file_path' => $relativeBookPath,
            'id' => $projectId,
        ]);

        $steps = [
            ['style', 1],
            ['characters', 2],
            ['portraits', 3],
            ['chapters', 4],
            ['illustrations', 5],
        ];

        $stepInsert = $pdo->prepare(
            'INSERT INTO project_steps (project_id, step, step_order, state)
             VALUES (:project_id, :step, :step_order, \'pending\')'
        );

        foreach ($steps as [$step, $order]) {
            $stepInsert->execute([
                'project_id' => $projectId,
                'step' => $step,
                'step_order' => $order,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_error(500, 'Could not create project.', ['message' => $e->getMessage()]);
    }

    $detail = $projectService->getDetail($projectId, $userId);
    json_response(201, $detail);
}

json_error(405, 'Method not allowed.');
