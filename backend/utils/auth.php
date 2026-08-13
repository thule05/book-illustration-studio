<?php

declare(strict_types=1);

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function set_user_session(int $userId): void
{
    start_session();
    $_SESSION['user_id'] = $userId;
}

function current_user_id(): ?int
{
    start_session();
    $id = $_SESSION['user_id'] ?? null;

    return is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
}

function require_auth(): int
{
    $userId = current_user_id();
    if ($userId === null) {
        json_error(401, 'Authentication required.');
    }

    return $userId;
}
