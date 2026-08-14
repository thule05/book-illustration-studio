<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_method('GET');

$userId = require_auth();
session_write_close();

$relativePath = (string) ($_GET['path'] ?? '');

if ($relativePath === '') {
    json_error(400, 'path is required.');
}

$relativePath = str_replace('\\', '/', $relativePath);
if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
    json_error(400, 'Invalid path.');
}

if (
    preg_match(
        '#\Aimages/(portraits|illustrations)/([1-9][0-9]*)/[^/]+\.(png|jpe?g|webp)\z#i',
        $relativePath,
        $pathParts
    ) !== 1
) {
    json_error(404, 'File not found.');
}

$category = strtolower($pathParts[1]);
$projectId = (int) $pathParts[2];

if ($category === 'portraits') {
    $ownership = $pdo->prepare(
        'SELECT 1
         FROM characters c
         INNER JOIN projects p ON p.id = c.project_id
         WHERE p.id = :project_id
           AND p.user_id = :user_id
           AND c.portrait_path = :path
         LIMIT 1'
    );
} else {
    $ownership = $pdo->prepare(
        'SELECT 1
         FROM chapters c
         INNER JOIN projects p ON p.id = c.project_id
         WHERE p.id = :project_id
           AND p.user_id = :user_id
           AND c.illustration_path = :path
         LIMIT 1'
    );
}

$ownership->execute([
    'project_id' => $projectId,
    'user_id' => $userId,
    'path' => $relativePath,
]);

if ($ownership->fetchColumn() === false) {
    json_error(404, 'File not found.');
}

$configuredStorageRoot = trim((string) env('STORAGE_ROOT', ''));
if ($configuredStorageRoot === '') {
    $configuredStorageRoot = dirname(__DIR__) . '/storage';
}
$storageRoot = realpath($configuredStorageRoot);
if ($storageRoot === false) {
    json_error(500, 'Storage root not found.');
}

$absolutePath = realpath($storageRoot . '/' . $relativePath);

if ($absolutePath === false || !str_starts_with($absolutePath, $storageRoot . DIRECTORY_SEPARATOR)) {
    json_error(404, 'File not found.');
}

if (!is_file($absolutePath)) {
    json_error(404, 'File not found.');
}

$extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
$mimeTypes = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
];

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Type: ' . ($mimeTypes[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($absolutePath));
readfile($absolutePath);
exit;
