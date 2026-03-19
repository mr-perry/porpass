<?php
/**
 * change_requests.php — Admin page for reviewing pending user change requests.
 *
 * Displays all pending institution and department change requests submitted
 * by users via Account Settings. Admins can approve or reject each request.
 * Approved institution/department changes are applied immediately to the
 * users table. Name and email changes are handled automatically and will
 * appear in the log with status 'approved' for audit purposes.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_admin();

$db = get_db();

// ── Handle POST actions ───────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action']     ?? '';
    $request_id = (int)($_POST['request_id'] ?? 0);
    $notes      = trim($_POST['notes'] ?? '');

    if ($request_id > 0) {
        // Fetch the request
        $stmt = $db->prepare(
            'SELECT * FROM user_change_requests WHERE request_id = ? AND status = ?'
        );
        $stmt->execute([$request_id, 'pending']);
        $request = $stmt->fetch();

        if ($request) {
            if ($action === 'approve') {
                // Apply the change to the users table
                if ($request['field'] === 'institution_id') {
                    $db->prepare(
                        'UPDATE users SET institution_id = ? WHERE user_id = ?'
                    )->execute([$request['new_value'], $request['user_id']]);
                } elseif ($request['field'] === 'department_id') {
                    $db->prepare(
                        'UPDATE users SET department_id = ? WHERE user_id = ?'
                    )->execute([$request['new_value'], $request['user_id']]);
                }

                // Mark request as approved
                $db->prepare(
                    'UPDATE user_change_requests
                     SET status      = ?,
                         reviewed_by = ?,
                         reviewed_at = NOW(),
                         notes       = ?
                     WHERE request_id = ?'
                )->execute(['approved', $_SESSION['user_id'], $notes, $request_id]);

            } elseif ($action === 'reject') {
                $db->prepare(
                    'UPDATE user_change_requests
                     SET status      = ?,
                         reviewed_by = ?,
                         reviewed_at = NOW(),
                         notes       = ?
                     WHERE request_id = ?'
                )->execute(['rejected', $_SESSION['user_id'], $notes, $request_id]);
            }
        }
    }

    header('Location: /admin/change_requests.php');
    exit;
}

// ── Fetch pending institution/department change requests ──────────────────

$pending = $db->query(
    'SELECT cr.request_id, cr.user_id, cr.field, cr.old_value, cr.new_value,
            cr.requested_at,
            u.username, u.first_name, u.last_name, u.email,
            i_old.institution    AS old_institution,
            i_old.institution_abbr AS old_institution_abbr,
            i_old.department     AS old_department,
            i_new.institution    AS new_institution,
            i_new.institution_abbr AS new_institution_abbr,
            i_new.department     AS new_department
     FROM user_change_requests cr
     JOIN users u ON cr.user_id = u.user_id
     LEFT JOIN institutions i_old ON cr.old_value = i_old.institution_id
     LEFT JOIN institutions i_new ON cr.new_value = i_new.institution_id
     WHERE cr.status = \'pending\'
       AND cr.field IN (\'institution_id\', \'department_id\')
     ORDER BY cr.requested_at ASC'
)->fetchAll();

// ── Fetch full change log (all statuses, all fields) ──────────────────────

$log = $db->query(
    'SELECT cr.request_id, cr.field, cr.old_value, cr.new_value,
            cr.status, cr.requested_at, cr.reviewed_at, cr.notes,
            u.username,
            r.username AS reviewed_by_username
     FROM user_change_requests cr
     JOIN users u ON cr.user_id = u.user_id
     LEFT JOIN users r ON cr.reviewed_by = r.user_id
     ORDER BY cr.requested_at DESC
     LIMIT 100'
)->fetchAll();

open_layout('Change Requests');
?>

<div class="row mb-3">
    <div class="col">
        <h2>Change Requests</h2>
    </div>
</div>

<!-- ── Pending institution/department changes ────────────────────────────── -->
<h4 class="mb-3">
    Pending Approval
    <?php if (!empty($pending)): ?>
        <span class="badge bg-warning text-dark ms-2"><?= count($pending) ?></span>
    <?php endif; ?>
</h4>

<?php if (empty($pending)): ?>
    <div class="alert alert-info mb-5">
        No institution or department changes pending approval.
    </div>
<?php else: ?>

<div class="mb-5">
<?php foreach ($pending as $req): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <strong><?= htmlspecialchars($req['username']) ?></strong>
            (<?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?>)
            &mdash; <?= htmlspecialchars($req['email']) ?>
        </span>
        <span class="text-muted small">
            <?= htmlspecialchars($req['requested_at']) ?>
        </span>
    </div>
    <div class="card-body">

        <dl class="row mb-3">
            <dt class="col-sm-3">Field</dt>
            <dd class="col-sm-9">
                <span class="badge bg-secondary">
                    <?= htmlspecialchars($req['field']) ?>
                </span>
            </dd>

            <dt class="col-sm-3">Current Value</dt>
            <dd class="col-sm-9">
                <?php if ($req['field'] === 'institution_id'): ?>
                    <?= $req['old_institution']
                        ? htmlspecialchars($req['old_institution'])
                          . ($req['old_institution_abbr']
                              ? ' (' . htmlspecialchars($req['old_institution_abbr']) . ')'
                              : '')
                        : '<em class="text-muted">None</em>' ?>
                <?php else: ?>
                    <?= $req['old_department']
                        ? htmlspecialchars($req['old_department'])
                        : '<em class="text-muted">None</em>' ?>
                <?php endif; ?>
            </dd>

            <dt class="col-sm-3">Requested Value</dt>
            <dd class="col-sm-9">
                <?php if ($req['field'] === 'institution_id'): ?>
                    <?php if ($req['new_institution']): ?>
                        <?= htmlspecialchars($req['new_institution']) ?>
                        <?= $req['new_institution_abbr']
                            ? '(' . htmlspecialchars($req['new_institution_abbr']) . ')'
                            : '' ?>
                        <?php if (!$req['new_institution_abbr']): ?>
                            <span class="badge bg-warning text-dark ms-1">
                                Pending institution approval
                            </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <em class="text-muted">Institution ID: <?= htmlspecialchars($req['new_value']) ?></em>
                    <?php endif; ?>
                <?php else: ?>
                    <?= $req['new_department']
                        ? htmlspecialchars($req['new_department'])
                        : '<em class="text-muted">Department ID: ' . htmlspecialchars($req['new_value']) . '</em>' ?>
                <?php endif; ?>
            </dd>
        </dl>

        <form method="POST" action="/admin/change_requests.php">
            <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
            <div class="mb-3">
                <label for="notes_<?= $req['request_id'] ?>" class="form-label">
                    Notes (optional)
                </label>
                <input type="text"
                       class="form-control form-control-sm"
                       id="notes_<?= $req['request_id'] ?>"
                       name="notes"
                       placeholder="Reason for approval or rejection">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" name="action" value="approve"
                        class="btn btn-success btn-sm">
                    Approve
                </button>
                <button type="submit" name="action" value="reject"
                        class="btn btn-danger btn-sm"
                        onclick="return confirm('Reject this change request?')">
                    Reject
                </button>
            </div>
        </form>

    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<!-- ── Change log ─────────────────────────────────────────────────────────── -->
<h4 class="mb-3">Change Log</h4>

<?php if (empty($log)): ?>
    <div class="alert alert-info">No change requests recorded yet.</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-striped table-hover align-middle small">
        <thead class="table-dark">
            <tr>
                <th>User</th>
                <th>Field</th>
                <th>Old Value</th>
                <th>New Value</th>
                <th>Status</th>
                <th>Requested</th>
                <th>Reviewed By</th>
                <th>Reviewed At</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($log as $entry): ?>
            <tr>
                <td><?= htmlspecialchars($entry['username']) ?></td>
                <td>
                    <span class="badge bg-secondary">
                        <?= htmlspecialchars($entry['field']) ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($entry['old_value'] ?? '—') ?></td>
                <td><?= htmlspecialchars($entry['new_value'] ?? '—') ?></td>
                <td>
                    <?php
                    $badge = match($entry['status']) {
                        'approved' => 'bg-success',
                        'rejected' => 'bg-danger',
                        'expired'  => 'bg-secondary',
                        default    => 'bg-warning text-dark',
                    };
                    ?>
                    <span class="badge <?= $badge ?>">
                        <?= htmlspecialchars($entry['status']) ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($entry['requested_at']) ?></td>
                <td><?= htmlspecialchars($entry['reviewed_by_username'] ?? '—') ?></td>
                <td><?= htmlspecialchars($entry['reviewed_at'] ?? '—') ?></td>
                <td><?= htmlspecialchars($entry['notes'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php close_layout(); ?>