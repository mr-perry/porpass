<?php
/**
 * institutions.php — Admin page for reviewing and approving pending institutions
 * and departments submitted by users during registration.
 *
 * Pending entries (is_approved = 0) are displayed for review. Admins can
 * approve an entry as-is, edit the name and abbreviation before approving,
 * or reject (delete) the entry. Approved institutions become available in
 * the registration dropdown for future users.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_admin();

$db = get_db();

// ── Handle POST actions ───────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action         = $_POST['action']          ?? '';
    $institution_id = (int)($_POST['institution_id'] ?? 0);

    if ($institution_id > 0) {

        if ($action === 'approve') {
            $institution      = trim($_POST['institution']      ?? '');
            $institution_abbr = trim($_POST['institution_abbr'] ?? '') ?: null;
            $department       = trim($_POST['department']       ?? '') ?: null;
            $department_abbr  = trim($_POST['department_abbr']  ?? '') ?: null;
            $country_code     = trim($_POST['country_code']     ?? '') ?: null;

            $db->prepare(
                'UPDATE institutions
                 SET institution      = ?,
                     institution_abbr = ?,
                     department       = ?,
                     department_abbr  = ?,
                     country_code     = ?,
                     is_approved      = 1,
                     approved_by      = ?,
                     approved_at      = NOW()
                 WHERE institution_id = ?'
            )->execute([
                $institution,
                $institution_abbr,
                $department,
                $department_abbr,
                $country_code,
                $_SESSION['user_id'],
                $institution_id,
            ]);

        } elseif ($action === 'reject') {
            // Only delete if still pending — never delete approved institutions
            $db->prepare(
                'DELETE FROM institutions
                 WHERE institution_id = ? AND is_approved = 0'
            )->execute([$institution_id]);
        }
    }

    header('Location: /admin/institutions.php');
    exit;
}

// ── Fetch pending institutions ────────────────────────────────────────────

$pending = $db->query(
    'SELECT i.institution_id, i.institution, i.institution_abbr,
            i.department, i.department_abbr, i.country_code,
            i.created_at,
            u.username AS submitted_by
     FROM institutions i
     LEFT JOIN users u ON i.created_by = u.user_id
     WHERE i.is_approved = 0
     ORDER BY i.created_at ASC'
)->fetchAll();

// ── Fetch approved institutions ───────────────────────────────────────────

$approved = $db->query(
    'SELECT i.institution_id, i.institution, i.institution_abbr,
            i.department, i.department_abbr, i.country_code,
            i.created_at, i.approved_at,
            a.username AS approved_by
     FROM institutions i
     LEFT JOIN users a ON i.approved_by = a.user_id
     WHERE i.is_approved = 1
     ORDER BY i.institution, i.department'
)->fetchAll();

open_layout('Manage Institutions');
?>

<div class="row mb-3">
    <div class="col">
        <h2>Manage Institutions</h2>
    </div>
</div>

<!-- ── Pending ────────────────────────────────────────────────────────────── -->
<h4 class="mb-3">
    Pending Review
    <?php if (!empty($pending)): ?>
        <span class="badge bg-warning text-dark ms-2"><?= count($pending) ?></span>
    <?php endif; ?>
</h4>

<?php if (empty($pending)): ?>
    <div class="alert alert-info mb-5">No institutions pending review.</div>
<?php else: ?>

<div class="mb-5">
<?php foreach ($pending as $inst): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            Submitted <?= htmlspecialchars($inst['created_at']) ?>
            <?= $inst['submitted_by'] ? ' by <strong>' . htmlspecialchars($inst['submitted_by']) . '</strong>' : '' ?>
        </span>
        <span class="badge bg-warning text-dark">Pending</span>
    </div>
    <div class="card-body">
        <form method="POST" action="/admin/institutions.php">
            <input type="hidden" name="institution_id"
                   value="<?= $inst['institution_id'] ?>">

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Institution Name</label>
                    <input type="text" class="form-control" name="institution"
                           value="<?= htmlspecialchars($inst['institution'] ?? '') ?>"
                           required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Abbreviation</label>
                    <input type="text" class="form-control" name="institution_abbr"
                           value="<?= htmlspecialchars($inst['institution_abbr'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Country Code</label>
                    <input type="text" class="form-control" name="country_code"
                           maxlength="2"
                           placeholder="e.g. US"
                           value="<?= htmlspecialchars($inst['country_code'] ?? '') ?>">
                </div>
            </div>

            <?php if ($inst['department']): ?>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Department Name</label>
                    <input type="text" class="form-control" name="department"
                           value="<?= htmlspecialchars($inst['department']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Dept. Abbreviation</label>
                    <input type="text" class="form-control" name="department_abbr"
                           value="<?= htmlspecialchars($inst['department_abbr'] ?? '') ?>">
                </div>
            </div>
            <?php else: ?>
                <input type="hidden" name="department"      value="">
                <input type="hidden" name="department_abbr" value="">
            <?php endif; ?>

            <div class="d-flex gap-2">
                <button type="submit" name="action" value="approve"
                        class="btn btn-success btn-sm">
                    Approve
                </button>
                <button type="submit" name="action" value="reject"
                        class="btn btn-danger btn-sm"
                        onclick="return confirm('Delete this pending institution? This cannot be undone.')">
                    Reject
                </button>
            </div>

        </form>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<!-- ── Approved ───────────────────────────────────────────────────────────── -->
<h4 class="mb-3">Approved Institutions</h4>

<?php if (empty($approved)): ?>
    <div class="alert alert-info">No approved institutions yet.</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-striped table-hover align-middle">
        <thead class="table-dark">
            <tr>
                <th>Institution</th>
                <th>Abbr.</th>
                <th>Department</th>
                <th>Dept. Abbr.</th>
                <th>Country</th>
                <th>Approved By</th>
                <th>Approved At</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($approved as $inst): ?>
            <tr>
                <td><?= htmlspecialchars($inst['institution'] ?? '—') ?></td>
                <td><?= htmlspecialchars($inst['institution_abbr'] ?? '—') ?></td>
                <td><?= htmlspecialchars($inst['department'] ?? '—') ?></td>
                <td><?= htmlspecialchars($inst['department_abbr'] ?? '—') ?></td>
                <td><?= htmlspecialchars($inst['country_code'] ?? '—') ?></td>
                <td><?= htmlspecialchars($inst['approved_by'] ?? '—') ?></td>
                <td><?= htmlspecialchars($inst['approved_at'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php close_layout(); ?>