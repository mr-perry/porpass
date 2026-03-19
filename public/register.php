<?php
/**
 * register.php — New user registration page.
 *
 * Handles display and processing of the registration form. On successful
 * submission, creates a new user with is_active = 0 pending admin approval.
 * New institutions and departments submitted via the "Other" option are
 * inserted with is_approved = 0 and require separate admin review.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/mailer.php';
require_once __DIR__ . '/../vendor/autoload.php';

session_start_secure();

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$errors  = [];
$success = false;
$old     = [];

// Load approved institutions for dropdown
$db           = get_db();
$institutions = $db->query(
    'SELECT DISTINCT institution_id, institution, institution_abbr
     FROM institutions
     WHERE institution IS NOT NULL
       AND is_approved = 1
     ORDER BY institution'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;

    $first_name    = trim($_POST['first_name']        ?? '');
    $last_name     = trim($_POST['last_name']         ?? '');
    $username      = trim($_POST['username']          ?? '');
    $email         = trim($_POST['email']             ?? '');
    $password      = $_POST['password']               ?? '';
    $password2     = $_POST['password2']              ?? '';
    $inst_id       = (int)($_POST['institution_id']   ?? 0);
    $inst_other    = trim($_POST['institution_other'] ?? '');
    $dept_id       = (int)($_POST['department_id']    ?? 0);
    $dept_other    = trim($_POST['department_other']  ?? '');
    $access_reason = trim($_POST['access_reason']     ?? '');

    // ── Validation ────────────────────────────────────────────────────────
    if (empty($first_name))
        $errors['first_name']  = 'First name is required.';
    if (empty($last_name))
        $errors['last_name']   = 'Last name is required.';
    if (empty($username))
        $errors['username']    = 'Username is required.';
    if (empty($email))
        $errors['email']       = 'Email address is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors['email']       = 'Please enter a valid email address.';
    if (strlen($password) < 8)
        $errors['password']    = 'Password must be at least 8 characters.';
    if ($password !== $password2)
        $errors['password2']   = 'Passwords do not match.';
    if ($inst_id === 0 && empty($inst_other))
        $errors['institution'] = 'Please select or enter an institution.';
    if (empty($access_reason))
        $errors['access_reason'] = 'Please briefly describe why you are requesting access.';

    // Check username and email uniqueness
    if (empty($errors['username'])) {
        $stmt = $db->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch())
            $errors['username'] = 'This username is already taken.';
    }
    if (empty($errors['email'])) {
        $stmt = $db->prepare('SELECT user_id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch())
            $errors['email'] = 'An account with this email address already exists.';
    }

    // ── Insert ────────────────────────────────────────────────────────────
    if (empty($errors)) {

        // Handle "Other" institution — insert pending row
        if ($inst_id === -1 && !empty($inst_other)) {
            $stmt = $db->prepare(
                'INSERT INTO institutions (institution, is_approved, created_by)
                 VALUES (?, 0, NULL)'
            );
            $stmt->execute([$inst_other]);
            $inst_id = (int)$db->lastInsertId();
        }

        // Handle "Other" department — insert pending row into departments table
        $final_dept_id = null;
        if ($dept_id === -1 && !empty($dept_other)) {
            $stmt = $db->prepare(
                'INSERT INTO departments
                    (institution_id, department, is_approved, created_by)
                 VALUES (?, ?, 0, NULL)'
            );
            $stmt->execute([$inst_id, $dept_other]);
            $final_dept_id = (int)$db->lastInsertId();
        } elseif ($dept_id > 0) {
            $final_dept_id = $dept_id;
        }

        // Generate email verification token
        $raw_token    = bin2hex(random_bytes(32));
        $hashed_token = hash('sha256', $raw_token);
        $expires      = date('Y-m-d H:i:s', time() + 86400); // 24 hours

        $stmt = $db->prepare(
            'INSERT INTO users
                (first_name, last_name, username, email, password_hash,
                 institution_id, department_id, access_reason,
                 role, is_active, email_verified,
                 email_verify_token, email_verify_expires)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'user\', 0, 0, ?, ?)'
        );
        $stmt->execute([
            $first_name,
            $last_name,
            $username,
            $email,
            password_hash($password, PASSWORD_BCRYPT),
            $inst_id ?: null,
            $final_dept_id,
            $access_reason,
            $hashed_token,
            $expires,
        ]);

        // Send verification email
        $name = trim($first_name . ' ' . $last_name);
        send_email_verification($email, $name, $raw_token);

        $success = true;
        $old     = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PORPASS — Create Account</title>
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
        <div class="col-md-7">

            <h1 class="h3 mb-1">Create an Account</h1>
            <p class="text-muted mb-4">
                Account requests are reviewed and approved by a PORPASS administrator.
            </p>

            <?php if ($success): ?>

                <div class="alert alert-success">
                    <h4 class="alert-heading">Request Submitted</h4>
                    <p>
                        Thank you for registering. We have sent a verification email
                        to your address — please click the link in that email to verify
                        your account.
                    </p>
                    <p class="mb-0">
                        Once your email is verified, your account will be reviewed and
                        approved by a PORPASS administrator before you can sign in.
                    </p>
                    <?php if (isset($inst_id) && $inst_id > 0): ?>
                    <p class="mb-0 small mt-2">
                        If you submitted a new institution or department, these will also
                        be reviewed by an administrator.
                    </p>
                    <?php endif; ?>
                    <a href="/login.php" class="btn btn-success btn-sm mt-3">Go to Sign In</a>
                </div>

            <?php else: ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    Please correct the errors below before submitting.
                </div>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                <form method="POST" action="/register.php" novalidate>

                    <!-- Name -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="first_name" class="form-label">
                                First Name <span class="text-danger">*</span>
                            </label>
                            <input type="text"
                                   class="form-control <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>"
                                   id="first_name" name="first_name"
                                   value="<?= htmlspecialchars($old['first_name'] ?? '') ?>"
                                   required>
                            <?php if (isset($errors['first_name'])): ?>
                                <div class="invalid-feedback">
                                    <?= htmlspecialchars($errors['first_name']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label for="last_name" class="form-label">
                                Last Name <span class="text-danger">*</span>
                            </label>
                            <input type="text"
                                   class="form-control <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>"
                                   id="last_name" name="last_name"
                                   value="<?= htmlspecialchars($old['last_name'] ?? '') ?>"
                                   required>
                            <?php if (isset($errors['last_name'])): ?>
                                <div class="invalid-feedback">
                                    <?= htmlspecialchars($errors['last_name']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Username -->
                    <div class="mb-3">
                        <label for="username" class="form-label">
                            Username <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                               id="username" name="username"
                               value="<?= htmlspecialchars($old['username'] ?? '') ?>"
                               required>
                        <?php if (isset($errors['username'])): ?>
                            <div class="invalid-feedback">
                                <?= htmlspecialchars($errors['username']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Email -->
                    <div class="mb-3">
                        <label for="email" class="form-label">
                            Email Address <span class="text-danger">*</span>
                        </label>
                        <input type="email"
                               class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                               id="email" name="email"
                               value="<?= htmlspecialchars($old['email'] ?? '') ?>"
                               required>
                        <?php if (isset($errors['email'])): ?>
                            <div class="invalid-feedback">
                                <?= htmlspecialchars($errors['email']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Password -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="password" class="form-label">
                                Password <span class="text-danger">*</span>
                            </label>
                            <input type="password"
                                   class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                                   id="password" name="password"
                                   required>
                            <div class="form-text">Minimum 8 characters.</div>
                            <?php if (isset($errors['password'])): ?>
                                <div class="invalid-feedback">
                                    <?= htmlspecialchars($errors['password']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label for="password2" class="form-label">
                                Confirm Password <span class="text-danger">*</span>
                            </label>
                            <input type="password"
                                   class="form-control <?= isset($errors['password2']) ? 'is-invalid' : '' ?>"
                                   id="password2" name="password2"
                                   required>
                            <?php if (isset($errors['password2'])): ?>
                                <div class="invalid-feedback">
                                    <?= htmlspecialchars($errors['password2']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Institution -->
                    <div class="mb-2">
                        <label for="institution_id" class="form-label">
                            Institution <span class="text-danger">*</span>
                        </label>
                        <select class="form-select <?= isset($errors['institution']) ? 'is-invalid' : '' ?>"
                                id="institution_id" name="institution_id">
                            <option value="0">— Select institution —</option>
                            <?php foreach ($institutions as $inst): ?>
                                <option value="<?= $inst['institution_id'] ?>"
                                    <?= (int)($old['institution_id'] ?? 0) === (int)$inst['institution_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($inst['institution']) ?>
                                    <?= $inst['institution_abbr'] ? '(' . htmlspecialchars($inst['institution_abbr']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="-1" <?= ($old['institution_id'] ?? '') === '-1' ? 'selected' : '' ?>>
                                Other — not listed
                            </option>
                        </select>
                        <?php if (isset($errors['institution'])): ?>
                            <div class="invalid-feedback">
                                <?= htmlspecialchars($errors['institution']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Institution other -->
                    <div class="mb-3" id="institution_other_wrap" style="display:none;">
                        <input type="text"
                               class="form-control"
                               id="institution_other"
                               name="institution_other"
                               placeholder="Enter your institution name"
                               value="<?= htmlspecialchars($old['institution_other'] ?? '') ?>">
                        <div class="form-text">
                            New institutions are reviewed and approved by an administrator.
                        </div>
                    </div>

                    <!-- Department -->
                    <div class="mb-2">
                        <label for="department_id" class="form-label">Department</label>
                        <select class="form-select" id="department_id" name="department_id">
                            <option value="0">— Select department (optional) —</option>
                            <option value="-1">Other — not listed</option>
                        </select>
                    </div>

                    <!-- Department other -->
                    <div class="mb-3" id="department_other_wrap" style="display:none;">
                        <input type="text"
                               class="form-control"
                               id="department_other"
                               name="department_other"
                               placeholder="Enter your department name"
                               value="<?= htmlspecialchars($old['department_other'] ?? '') ?>">
                        <div class="form-text">
                            New departments are reviewed and approved by an administrator.
                        </div>
                    </div>

                    <!-- Access reason -->
                    <div class="mb-3">
                        <label for="access_reason" class="form-label">
                            Reason for Requesting Access <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control <?= isset($errors['access_reason']) ? 'is-invalid' : '' ?>"
                                  id="access_reason" name="access_reason"
                                  rows="3"
                                  placeholder="Briefly describe your research interests and intended use of PORPASS."
                                  required><?= htmlspecialchars($old['access_reason'] ?? '') ?></textarea>
                        <?php if (isset($errors['access_reason'])): ?>
                            <div class="invalid-feedback">
                                <?= htmlspecialchars($errors['access_reason']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary">Submit Request</button>
                    </div>

                    <p class="text-center small">
                        Already have an account? <a href="/login.php">Sign in</a>
                    </p>

                </form>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<script src="/resources/js/bootstrap.bundle.min.js"></script>
<script>
// ── Institution / Department dynamic behaviour ────────────────────────────

const instSelect     = document.getElementById('institution_id');
const instOtherWrap  = document.getElementById('institution_other_wrap');
const instOtherInput = document.getElementById('institution_other');
const deptSelect     = document.getElementById('department_id');
const deptOtherWrap  = document.getElementById('department_other_wrap');

instSelect.addEventListener('change', function () {
    const isOther = this.value === '-1';
    instOtherWrap.style.display = isOther ? 'block' : 'none';
    instOtherInput.required     = isOther;
    resetDepartments();
    if (this.value > 0) {
        fetchDepartments(this.value);
    }
});

deptSelect.addEventListener('change', function () {
    deptOtherWrap.style.display = this.value === '-1' ? 'block' : 'none';
});

function resetDepartments() {
    deptSelect.innerHTML =
        '<option value="0">— Select department (optional) —</option>' +
        '<option value="-1">Other — not listed</option>';
    deptOtherWrap.style.display = 'none';
}

function fetchDepartments(institutionId) {
    fetch('/api/departments.php?institution_id=' + institutionId)
        .then(r => r.json())
        .then(data => {
            resetDepartments();
            data.forEach(dept => {
                if (dept.department) {
                    const opt       = document.createElement('option');
                    opt.value       = dept.department_id;
                    opt.textContent = dept.department +
                        (dept.department_abbr ? ' (' + dept.department_abbr + ')' : '');
                    deptSelect.insertBefore(opt, deptSelect.lastElementChild);
                }
            });
        })
        .catch(() => { /* silently ignore fetch errors */ });
}

// Restore "Other" fields if form was resubmitted with errors
(function () {
    if (instSelect.value === '-1') {
        instOtherWrap.style.display = 'block';
        instOtherInput.required     = true;
    } else if (instSelect.value > 0) {
        fetchDepartments(instSelect.value);
    }
})();
</script>

</body>
</html>