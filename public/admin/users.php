<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_admin();

$db = get_db();

// Handle approve / reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']  ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    if ($user_id > 0) {
        if ($action === 'approve') {
            $db->prepare(
                'UPDATE users SET is_active = 1, approved_by = ?, approved_at = NOW()
                 WHERE user_id = ?'
            )->execute([$_SESSION['user_id'], $user_id]);
        } elseif ($action === 'reject') {
            $db->prepare('DELETE FROM users WHERE user_id = ? AND is_active = 0')
               ->execute([$user_id]);
        }
    }
    header('Location: /admin/users.php');
    exit;
}

// Fetch all users
$users = $db->query(
    'SELECT u.user_id, u.username, u.email, u.role, u.is_active,
            u.email_verified, u.created_at, u.last_login_at,
            i.institution_abbr
     FROM users u
     LEFT JOIN institutions i ON u.institution_id = i.institution_id
     ORDER BY u.is_active ASC, u.created_at DESC'
)->fetchAll();

open_layout('Manage Users');
?>

<div class="row mb-3">
    <div class="col">
        <h2>Manage Users</h2>
    </div>
</div>

<?php if (empty($users)): ?>
    <div class="alert alert-info">No users found.</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-striped table-hover align-middle">
        <thead class="table-dark">
            <tr>
                <th>Username</th>
                <th>Email</th>
                <th>Status</th>
                <th>Institution</th>
                <th>Role</th>
                <th>Status</th>
                <th>Registered</th>
                <th>Last Login</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td>
                    <?php if ($u['email_verified']): ?>
                        <span class="badge bg-success">Verified</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Unverified</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($u['institution_abbr'] ?? '—') ?></td>
                <td>
                    <span class="badge <?= $u['role'] === 'admin' ? 'bg-danger' : 'bg-secondary' ?>">
                        <?= htmlspecialchars($u['role']) ?>
                    </span>
                </td>
                <td>
                    <?php if ($u['is_active']): ?>
                        <span class="badge bg-success">Active</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">Pending</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($u['created_at']) ?></td>
                <td><?= $u['last_login_at'] ? htmlspecialchars($u['last_login_at']) : '—' ?></td>
                <td>
                    <?php if (!$u['is_active']): ?>
                    <form method="POST" action="/admin/users.php" class="d-inline">
                        <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                        <button type="submit" name="action" value="approve"
                                class="btn btn-success btn-sm">Approve</button>
                        <button type="submit" name="action" value="reject"
                                class="btn btn-danger btn-sm"
                                onclick="return confirm('Delete this user?')">Reject</button>
                    </form>
                    <?php else: ?>
                        <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php close_layout(); ?>