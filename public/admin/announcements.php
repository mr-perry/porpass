<?php
/**
 * announcements.php — Admin page for managing system announcements.
 *
 * Allows admins to create, edit, activate/deactivate, and delete
 * announcements that appear on the user dashboard. Announcements
 * expire after 90 days by default but the expiry date can be overridden.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_admin();

$db     = get_db();
$errors = [];
$success = '';

// ── Handle POST actions ───────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action          = $_POST['action']          ?? '';
    $announcement_id = (int)($_POST['announcement_id'] ?? 0);

    if ($action === 'add') {
        $title      = trim($_POST['title']      ?? '');
        $body       = trim($_POST['body']       ?? '');
        $expires_at = trim($_POST['expires_at'] ?? '');
        $is_active  = isset($_POST['is_active']) ? 1 : 0;

        if (empty($title))  $errors['title'] = 'Title is required.';
        if (empty($body))   $errors['body']  = 'Body is required.';

        if (empty($errors)) {
            $expires = !empty($expires_at)
                ? $expires_at
                : date('Y-m-d H:i:s', strtotime('+90 days'));

            $db->prepare(
                'INSERT INTO announcements (title, body, is_active, created_by, expires_at)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$title, $body, $is_active, $_SESSION['user_id'], $expires]);
            $success = 'Announcement added.';
        }

    } elseif ($action === 'edit' && $announcement_id > 0) {
        $title      = trim($_POST['title']      ?? '');
        $body       = trim($_POST['body']       ?? '');
        $expires_at = trim($_POST['expires_at'] ?? '');
        $is_active  = isset($_POST['is_active']) ? 1 : 0;

        if (empty($title))  $errors['title'] = 'Title is required.';
        if (empty($body))   $errors['body']  = 'Body is required.';

        if (empty($errors)) {
            $db->prepare(
                'UPDATE announcements
                 SET title = ?, body = ?, is_active = ?, expires_at = ?
                 WHERE announcement_id = ?'
            )->execute([$title, $body, $is_active, $expires_at, $announcement_id]);
            $success = 'Announcement updated.';
        }

    } elseif ($action === 'toggle' && $announcement_id > 0) {
        $db->prepare(
            'UPDATE announcements SET is_active = NOT is_active
             WHERE announcement_id = ?'
        )->execute([$announcement_id]);
        header('Location: /admin/announcements.php');
        exit;

    } elseif ($action === 'delete' && $announcement_id > 0) {
        $db->prepare(
            'DELETE FROM announcements WHERE announcement_id = ?'
        )->execute([$announcement_id]);
        header('Location: /admin/announcements.php');
        exit;
    }
}

// ── Fetch all announcements ───────────────────────────────────────────────

$announcements = $db->query(
    'SELECT a.announcement_id, a.title, a.body, a.is_active,
            a.created_at, a.expires_at,
            u.username AS created_by
     FROM announcements a
     JOIN users u ON a.created_by = u.user_id
     ORDER BY a.created_at DESC'
)->fetchAll();

open_layout('Manage Announcements');
?>

<div class="row mb-3">
    <div class="col">
        <h2>Manage Announcements</h2>
        <p class="text-muted">
            Announcements appear on the user dashboard. They expire after 90 days
            by default and are only shown to users when active and not expired.
        </p>
    </div>
</div>

<!-- ── Add Announcement ───────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-5">
    <div class="card-header"><strong>Add Announcement</strong></div>
    <div class="card-body">

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST" action="/admin/announcements.php">
            <input type="hidden" name="action" value="add">

            <div class="mb-3">
                <label for="title" class="form-label">
                    Title <span class="text-danger">*</span>
                </label>
                <input type="text"
                       class="form-control <?= isset($errors['title']) ? 'is-invalid' : '' ?>"
                       id="title" name="title"
                       value="<?= htmlspecialchars($_POST['title'] ?? '') ?>"
                       required>
                <?php if (isset($errors['title'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['title']) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="body" class="form-label">
                    Body <span class="text-danger">*</span>
                </label>
                <textarea class="form-control <?= isset($errors['body']) ? 'is-invalid' : '' ?>"
                          id="body" name="body" rows="4"
                          placeholder="Announcement text. Basic HTML is supported."
                          required><?= htmlspecialchars($_POST['body'] ?? '') ?></textarea>
                <?php if (isset($errors['body'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['body']) ?></div>
                <?php endif; ?>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label for="expires_at" class="form-label">
                        Expires At
                    </label>
                    <input type="datetime-local"
                           class="form-control"
                           id="expires_at" name="expires_at"
                           value="<?= date('Y-m-d\TH:i', strtotime('+90 days')) ?>">
                    <div class="form-text">Defaults to 90 days from now.</div>
                </div>
                <div class="col-md-4 d-flex align-items-center">
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox"
                               id="is_active" name="is_active" checked>
                        <label class="form-check-label" for="is_active">
                            Active (visible to users immediately)
                        </label>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-sm">
                Post Announcement
            </button>
        </form>
    </div>
</div>

<!-- ── Existing Announcements ─────────────────────────────────────────────── -->
<h4 class="mb-3">All Announcements</h4>

<?php if (empty($announcements)): ?>
    <div class="alert alert-info">No announcements yet.</div>
<?php else: ?>

<?php foreach ($announcements as $ann): ?>
<?php
    $is_expired = strtotime($ann['expires_at']) < time();
    $status_class = $ann['is_active'] && !$is_expired
        ? 'border-success'
        : 'border-secondary';
?>
<div class="card shadow-sm mb-3 <?= $status_class ?>" style="border-left-width: 4px;">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <strong><?= htmlspecialchars($ann['title']) ?></strong>
            <span class="ms-2">
                <?php if ($is_expired): ?>
                    <span class="badge bg-secondary">Expired</span>
                <?php elseif ($ann['is_active']): ?>
                    <span class="badge bg-success">Active</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark">Inactive</span>
                <?php endif; ?>
            </span>
        </span>
        <span class="text-muted small">
            Posted by <?= htmlspecialchars($ann['created_by']) ?>
            on <?= htmlspecialchars($ann['created_at']) ?>
            &mdash; Expires <?= htmlspecialchars($ann['expires_at']) ?>
        </span>
    </div>
    <div class="card-body">

        <!-- Edit form -->
        <form method="POST" action="/admin/announcements.php" class="mb-3">
            <input type="hidden" name="action"          value="edit">
            <input type="hidden" name="announcement_id" value="<?= $ann['announcement_id'] ?>">

            <div class="mb-2">
                <input type="text" class="form-control form-control-sm"
                       name="title"
                       value="<?= htmlspecialchars($ann['title']) ?>"
                       required>
            </div>
            <div class="mb-2">
                <textarea class="form-control form-control-sm"
                          name="body" rows="3"
                          required><?= htmlspecialchars($ann['body']) ?></textarea>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-md-4">
                    <input type="datetime-local"
                           class="form-control form-control-sm"
                           name="expires_at"
                           value="<?= date('Y-m-d\TH:i', strtotime($ann['expires_at'])) ?>">
                </div>
                <div class="col-md-4 d-flex align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox"
                               name="is_active"
                               <?= $ann['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label small">Active</label>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Save</button>

                <!-- Toggle active/inactive -->
                </form>
                <form method="POST" action="/admin/announcements.php"
                      style="display:inline;">
                    <input type="hidden" name="action"          value="toggle">
                    <input type="hidden" name="announcement_id" value="<?= $ann['announcement_id'] ?>">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                        <?= $ann['is_active'] ? 'Deactivate' : 'Activate' ?>
                    </button>
                </form>

                <!-- Delete -->
                <form method="POST" action="/admin/announcements.php"
                      style="display:inline;">
                    <input type="hidden" name="action"          value="delete">
                    <input type="hidden" name="announcement_id" value="<?= $ann['announcement_id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm"
                            onclick="return confirm('Permanently delete this announcement?')">
                        Delete
                    </button>
                </form>
            </div>
        </form>

    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php close_layout(); ?>