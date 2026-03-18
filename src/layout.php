<?php
/**
 * layout.php — Shared page layout for PORPASS.
 *
 * Provides open_layout() and close_layout() functions that wrap page content
 * in a consistent HTML shell with Bootstrap navigation. Call open_layout() at
 * the top of each page and close_layout() at the bottom.
 */

/**
 * Output the opening HTML, head, and navigation bar.
 *
 * Left side of the navbar contains primary navigation (Dashboard, Browse
 * Observations, Processing). Right side contains Documentation, the Admin
 * dropdown (admin users only), and the username/account dropdown.
 *
 * @param string $title Page title appended to "PORPASS —" in the <title> element.
 */
function open_layout(string $title = 'PORPASS'): void {
    $username = htmlspecialchars($_SESSION['username'] ?? '');
    $role     = $_SESSION['role'] ?? 'user';
    $is_admin = $role === 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PORPASS — <?= htmlspecialchars($title) ?></title>
    <link href="/resources/css/bootstrap.min.css" rel="stylesheet">
    <link href="/resources/css/porpass.css"       rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">

        <a class="navbar-brand fw-bold" href="/dashboard.php">PORPASS</a>

        <button class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#porpassNav"
                aria-controls="porpassNav"
                aria-expanded="false"
                aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="porpassNav">

            <!-- Left side: primary navigation -->
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">

                <li class="nav-item">
                    <a class="nav-link" href="/dashboard.php">Dashboard</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="/observations.php">Browse Observations</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="/submit.php">Processing</a>
                </li>

            </ul>

            <!-- Right side: Documentation, Admin (admin only), username -->
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">

                <li class="nav-item">
                    <a class="nav-link" href="/docs.php">Documentation</a>
                </li>

                <?php if ($is_admin): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        Admin
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
                        <li>
                            <a class="dropdown-item" href="/admin/users.php">Manage Users</a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/admin/instruments.php">Manage Instruments</a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/admin/bodies.php">Manage Bodies</a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/admin/institutions.php">Manage Institutions</a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="/admin/docs.php">Admin Documentation</a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <?= $username ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
                        <li>
                            <a class="dropdown-item" href="/account.php">Account Settings</a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="/logout.php">Sign Out</a>
                        </li>
                    </ul>
                </li>

            </ul>

        </div>

    </div>
</nav>

<main class="container-fluid py-4">
<?php
}

/**
 * Output the closing HTML including the page footer and Bootstrap JS.
 */
function close_layout(): void {
?>
</main>

<footer class="border-top bg-secondary text-white py-4 mt-5">
    <div class="container text-center">
        <img src="/resources/img/PSI_Logo.png"
             alt="Planetary Science Institute"
             class="mb-3"
             style="max-height: 150px;">
        <p class="mb-1">
            PORPASS is hosted by <strong>The Planetary Science Institute</strong><br>
            1700 East Fort Lowell, Suite 106, Tucson, AZ 85719-2395 &mdash; (520) 622-6300
        </p>
        <p class="text-white-50 small mt-2">
            Development funded by the NASA Planetary Data Archival, Restoration, and Tools
            (PDART) Program, grant number 80NSSC20K1057.
        </p>
        <p class="text-white-50 small">
            PORPASS &mdash; Planetary Orbital Radar Processing and Simulation System
        </p>
    </div>
</footer>

<script src="/resources/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}