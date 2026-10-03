<?php

/*
 * Pengurusan session dan perlindungan ikut role (merchant/NGO).
 * Setiap page yang perlu login include fail ni dulu.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Base URL folder projek, cth: /freshtrack
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

// Semak sama ada user dah login (session user_id & role wujud).
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id'], $_SESSION['role']);
}

// Ambil role user semasa (merchant/ngo), kosong kalau belum login.
function currentRole(): string
{
    return $_SESSION['role'] ?? '';
}

// Hantar user ke dashboard yang sepadan dengan role dia.
function redirectToDashboard(): void
{
    if (currentRole() === 'merchant') {
        header('Location: ' . BASE_URL . '/merchant/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . '/ngo/dashboard.php');
    }

    exit;
}

// Sekat page ni melainkan user login dan role dia sepadan.
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

// Jana CSRF token sekali per session, simpan dalam $_SESSION supaya
// sama digunakan untuk semua form sepanjang session tu.
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

// Sahkan token yang dihantar form sepadan dengan token dalam session
// (elak serangan CSRF — form submit dari luar tapak).
function verifyCsrfToken(?string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

// Cross-Site Request Forgery (CSRF) token untuk form HTML. 