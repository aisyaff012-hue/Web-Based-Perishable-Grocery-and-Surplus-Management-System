<?php

/*
 * Session handling and role protection.
 * Every protected page includes this file first.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Base URL of the project folder, e.g. /freshtrack
define(
    'BASE_URL',
    rtrim(
        str_replace(
            '\\',
            '/',
            substr(
                dirname(__DIR__),
                strlen(
                    str_replace(
                        '\\',
                        '/',
                        realpath($_SERVER['DOCUMENT_ROOT'])
                    )
                )
            )
        ),
        '/'
    )
);
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id'], $_SESSION['role']);
}

function currentRole(): string
{
    return $_SESSION['role'] ?? '';
}

// Sends the user to the dashboard that matches their role.
function redirectToDashboard(): void
{
    if (currentRole() === 'merchant') {
        header('Location: ' . BASE_URL . '/merchant/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . '/ngo/dashboard.php');
    }

    exit;
}

// Blocks the page unless the user holds the required role.
function requireRole(string $role): void
{
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit;
    }

    if (currentRole() !== $role) {
        redirectToDashboard();
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}