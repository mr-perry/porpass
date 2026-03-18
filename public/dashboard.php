<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/layout.php';

session_start_secure();
require_login();

open_layout('Dashboard');
?>

<div class="row">
    <div class="col-12">
        <h2>Dashboard</h2>
        <p class="text-muted">
            Welcome back, <strong><?= htmlspecialchars($_SESSION['username']) ?></strong>.
        </p>
    </div>
</div>

<?php close_layout(); ?>