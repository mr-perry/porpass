<?php
/**
 * login.php — User login page.
 *
 * Handles both display of the login form and processing of POST submissions.
 * On success, redirects admin users to /admin/users.php and regular users
 * to /dashboard.php.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';

session_start_secure();

// Redirect already-authenticated users
if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = 'Please enter your email address and password.';
    } else {
        $db   = get_db();
        $stmt = $db->prepare(
            'SELECT user_id, username, password_hash, role, is_active
             FROM users WHERE email = ?'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid email address or password.';
        } elseif (!$user['is_active']) {
            $error = 'Your account is pending approval. Please check back later.';
        } else {
            // Regenerate session ID on login to prevent session fixation
            session_regenerate_id(true);
            $_SESSION['user_id']       = $user['user_id'];
            $_SESSION['username']      = $user['username'];
            $_SESSION['role']          = $user['role'];
            $_SESSION['last_activity'] = time();

            // Update last login timestamp
            $db->prepare('UPDATE users SET last_login_at = NOW() WHERE user_id = ?')
               ->execute([$user['user_id']]);

            // Role-based redirect
            if ($user['role'] === 'admin') {
                header('Location: /admin/users.php');
            } else {
                header('Location: /dashboard.php');
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PORPASS — Login</title>
    <link href="/resources/css/bootstrap.min.css" rel="stylesheet">
    <link href="/resources/css/porpass.css"       rel="stylesheet">
</head>
<body class="bg-light">

<div class="container">
    <div class="row justify-content-center mt-5">
        <div class="col-md-5">

            <div class="text-center mb-4">
                <img src="/resources/img/porpass-logo.png"
                     alt="PORPASS"
                     class="mb-3"
                     style="max-height: 80px;">
                <h1 class="h3">PORPASS</h1>
                <p class="text-muted">Planetary Orbital Radar Processing and Submission System</p>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h2 class="card-title h5 mb-4">Sign In</h2>

                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert">
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="/login.php" novalidate>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email address</label>
                            <input
                                type="email"
                                class="form-control"
                                id="email"
                                name="email"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                required
                                autofocus>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input
                                type="password"
                                class="form-control"
                                id="password"
                                name="password"
                                required>
                        </div>

                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-primary">Sign In</button>
                        </div>

                        <div class="d-flex justify-content-between small">
                            <a href="/auth/reset.php">Forgot your password?</a>
                            <a href="/register.php">Create an account</a>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="/resources/js/bootstrap.bundle.min.js"></script>
</body>
</html>
