<?php
/**
 * auth.php — Session and authentication helpers.
 *
 * Provides functions for starting sessions, checking login state,
 * enforcing authentication on protected pages, and role-based access.
 */

define('SESSION_TIMEOUT', 1800); // 30 minutes

/**
 * Start a secure PHP session.
 */
function session_start_secure(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => false, // set to true in production with HTTPS
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

/**
 * Check whether the current session is authenticated and not timed out.
 *
 * @return bool
 */
function is_logged_in(): bool {
    session_start_secure();
    if (empty($_SESSION['user_id'])) {
        return false;
    }
    if (isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Require the user to be logged in. Redirects to login if not.
 */
function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Require the user to be an admin. Redirects to dashboard if not.
 */
function require_admin(): void {
    require_login();
    if ($_SESSION['role'] !== 'admin') {
        header('Location: /dashboard.php');
        exit;
    }
}

/**
 * Log the current user out and redirect to login.
 */
function logout(): void {
    session_start_secure();
    session_unset();
    session_destroy();
    header('Location: /login.php');
    exit;
}