<?php
/**
 * account.php — User account settings page.
 *
 * Allows authenticated users to update their name, email address, password,
 * and institution/department. All changes are logged to user_change_requests
 * for audit purposes. Name changes are applied immediately. Email changes
 * require verification of the new address. Institution/department changes
 * require admin approval.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/mailer.php';
require_once __DIR__ . '/../src/layout.php';
require_once __DIR__ . '/../vendor/autoload.php';

session_start_secure();
require_login();

$db      = get_db();
$user_id = $_SESSION['user_id'];

// ── Fetch current user data ───────────────────────────────────────────────

$stmt = $db->prepare(
    'SELECT u.user_id, u.first_name, u.last_name, u.username, u.email,
            u.role, u.is_active, u.email_verified, u.created_at,
            u.last_login_at, u.institution_id, u.department_id,
            i.institution, i.institution_abbr,
            d.department, d.department_abbr
     FROM users u
     LEFT JOIN institutions i ON u.institution_id = i.institution_id
     LEFT JOIN departments d  ON u.department_id  = d.department_id
     WHERE u.user_id = ?'
);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// ── Fetch approved institutions for dropdown ──────────────────────────────

$institutions = $db->query(
    'SELECT DISTINCT institution_id, institution, institution_abbr
     FROM institutions
     WHERE institution IS NOT NULL AND is_approved = 1
     ORDER BY institution'
)->fetchAll();

// ── Fetch any pending change requests for this user ───────────────────────

$pending = $db->prepare(
    'SELECT field, new_value, requested_at
     FROM user_change_requests
     WHERE user_id = ? AND status = ?'
);
$pending->execute([$user_id, 'pending']);
$pending_changes = [];
foreach ($pending->fetchAll() as $row) {
    $pending_changes[$row['field']] = $row;
}

// ── Messages ──────────────────────────────────────────────────────────────

$success = [];
$errors  = [];

// ── Handle POST ───────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['section'] ?? '';

    // ── Name ─────────────────────────────────────────────────────────────
    if ($section === 'name') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name']  ?? '');

        if (empty($first_name))
            $errors['first_name'] = 'First name is required.';
        if (empty($last_name))
            $errors['last_name'] = 'Last name is required.';

        if (empty($errors)) {
            // Log first name change if changed
            if ($first_name !== $user['first_name']) {
                $db->prepare(
                    'INSERT INTO user_change_requests
                        (user_id, field, old_value, new_value, status, reviewed_by, reviewed_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())'
                )->execute([
                    $user_id, 'first_name',
                    $user['first_name'], $first_name,
                    'approved', $user_id
                ]);
            }
            // Log last name change if changed
            if ($last_name !== $user['last_name']) {
                $db->prepare(
                    'INSERT INTO user_change_requests
                        (user_id, field, old_value, new_value, status, reviewed_by, reviewed_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())'
                )->execute([
                    $user_id, 'last_name',
                    $user['last_name'], $last_name,
                    'approved', $user_id
                ]);
            }
            // Apply immediately
            $db->prepare(
                'UPDATE users SET first_name = ?, last_name = ? WHERE user_id = ?'
            )->execute([$first_name, $last_name, $user_id]);

            $success['name'] = 'Your name has been updated.';
            // Refresh user data
            $user['first_name'] = $first_name;
            $user['last_name']  = $last_name;
        }
    }

    // ── Email ─────────────────────────────────────────────────────────────
    if ($section === 'email') {
        $new_email       = trim($_POST['new_email']       ?? '');
        $current_password = $_POST['current_password_email'] ?? '';

        if (empty($new_email) || !filter_var($new_email, FILTER_VALIDATE_EMAIL))
            $errors['new_email'] = 'Please enter a valid email address.';
        elseif ($new_email === $user['email'])
            $errors['new_email'] = 'This is already your current email address.';
        if (empty($current_password))
            $errors['current_password_email'] = 'Please enter your current password.';

        if (empty($errors)) {
            // Verify current password
            $stmt = $db->prepare('SELECT password_hash FROM users WHERE user_id = ?');
            $stmt->execute([$user_id]);
            $row = $stmt->fetch();

            if (!password_verify($current_password, $row['password_hash'])) {
                $errors['current_password_email'] = 'Current password is incorrect.';
            } else {
                // Check email not already in use
                $stmt = $db->prepare('SELECT user_id FROM users WHERE email = ?');
                $stmt->execute([$new_email]);
                if ($stmt->fetch()) {
                    $errors['new_email'] = 'This email address is already in use.';
                } else {
                    // Generate verification token
                    $raw_token    = bin2hex(random_bytes(32));
                    $hashed_token = hash('sha256', $raw_token);
                    $expires      = date('Y-m-d H:i:s', time() + 86400);

                    // Cancel any existing pending email change
                    $db->prepare(
                        'UPDATE user_change_requests
                         SET status = ?
                         WHERE user_id = ? AND field = ? AND status = ?'
                    )->execute(['expired', $user_id, 'email', 'pending']);

                    // Log the change request
                    $db->prepare(
                        'INSERT INTO user_change_requests
                            (user_id, field, old_value, new_value,
                             status, verification_token, token_expires)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $user_id, 'email',
                        $user['email'], $new_email,
                        'pending', $hashed_token, $expires
                    ]);

                    // Send verification email to new address
                    $name = trim($user['first_name'] . ' ' . $user['last_name']);
                    send_email_change_verification($new_email, $name, $raw_token);

                    $success['email'] = 'A verification email has been sent to '
                                      . htmlspecialchars($new_email)
                                      . '. Click the link in that email to confirm the change.';
                }
            }
        }
    }

    // ── Password ──────────────────────────────────────────────────────────
    if ($section === 'password') {
        $current_password = $_POST['current_password_pw'] ?? '';
        $new_password     = $_POST['new_password']        ?? '';
        $new_password2    = $_POST['new_password2']       ?? '';

        if (empty($current_password))
            $errors['current_password_pw'] = 'Please enter your current password.';
        if (strlen($new_password) < 8)
            $errors['new_password'] = 'New password must be at least 8 characters.';
        if ($new_password !== $new_password2)
            $errors['new_password2'] = 'Passwords do not match.';

        if (empty($errors)) {
            $stmt = $db->prepare('SELECT password_hash FROM users WHERE user_id = ?');
            $stmt->execute([$user_id]);
            $row = $stmt->fetch();

            if (!password_verify($current_password, $row['password_hash'])) {
                $errors['current_password_pw'] = 'Current password is incorrect.';
            } else {
                $db->prepare(
                    'UPDATE users
                     SET password_hash    = ?,
                         password_expires = DATE_ADD(NOW(), INTERVAL 180 DAY)
                     WHERE user_id = ?'
                )->execute([
                    password_hash($new_password, PASSWORD_BCRYPT),
                    $user_id
                ]);
                $success['password'] = 'Your password has been updated.';
            }
        }
    }

    // ── Institution / Department ──────────────────────────────────────────
    if ($section === 'institution') {
        $inst_id    = (int)($_POST['institution_id']    ?? 0);
        $inst_other = trim($_POST['institution_other']  ?? '');
        $dept_id    = (int)($_POST['department_id']     ?? 0);
        $dept_other = trim($_POST['department_other']   ?? '');

        if ($inst_id === 0 && empty($inst_other))
            $errors['institution'] = 'Please select or enter an institution.';

        if (empty($errors)) {
            // Handle "Other" institution
            if ($inst_id === -1 && !empty($inst_other)) {
                $db->prepare(
                    'INSERT INTO institutions (institution, is_approved, created_by)
                     VALUES (?, 0, ?)'
                )->execute([$inst_other, $user_id]);
                $inst_id = (int)$db->lastInsertId();
            }

            // Handle "Other" department
            $final_dept_id = null;
            if ($dept_id === -1 && !empty($dept_other)) {
                $db->prepare(
                    'INSERT INTO departments
                        (institution_id, department, is_approved, created_by)
                     VALUES (?, ?, 0, ?)'
                )->execute([$inst_id, $dept_other, $user_id]);
                $final_dept_id = (int)$db->lastInsertId();
            } elseif ($dept_id > 0) {
                $final_dept_id = $dept_id;
            }

            // Log institution change request — pending admin approval
            if ($inst_id !== (int)$user['institution_id']) {
                // Cancel any existing pending institution change
                $db->prepare(
                    'UPDATE user_change_requests
                     SET status = ?
                     WHERE user_id = ? AND field = ? AND status = ?'
                )->execute(['expired', $user_id, 'institution_id', 'pending']);

                $db->prepare(
                    'INSERT INTO user_change_requests
                        (user_id, field, old_value, new_value, status)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $user_id, 'institution_id',
                    $user['institution_id'], $inst_id,
                    'pending'
                ]);
            }

            // Log department change request — pending admin approval
            if ($final_dept_id !== (int)$user['department_id']) {
                $db->prepare(
                    'UPDATE user_change_requests
                     SET status = ?
                     WHERE user_id = ? AND field = ? AND status = ?'
                )->execute(['expired', $user_id, 'department_id', 'pending']);

                $db->prepare(
                    'INSERT INTO user_change_requests
                        (user_id, field, old_value, new_value, status)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $user_id, 'department_id',
                    $user['department_id'], $final_dept_id,
                    'pending'
                ]);
            }

            $success['institution'] = 'Your institution change request has been submitted
                                       and is pending administrator approval.';
        }
    }
}

open_layout('Account Settings');
?>

<div class="row justify-content-center">
<div class="col-md-8">

<h2 class="mb-4">Account Settings</h2>

<!-- ── Account Status ─────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-header"><strong>Account Status</strong></div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4">Username</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($user['username']) ?></dd>

            <dt class="col-sm-4">Role</dt>
            <dd class="col-sm-8">
                <span class="badge <?= $user['role'] === 'admin' ? 'bg-danger' : 'bg-secondary' ?>">
                    <?= htmlspecialchars($user['role']) ?>
                </span>
            </dd>

            <dt class="col-sm-4">Email Verified</dt>
            <dd class="col-sm-8">
                <?php if ($user['email_verified']): ?>
                    <span class="badge bg-success">Verified</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark">Unverified</span>
                    <a href="/auth/verify.php" class="ms-2 small">Resend verification email</a>
                <?php endif; ?>
            </dd>

            <dt class="col-sm-4">Account Status</dt>
            <dd class="col-sm-8">
                <?php if ($user['is_active']): ?>
                    <span class="badge bg-success">Active</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark">Pending Approval</span>
                <?php endif; ?>
            </dd>

            <dt class="col-sm-4">Member Since</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($user['created_at']) ?></dd>

            <dt class="col-sm-4">Last Sign In</dt>
            <dd class="col-sm-8">
                <?= $user['last_login_at']
                    ? htmlspecialchars($user['last_login_at'])
                    : '—' ?>
            </dd>
        </dl>
    </div>
</div>

<!-- ── Name ──────────────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-header"><strong>Name</strong></div>
    <div class="card-body">

        <?php if (isset($success['name'])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success['name']) ?></div>
        <?php endif; ?>

        <form method="POST" action="/account.php" novalidate>
            <input type="hidden" name="section" value="name">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label for="first_name" class="form-label">First Name</label>
                    <input type="text"
                           class="form-control <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>"
                           id="first_name" name="first_name"
                           value="<?= htmlspecialchars($_POST['first_name'] ?? $user['first_name']) ?>"
                           required>
                    <?php if (isset($errors['first_name'])): ?>
                        <div class="invalid-feedback">
                            <?= htmlspecialchars($errors['first_name']) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label for="last_name" class="form-label">Last Name</label>
                    <input type="text"
                           class="form-control <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>"
                           id="last_name" name="last_name"
                           value="<?= htmlspecialchars($_POST['last_name'] ?? $user['last_name']) ?>"
                           required>
                    <?php if (isset($errors['last_name'])): ?>
                        <div class="invalid-feedback">
                            <?= htmlspecialchars($errors['last_name']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Update Name</button>
        </form>
    </div>
</div>

<!-- ── Email ──────────────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-header"><strong>Email Address</strong></div>
    <div class="card-body">

        <?php if (isset($success['email'])): ?>
            <div class="alert alert-success"><?= $success['email'] ?></div>
        <?php endif; ?>

        <?php if (isset($pending_changes['email'])): ?>
            <div class="alert alert-info">
                A change to <strong><?= htmlspecialchars($pending_changes['email']['new_value']) ?></strong>
                is pending email verification.
                Requested <?= htmlspecialchars($pending_changes['email']['requested_at']) ?>.
            </div>
        <?php endif; ?>

        <p class="text-muted small">
            Current: <strong><?= htmlspecialchars($user['email']) ?></strong>
        </p>

        <form method="POST" action="/account.php" novalidate>
            <input type="hidden" name="section" value="email">
            <div class="mb-3">
                <label for="new_email" class="form-label">New Email Address</label>
                <input type="email"
                       class="form-control <?= isset($errors['new_email']) ? 'is-invalid' : '' ?>"
                       id="new_email" name="new_email"
                       value="<?= htmlspecialchars($_POST['new_email'] ?? '') ?>"
                       required>
                <?php if (isset($errors['new_email'])): ?>
                    <div class="invalid-feedback">
                        <?= htmlspecialchars($errors['new_email']) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="mb-3">
                <label for="current_password_email" class="form-label">
                    Current Password <span class="text-danger">*</span>
                </label>
                <input type="password"
                       class="form-control <?= isset($errors['current_password_email']) ? 'is-invalid' : '' ?>"
                       id="current_password_email"
                       name="current_password_email"
                       required>
                <?php if (isset($errors['current_password_email'])): ?>
                    <div class="invalid-feedback">
                        <?= htmlspecialchars($errors['current_password_email']) ?>
                    </div>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Update Email</button>
        </form>
    </div>
</div>

<!-- ── Password ───────────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-header"><strong>Password</strong></div>
    <div class="card-body">

        <?php if (isset($success['password'])): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success['password']) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/account.php" novalidate>
            <input type="hidden" name="section" value="password">
            <div class="mb-3">
                <label for="current_password_pw" class="form-label">
                    Current Password <span class="text-danger">*</span>
                </label>
                <input type="password"
                       class="form-control <?= isset($errors['current_password_pw']) ? 'is-invalid' : '' ?>"
                       id="current_password_pw"
                       name="current_password_pw"
                       required>
                <?php if (isset($errors['current_password_pw'])): ?>
                    <div class="invalid-feedback">
                        <?= htmlspecialchars($errors['current_password_pw']) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label for="new_password" class="form-label">New Password</label>
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
                <div class="col-md-6">
                    <label for="new_password2" class="form-label">Confirm New Password</label>
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
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Update Password</button>
        </form>
    </div>
</div>

<!-- ── Institution / Department ───────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-header"><strong>Institution &amp; Department</strong></div>
    <div class="card-body">

        <?php if (isset($success['institution'])): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success['institution']) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($pending_changes['institution_id']) ||
                  isset($pending_changes['department_id'])): ?>
            <div class="alert alert-info">
                An institution or department change is pending administrator approval.
            </div>
        <?php endif; ?>

        <p class="text-muted small mb-3">
            Current:
            <strong><?= htmlspecialchars($user['institution'] ?? '—') ?></strong>
            <?= $user['institution_abbr'] ? '(' . htmlspecialchars($user['institution_abbr']) . ')' : '' ?>
            <?php if ($user['department']): ?>
                &mdash; <?= htmlspecialchars($user['department']) ?>
                <?= $user['department_abbr'] ? '(' . htmlspecialchars($user['department_abbr']) . ')' : '' ?>
            <?php endif; ?>
        </p>

        <form method="POST" action="/account.php" novalidate>
            <input type="hidden" name="section" value="institution">

            <!-- Institution -->
            <div class="mb-2">
                <label for="institution_id" class="form-label">Institution</label>
                <select class="form-select <?= isset($errors['institution']) ? 'is-invalid' : '' ?>"
                        id="institution_id" name="institution_id">
                    <option value="0">— Select institution —</option>
                    <?php foreach ($institutions as $inst): ?>
                        <option value="<?= $inst['institution_id'] ?>"
                            <?= (int)$user['institution_id'] === (int)$inst['institution_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($inst['institution']) ?>
                            <?= $inst['institution_abbr'] ? '(' . htmlspecialchars($inst['institution_abbr']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="-1">Other — not listed</option>
                </select>
                <?php if (isset($errors['institution'])): ?>
                    <div class="invalid-feedback">
                        <?= htmlspecialchars($errors['institution']) ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mb-3" id="institution_other_wrap" style="display:none;">
                <input type="text" class="form-control"
                       id="institution_other" name="institution_other"
                       placeholder="Enter your institution name">
                <div class="form-text">New institutions require admin approval.</div>
            </div>

            <!-- Department -->
            <div class="mb-2">
                <label for="department_id" class="form-label">Department</label>
                <select class="form-select" id="department_id" name="department_id">
                    <option value="0">— Select department (optional) —</option>
                    <option value="-1">Other — not listed</option>
                </select>
            </div>

            <div class="mb-3" id="department_other_wrap" style="display:none;">
                <input type="text" class="form-control"
                       id="department_other" name="department_other"
                       placeholder="Enter your department name">
                <div class="form-text">New departments require admin approval.</div>
            </div>

            <div class="alert alert-warning small py-2">
                Institution and department changes require administrator approval
                before taking effect.
            </div>

            <button type="submit" class="btn btn-primary btn-sm">
                Submit Change Request
            </button>
        </form>
    </div>
</div>

</div>
</div>

<script src="/resources/js/bootstrap.bundle.min.js"></script>
<script>
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
    if (this.value > 0) fetchDepartments(this.value);
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
            // Pre-select current department if it belongs to this institution
            const currentDeptId = <?= (int)($user['department_id'] ?? 0) ?>;
            if (currentDeptId > 0) {
                deptSelect.value = currentDeptId;
            }
        })
        .catch(() => {});
}

// Pre-load departments for current institution on page load
(function () {
    const currentInstId = <?= (int)($user['institution_id'] ?? 0) ?>;
    if (currentInstId > 0) fetchDepartments(currentInstId);
})();
</script>

<?php close_layout(); ?>