<?php
/**
 * reset.php — Password reset flow.
 *
 * Handles two distinct steps in a single file:
 *
 * Step 1 — Forgot password form (no token in URL):
 *   User enters their email address. If found, a hashed reset token is stored
 *   in the database and a reset link is emailed to the user. A generic success
 *   message is always shown regardless of whether the email exists, to prevent
 *   user enumeration.
 *
 * Step 2 — Reset form (valid token in URL):
 *   User arrives via the emailed link. If the token is valid and not expired,
 *   the new password form is shown. On submission the password is updated,
 *   the token is cleared, and the user is redirected to login.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/mailer.php';
require_once __DIR__ . '/../../vendor/autoload.php';

session_start_secure();

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$db     = get_db();
$step   = 1;        // 1 = forgot form, 2 = reset form
$errors = [];
$success_message = '';

$token = trim($_GET['token'] ?? '');

// ── Determine step ────────────────────────────────────────────────────────

if (!empty($token)) {
    // Validate token
    $stmt = $db->prepare(
        'SELECT user_id, password_reset_expires
         FROM users
         WHERE password_reset_token = ?
           AND is_active = 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $token_row = $stmt->fetch();

    if (!$token_row || strtotime($token_row['password_reset_expires']) < time()) {
        $errors['token'] = 'This password reset link is invalid or has expired.
                            Please request a new one.';
    } else {
        $step = 2;
    }
}

// ── Step 1: Process forgot password form ─────────────────────────────────

if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['new_password'])) {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    } else {
        $stmt = $db->prepare(
            'SELECT user_id, first_name, last_name FROM users WHERE email = ? AND is_active = 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generate token
            $raw_token    = bin2hex(random_bytes(32));
            $hashed_token = hash('sha256', $raw_token);
            $expires      = date('Y-m-d H:i:s', time() + 900); // 15 minutes

            $db->prepare(
                'UPDATE users
                 SET password_reset_token = ?, password_reset_expires = ?
                 WHERE user_id = ?'
            )->execute([$hashed_token, $expires, $user['user_id']]);

            $name = trim($user['first_name'] . ' ' . $user['last_name']);
            send_password_reset($email, $name, $raw_token);
        }

        // Always show success to prevent user enumeration
        $success_message = 'If an account exists for that email address, a password
                            reset link has been sent. Please check your inbox.
                            The link will expire in 15 minutes.';
    }
}

// ── Step 2: Process reset form ────────────────────────────────────────────

if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $new_password  = $_POST['new_password']  ?? '';
    $new_password2 = $_POST['new_password2'] ?? '';

    if (strlen($new_password) < 8) {
        $errors['new_password'] = 'Password must be at least 8 characters.';
    } elseif ($new_password !== $new_password2) {
        $errors['new_password2'] = 'Passwords do not match.';
    } else {
        $db->prepare(
            'UPDATE users
             SET password_hash            = ?,
                 password_reset_token     = NULL,
                 password_reset_expires   = NULL,
                 password_expires         = DATE_ADD(NOW(), INTERVAL 180 DAY)
             WHERE user_id = ?'
        )->execute([
            password_hash($new_password, PASSWORD_BCRYPT),
            $token_row['user_id'],
        ]);

        $success_message = 'Your password has been reset successfully.';
        $step = 3; // done
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PORPASS — Reset Password</title>
    <link href="/resources/css/bootstrap.min.css" rel="stylesheet">
    <link href="/resources/css/porpass.css"       rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/index.php">PORPASS</a>
        <ul class="navbar-nav ms-auto">
            <li class="nav-item">
                <a class="nav-link btn btn-outline-light btn-sm px-3 ms-2"
                   href="/login.php">Sign In</a>
            </li>
        </ul>
    </div>
</nav>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">

            <?php if (isset($errors['token'])): ?>

                <!-- Invalid / expired token -->
                <div class="alert alert-danger">
                    <?= htmlspecialchars($errors['token']) ?>
                </div>
                <a href="/auth/reset.php" class="btn btn-primary">Request New Link</a>

            <?php elseif ($step === 3): ?>

                <!-- Success — password changed -->
                <div class="alert alert-success">
                    <h4 class="alert-heading">Password Reset</h4>
                    <p><?= htmlspecialchars($success_message) ?></p>
                    <a href="/login.php" class="btn btn-success btn-sm">Sign In</a>
                </div>

            <?php elseif ($step === 2): ?>

                <!-- Step 2: New password form -->
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h2 class="card-title h5 mb-4">Choose a New Password</h2>

                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                Please correct the errors below.
                            </div>
                        <?php endif; ?>

                        <form method="POST"
                              action="/auth/reset.php?token=<?= urlencode($token) ?>"
                              novalidate>

                            <div class="mb-3">
                                <label for="new_password" class="form-label">
                                    New Password <span class="text-danger">*</span>
                                </label>
                                <input type="password"
                                       class="form-control <?= isset($errors['new_password']) ? 'is-invalid' : '' ?>"
                                       id="new_password" name="new_password"
                                       required>
                                <div class="form-text">Minimum 8 characters.</div>
                                <?php if (isset($errors['new_password'])): ?>
                                    <div class="invalid-feedback">
                                        <?= htmlspecialchars($errors['new_password']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label for="new_password2" class="form-label">
                                    Confirm New Password <span class="text-danger">*</span>
                                </label>
                                <input type="password"
                                       class="form-control <?= isset($errors['new_password2']) ? 'is-invalid' : '' ?>"
                                       id="new_password2" name="new_password2"
                                       required>
                                <?php if (isset($errors['new_password2'])): ?>
                                    <div class="invalid-feedback">
                                        <?= htmlspecialchars($errors['new_password2']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    Reset Password
                                </button>
                            </div>

                        </form>
                    </div>
                </div>

            <?php else: ?>

                <!-- Step 1: Forgot password form -->
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h2 class="card-title h5 mb-1">Forgot Your Password?</h2>
                        <p class="text-muted small mb-4">
                            Enter your email address and we'll send you a reset link.
                        </p>

                        <?php if ($success_message): ?>
                            <div class="alert alert-success">
                                <?= htmlspecialchars($success_message) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!$success_message): ?>
                        <form method="POST" action="/auth/reset.php" novalidate>

                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email"
                                       class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                                       id="email" name="email"
                                       required autofocus>
                                <?php if (isset($errors['email'])): ?>
                                    <div class="invalid-feedback">
                                        <?= htmlspecialchars($errors['email']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="d-grid mb-3">
                                <button type="submit" class="btn btn-primary">
                                    Send Reset Link
                                </button>
                            </div>

                            <p class="text-center small">
                                <a href="/login.php">Back to Sign In</a>
                            </p>

                        </form>
                        <?php endif; ?>

                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<script src="/resources/js/bootstrap.bundle.min.js"></script>
</body>
</html>