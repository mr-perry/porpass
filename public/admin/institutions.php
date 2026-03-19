<?php
/**
 * institutions.php — Admin page for managing institutions and departments.
 *
 * Provides four sections for each type (institution and department):
 *   1. Pending — review and approve/reject user-submitted entries
 *   2. Add New — add a new institution or department directly as approved
 *   3. Edit    — update an existing approved institution or department
 *   4. Approved — table with edit and hard-delete actions
 *
 * Hard deleting an institution or department sets affected users'
 * institution_id or department_id to NULL via ON DELETE SET NULL.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_admin();

$db = get_db();

// ── Handle POST actions ───────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']  ?? '';
    $type    = $_POST['type']    ?? '';
    $item_id = (int)($_POST['item_id'] ?? 0);

    // ── Institution actions ───────────────────────────────────────────────
    if ($type === 'institution') {

        if ($action === 'approve' && $item_id > 0) {
            $db->prepare(
                'UPDATE institutions
                 SET institution      = ?,
                     institution_abbr = ?,
                     country_code     = ?,
                     is_approved      = 1,
                     approved_by      = ?,
                     approved_at      = NOW()
                 WHERE institution_id = ?'
            )->execute([
                trim($_POST['institution']      ?? ''),
                trim($_POST['institution_abbr'] ?? '') ?: null,
                trim($_POST['country_code']     ?? '') ?: null,
                $_SESSION['user_id'],
                $item_id,
            ]);

        } elseif ($action === 'reject' && $item_id > 0) {
            $db->prepare(
                'DELETE FROM institutions WHERE institution_id = ? AND is_approved = 0'
            )->execute([$item_id]);

        } elseif ($action === 'add') {
            $institution = trim($_POST['institution'] ?? '');
            if (!empty($institution)) {
                $db->prepare(
                    'INSERT INTO institutions
                        (institution, institution_abbr, country_code,
                         is_approved, approved_by, approved_at, created_by)
                     VALUES (?, ?, ?, 1, ?, NOW(), ?)'
                )->execute([
                    $institution,
                    trim($_POST['institution_abbr'] ?? '') ?: null,
                    trim($_POST['country_code']     ?? '') ?: null,
                    $_SESSION['user_id'],
                    $_SESSION['user_id'],
                ]);
            }

        } elseif ($action === 'edit' && $item_id > 0) {
            $db->prepare(
                'UPDATE institutions
                 SET institution      = ?,
                     institution_abbr = ?,
                     country_code     = ?
                 WHERE institution_id = ?'
            )->execute([
                trim($_POST['institution']      ?? ''),
                trim($_POST['institution_abbr'] ?? '') ?: null,
                trim($_POST['country_code']     ?? '') ?: null,
                $item_id,
            ]);

        } elseif ($action === 'delete' && $item_id > 0) {
            $db->prepare(
                'DELETE FROM institutions WHERE institution_id = ?'
            )->execute([$item_id]);
        }
    }

    // ── Department actions ────────────────────────────────────────────────
    if ($type === 'department') {

        if ($action === 'approve' && $item_id > 0) {
            $db->prepare(
                'UPDATE departments
                 SET department      = ?,
                     department_abbr = ?,
                     is_approved     = 1,
                     approved_by     = ?,
                     approved_at     = NOW()
                 WHERE department_id = ?'
            )->execute([
                trim($_POST['department']      ?? ''),
                trim($_POST['department_abbr'] ?? '') ?: null,
                $_SESSION['user_id'],
                $item_id,
            ]);

        } elseif ($action === 'reject' && $item_id > 0) {
            $db->prepare(
                'DELETE FROM departments WHERE department_id = ? AND is_approved = 0'
            )->execute([$item_id]);

        } elseif ($action === 'add') {
            $department     = trim($_POST['department']      ?? '');
            $institution_id = (int)($_POST['institution_id'] ?? 0);
            if (!empty($department) && $institution_id > 0) {
                $db->prepare(
                    'INSERT INTO departments
                        (institution_id, department, department_abbr,
                         is_approved, approved_by, approved_at, created_by)
                     VALUES (?, ?, ?, 1, ?, NOW(), ?)'
                )->execute([
                    $institution_id,
                    $department,
                    trim($_POST['department_abbr'] ?? '') ?: null,
                    $_SESSION['user_id'],
                    $_SESSION['user_id'],
                ]);
            }

        } elseif ($action === 'edit' && $item_id > 0) {
            $db->prepare(
                'UPDATE departments
                 SET institution_id  = ?,
                     department      = ?,
                     department_abbr = ?
                 WHERE department_id = ?'
            )->execute([
                (int)($_POST['institution_id']  ?? 0),
                trim($_POST['department']       ?? ''),
                trim($_POST['department_abbr']  ?? '') ?: null,
                $item_id,
            ]);

        } elseif ($action === 'delete' && $item_id > 0) {
            $db->prepare(
                'DELETE FROM departments WHERE department_id = ?'
            )->execute([$item_id]);
        }
    }

    header('Location: /admin/institutions.php');
    exit;
}

// ── Fetch data ────────────────────────────────────────────────────────────

$pending_institutions = $db->query(
    'SELECT i.institution_id, i.institution, i.institution_abbr,
            i.country_code, i.created_at,
            u.username AS submitted_by
     FROM institutions i
     LEFT JOIN users u ON i.created_by = u.user_id
     WHERE i.is_approved = 0
     ORDER BY i.created_at ASC'
)->fetchAll();

$pending_departments = $db->query(
    'SELECT d.department_id, d.department, d.department_abbr,
            d.institution_id, d.created_at,
            i.institution, i.institution_abbr AS inst_abbr,
            u.username AS submitted_by
     FROM departments d
     JOIN institutions i ON d.institution_id = i.institution_id
     LEFT JOIN users u ON d.created_by = u.user_id
     WHERE d.is_approved = 0
     ORDER BY d.created_at ASC'
)->fetchAll();

$approved_institutions = $db->query(
    'SELECT i.institution_id, i.institution, i.institution_abbr,
            i.country_code, i.approved_at,
            a.username AS approved_by,
            COUNT(u.user_id) AS user_count
     FROM institutions i
     LEFT JOIN users a ON i.approved_by = a.user_id
     LEFT JOIN users u ON u.institution_id = i.institution_id
     WHERE i.is_approved = 1
     GROUP BY i.institution_id
     ORDER BY i.institution'
)->fetchAll();

$approved_departments = $db->query(
    'SELECT d.department_id, d.department, d.department_abbr,
            d.institution_id, d.approved_at,
            i.institution, i.institution_abbr AS inst_abbr,
            a.username AS approved_by,
            COUNT(u.user_id) AS user_count
     FROM departments d
     JOIN institutions i ON d.institution_id = i.institution_id
     LEFT JOIN users a ON d.approved_by = a.user_id
     LEFT JOIN users u ON u.department_id = d.department_id
     WHERE d.is_approved = 1
     GROUP BY d.department_id
     ORDER BY i.institution, d.department'
)->fetchAll();

// Approved institutions for department dropdowns
$inst_options = $db->query(
    'SELECT institution_id, institution, institution_abbr
     FROM institutions WHERE is_approved = 1 ORDER BY institution'
)->fetchAll();

$total_pending = count($pending_institutions) + count($pending_departments);

open_layout('Manage Institutions & Departments');
?>

<div class="row mb-3">
    <div class="col">
        <h2>
            Manage Institutions &amp; Departments
            <?php if ($total_pending > 0): ?>
                <span class="badge bg-warning text-dark ms-2"><?= $total_pending ?></span>
            <?php endif; ?>
        </h2>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- INSTITUTIONS                                                            -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<h3 class="border-bottom pb-2 mb-4">Institutions</h3>

<!-- ── Pending Institutions ───────────────────────────────────────────────── -->
<h5 class="mb-3">
    Pending Review
    <?php if (!empty($pending_institutions)): ?>
        <span class="badge bg-warning text-dark ms-1"><?= count($pending_institutions) ?></span>
    <?php endif; ?>
</h5>

<?php if (empty($pending_institutions)): ?>
    <div class="alert alert-info mb-4">No institutions pending review.</div>
<?php else: ?>
<div class="mb-4">
<?php foreach ($pending_institutions as $inst): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            Submitted <?= htmlspecialchars($inst['created_at']) ?>
            <?= $inst['submitted_by']
                ? ' by <strong>' . htmlspecialchars($inst['submitted_by']) . '</strong>'
                : '' ?>
        </span>
        <span class="badge bg-warning text-dark">Pending</span>
    </div>
    <div class="card-body">
        <form method="POST" action="/admin/institutions.php">
            <input type="hidden" name="type"    value="institution">
            <input type="hidden" name="item_id" value="<?= $inst['institution_id'] ?>">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Institution Name</label>
                    <input type="text" class="form-control" name="institution"
                           value="<?= htmlspecialchars($inst['institution'] ?? '') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Abbreviation</label>
                    <input type="text" class="form-control" name="institution_abbr"
                           value="<?= htmlspecialchars($inst['institution_abbr'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Country Code</label>
                    <input type="text" class="form-control" name="country_code"
                           maxlength="2" placeholder="e.g. US"
                           value="<?= htmlspecialchars($inst['country_code'] ?? '') ?>">
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" name="action" value="approve"
                        class="btn btn-success btn-sm">Approve</button>
                <button type="submit" name="action" value="reject"
                        class="btn btn-danger btn-sm"
                        onclick="return confirm('Permanently delete this pending institution?')">
                    Reject
                </button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Add Institution ────────────────────────────────────────────────────── -->
<h5 class="mb-3">Add Institution</h5>
<div class="card shadow-sm mb-5">
    <div class="card-body">
        <form method="POST" action="/admin/institutions.php">
            <input type="hidden" name="type"   value="institution">
            <input type="hidden" name="action" value="add">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Institution Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="institution"
                           placeholder="e.g. University of Arizona" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Abbreviation</label>
                    <input type="text" class="form-control" name="institution_abbr"
                           placeholder="e.g. UA">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Country Code</label>
                    <input type="text" class="form-control" name="country_code"
                           maxlength="2" placeholder="e.g. US">
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Add Institution</button>
        </form>
    </div>
</div>

<!-- ── Approved Institutions ──────────────────────────────────────────────── -->
<h5 class="mb-3">Approved Institutions</h5>

<?php if (empty($approved_institutions)): ?>
    <div class="alert alert-info mb-5">No approved institutions yet.</div>
<?php else: ?>
<div class="table-responsive mb-5">
    <table class="table table-striped table-hover align-middle">
        <thead class="table-dark">
            <tr>
                <th>Institution</th>
                <th>Abbr.</th>
                <th>Country</th>
                <th>Users</th>
                <th>Approved By</th>
                <th>Approved At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($approved_institutions as $inst): ?>
            <tr>
                <!-- Inline edit form -->
                <form method="POST" action="/admin/institutions.php">
                    <input type="hidden" name="type"    value="institution">
                    <input type="hidden" name="action"  value="edit">
                    <input type="hidden" name="item_id" value="<?= $inst['institution_id'] ?>">
                    <td>
                        <input type="text" class="form-control form-control-sm"
                               name="institution"
                               value="<?= htmlspecialchars($inst['institution']) ?>"
                               required>
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm"
                               name="institution_abbr"
                               value="<?= htmlspecialchars($inst['institution_abbr'] ?? '') ?>">
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm"
                               name="country_code" maxlength="2"
                               value="<?= htmlspecialchars($inst['country_code'] ?? '') ?>">
                    </td>
                    <td><?= (int)$inst['user_count'] ?></td>
                    <td><?= htmlspecialchars($inst['approved_by'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($inst['approved_at'] ?? '—') ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                        </form>
                        <form method="POST" action="/admin/institutions.php"
                              style="display:inline;">
                            <input type="hidden" name="type"    value="institution">
                            <input type="hidden" name="action"  value="delete">
                            <input type="hidden" name="item_id" value="<?= $inst['institution_id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm"
                                    onclick="return confirm('Permanently delete this institution? <?= (int)$inst['user_count'] ?> user(s) will have their institution cleared.')">
                                Delete
                            </button>
                        </form>
                        </div>
                    </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>


<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- DEPARTMENTS                                                             -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<h3 class="border-bottom pb-2 mb-4">Departments</h3>

<!-- ── Pending Departments ────────────────────────────────────────────────── -->
<h5 class="mb-3">
    Pending Review
    <?php if (!empty($pending_departments)): ?>
        <span class="badge bg-warning text-dark ms-1"><?= count($pending_departments) ?></span>
    <?php endif; ?>
</h5>

<?php if (empty($pending_departments)): ?>
    <div class="alert alert-info mb-4">No departments pending review.</div>
<?php else: ?>
<div class="mb-4">
<?php foreach ($pending_departments as $dept): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <span class="text-muted">Institution:</span>
            <strong>
                <?= htmlspecialchars($dept['institution']) ?>
                <?= $dept['inst_abbr'] ? '(' . htmlspecialchars($dept['inst_abbr']) . ')' : '' ?>
            </strong>
            &mdash;
            Submitted <?= htmlspecialchars($dept['created_at']) ?>
            <?= $dept['submitted_by']
                ? ' by <strong>' . htmlspecialchars($dept['submitted_by']) . '</strong>'
                : '' ?>
        </span>
        <span class="badge bg-warning text-dark">Pending</span>
    </div>
    <div class="card-body">
        <form method="POST" action="/admin/institutions.php">
            <input type="hidden" name="type"    value="department">
            <input type="hidden" name="item_id" value="<?= $dept['department_id'] ?>">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Department Name</label>
                    <input type="text" class="form-control" name="department"
                           value="<?= htmlspecialchars($dept['department'] ?? '') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Abbreviation</label>
                    <input type="text" class="form-control" name="department_abbr"
                           value="<?= htmlspecialchars($dept['department_abbr'] ?? '') ?>">
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" name="action" value="approve"
                        class="btn btn-success btn-sm">Approve</button>
                <button type="submit" name="action" value="reject"
                        class="btn btn-danger btn-sm"
                        onclick="return confirm('Permanently delete this pending department?')">
                    Reject
                </button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Add Department ─────────────────────────────────────────────────────── -->
<h5 class="mb-3">Add Department</h5>
<div class="card shadow-sm mb-5">
    <div class="card-body">
        <form method="POST" action="/admin/institutions.php">
            <input type="hidden" name="type"   value="department">
            <input type="hidden" name="action" value="add">
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Institution <span class="text-danger">*</span></label>
                    <select class="form-select" name="institution_id" required>
                        <option value="">— Select institution —</option>
                        <?php foreach ($inst_options as $inst): ?>
                            <option value="<?= $inst['institution_id'] ?>">
                                <?= htmlspecialchars($inst['institution']) ?>
                                <?= $inst['institution_abbr']
                                    ? '(' . htmlspecialchars($inst['institution_abbr']) . ')'
                                    : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Department Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="department"
                           placeholder="e.g. Department of Planetary Sciences" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Abbreviation</label>
                    <input type="text" class="form-control" name="department_abbr"
                           placeholder="e.g. DPS">
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Add Department</button>
        </form>
    </div>
</div>

<!-- ── Approved Departments ───────────────────────────────────────────────── -->
<h5 class="mb-3">Approved Departments</h5>

<?php if (empty($approved_departments)): ?>
    <div class="alert alert-info">No approved departments yet.</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-striped table-hover align-middle">
        <thead class="table-dark">
            <tr>
                <th>Institution</th>
                <th>Department</th>
                <th>Abbr.</th>
                <th>Users</th>
                <th>Approved By</th>
                <th>Approved At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($approved_departments as $dept): ?>
            <tr>
                <form method="POST" action="/admin/institutions.php">
                    <input type="hidden" name="type"    value="department">
                    <input type="hidden" name="action"  value="edit">
                    <input type="hidden" name="item_id" value="<?= $dept['department_id'] ?>">
                    <td>
                        <input type="hidden" name="institution_id" value="<?= $dept['institution_id'] ?>">
                        <?= htmlspecialchars($dept['institution_abbr'] ?? $dept['institution']) ?>
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm"
                               name="department"
                               value="<?= htmlspecialchars($dept['department']) ?>"
                               required>
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm"
                               name="department_abbr"
                               value="<?= htmlspecialchars($dept['department_abbr'] ?? '') ?>">
                    </td>
                    <td><?= (int)$dept['user_count'] ?></td>
                    <td><?= htmlspecialchars($dept['approved_by'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($dept['approved_at'] ?? '—') ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                        </form>
                        <form method="POST" action="/admin/institutions.php"
                              style="display:inline;">
                            <input type="hidden" name="type"    value="department">
                            <input type="hidden" name="action"  value="delete">
                            <input type="hidden" name="item_id" value="<?= $dept['department_id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm"
                                    onclick="return confirm('Permanently delete this department? <?= (int)$dept['user_count'] ?> user(s) will have their department cleared.')">
                                Delete
                            </button>
                        </form>
                        </div>
                    </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php close_layout(); ?>