<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_method('GET');

$relativePath = (string) ($_GET['path'] ?? '');

if ($relativePath === '') {
    json_error(400, 'path is required.');
}

$relativePath = str_replace('\\', '/', $relativePath);
if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
    json_error(400, 'Invalid path.');
}

$storageRoot = realpath(dirname(__DIR__) . '/storage');
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
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'txt' => 'text/plain; charset=utf-8',
];

header('Content-Type: ' . ($mimeTypes[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($absolutePath));
readfile($absolutePath);
exit;
