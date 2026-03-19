<?php
/**
 * verify_email_change.php — Email change verification.
 *
 * Called when a user clicks the confirmation link sent to their new email
 * address after submitting an email change request via Account Settings.
 * Validates the token, updates the user's email address, and marks the
 * change request as approved.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

session_start_secure();

$db    = get_db();
$token = trim($_GET['token'] ?? '');
$state = 'invalid'; // invalid | expired | success

if (!empty($token)) {
    $hashed = hash('sha256', $token);

    $stmt = $db->prepare(
        'SELECT request_id, user_id, old_value, new_value, token_expires
         FROM user_change_requests
         WHERE verification_token = ?
           AND field  = ?
           AND status = ?'
    );
    $stmt->execute([$hashed, 'email', 'pending']);
    $request = $stmt->fetch();

    if (!$request) {
        $state = 'invalid';
    } elseif (strtotime($request['token_expires']) < time()) {
        // Mark as expired
        $db->prepare(
            'UPDATE user_change_requests SET status = ? WHERE request_id = ?'
        )->execute(['expired', $request['request_id']]);
        $state = 'expired';
    } else {
        // Apply the email change
        $db->prepare(
            'UPDATE users SET email = ? WHERE user_id = ?'
        )->execute([$request['new_value'], $request['user_id']]);

        // Mark the request as approved
        $db->prepare(
            'UPDATE user_change_requests
             SET status      = ?,
                 reviewed_at = NOW()
             WHERE request_id = ?'
        )->execute(['approved', $request['request_id']]);

        // If this user is currently logged in, update their session email
        if (is_logged_in() && $_SESSION['user_id'] === (int)$request['user_id']) {
            $_SESSION['email'] = $request['new_value'];
        }

        $state = 'success';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PORPASS — Email Change Confirmation</title>
    <link href="/resources/css/bootstrap.min.css" rel="stylesheet">
    <link href="/resources/css/porpass.css"       rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/index.php">PORPASS</a>
        <ul class="navbar-nav ms-auto">
            <?php if (is_logged_in()): ?>
            <li class="nav-item">
                <a class="nav-link" href="/account.php">Account Settings</a>
            </li>
            <?php else: ?>
            <li class="nav-item">
                <a class="nav-link btn btn-outline-light btn-sm px-3 ms-2"
                   href="/login.php">Sign In</a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
</nav>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">

            <?php if ($state === 'success'): ?>

                <div class="alert alert-success">
                    <h4 class="alert-heading">Email Address Updated</h4>
                    <p>
                        Your email address has been successfully updated to
                        <strong><?= htmlspecialchars($request['new_value']) ?></strong>.
                    </p>
                    <?php if (is_logged_in()): ?>
                        <a href="/account.php" class="btn btn-success btn-sm">
                            Back to Account Settings
                        </a>
                    <?php else: ?>
                        <a href="/login.php" class="btn btn-success btn-sm">Sign In</a>
                    <?php endif; ?>
                </div>

            <?php elseif ($state === 'expired'): ?>

                <div class="alert alert-warning">
                    <h4 class="alert-heading">Link Expired</h4>
                    <p>
                        This email change confirmation link has expired.
                        Please submit a new email change request from your
                        Account Settings.
                    </p>
                    <?php if (is_logged_in()): ?>
                        <a href="/account.php" class="btn btn-warning btn-sm">
                            Account Settings
                        </a>
                    <?php else: ?>
                        <a href="/login.php" class="btn btn-warning btn-sm">Sign In</a>
                    <?php endif; ?>
                </div>

            <?php else: ?>

                <div class="alert alert-danger">
                    <h4 class="alert-heading">Invalid Link</h4>
                    <p>
                        This confirmation link is invalid or has already been used.
                        If you need to change your email address, please submit a
                        new request from your Account Settings.
                    </p>
                    <?php if (is_logged_in()): ?>
                        <a href="/account.php" class="btn btn-danger btn-sm">
                            Account Settings
                        </a>
                    <?php else: ?>
                        <a href="/login.php" class="btn btn-danger btn-sm">Sign In</a>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<script src="/resources/js/bootstrap.bundle.min.js"></script>
</body>
</html>