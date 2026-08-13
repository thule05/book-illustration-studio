<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_method('POST');

$body = read_json_body();
$name = trim((string) ($body['name'] ?? ''));
$email = strtolower(trim((string) ($body['email'] ?? '')));

if ($name === '') {
    json_error(400, 'Name is required.');
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error(400, 'Valid email is required.');
}

$select = $pdo->prepare('SELECT id, name, email FROM users WHERE email = :email LIMIT 1');
$select->execute(['email' => $email]);
$user = $select->fetch();

if ($user) {
    if ($user['name'] !== $name) {
        $update = $pdo->prepare('UPDATE users SET name = :name WHERE id = :id');
        $update->execute([
            'name' => $name,
            'id' => $user['id'],
        ]);
        $user['name'] = $name;
    }
} else {
    $insert = $pdo->prepare('INSERT INTO users (name, email) VALUES (:name, :email)');
    $insert->execute([
        'name' => $name,
        'email' => $email,
    ]);

    $user = [
        'id' => (int) $pdo->lastInsertId(),
        'name' => $name,
        'email' => $email,
    ];
}

set_user_session((int) $user['id']);

json_response(200, [
    'user' => [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
    ],
]);
