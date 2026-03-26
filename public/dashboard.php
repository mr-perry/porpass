<?php
/**
 * dashboard.php — PORPASS user and admin dashboard.
 *
 * Displays system announcements, summary statistics, recent observations,
 * the user's recent processing jobs, and an interactive cumulative
 * processing chart. Admin users additionally see pending approval counts,
 * total user stats, and system health indicators.
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/layout.php';

session_start_secure();
//echo "Logged in as user_id: " . $_SESSION['user_id'] . " username: " . $_SESSION['username'];
require_login();

$db       = get_db();
$user_id  = $_SESSION['user_id'];
$is_admin = $_SESSION['role'] === 'admin';

// ── Announcements (active, not expired, last 90 days) ─────────────────────

$announcements = $db->query(
    'SELECT title, body, created_at
     FROM announcements
     WHERE is_active = 1
       AND expires_at > NOW()
     ORDER BY created_at DESC
     LIMIT 5'
)->fetchAll();

// ── Observation summary by instrument ─────────────────────────────────────

$obs_stats = $db->query(
    'SELECT i.instrument_abbr, COUNT(o.observation_id) AS obs_count
     FROM observations o
     JOIN instruments i ON o.instrument_id = i.instrument_id
     GROUP BY i.instrument_id
     ORDER BY obs_count DESC'
)->fetchAll();

$total_observations = array_sum(array_column($obs_stats, 'obs_count'));

// ── Recent observations (last 5) ──────────────────────────────────────────

$recent_observations = $db->query(
    'SELECT o.native_id, o.start_time, o.stop_time,
            i.instrument_abbr, b.body_name
     FROM observations o
     JOIN instruments i ON o.instrument_id = i.instrument_id
     JOIN bodies b      ON o.body_id       = b.body_id
     ORDER BY o.start_time DESC
     LIMIT 5'
)->fetchAll();

// ── User processing jobs (last 10) ────────────────────────────────────────

$recent_jobs = $db->prepare(
    'SELECT pj.job_id, pj.batch_id, pj.processing_type, pj.status,
            pj.submitted_at, pj.completed_at,
            o.native_id, i.instrument_abbr
     FROM processing_jobs pj
     JOIN observations o ON pj.observation_id = o.observation_id
     JOIN instruments i  ON pj.instrument_id  = i.instrument_id
     WHERE pj.user_id = ?
     ORDER BY pj.submitted_at DESC
     LIMIT 10'
);
$recent_jobs->execute([$user_id]);
$recent_jobs = $recent_jobs->fetchAll();

// ── Processing stats summary for current user ─────────────────────────────

$job_stats = $db->prepare(
    'SELECT pj.status, COUNT(*) AS cnt
     FROM processing_jobs pj
     WHERE pj.user_id = ?
     GROUP BY pj.status'
);
$job_stats->execute([$user_id]);
$job_counts = [];
foreach ($job_stats->fetchAll() as $row) {
    $job_counts[$row['status']] = (int)$row['cnt'];
}
$total_jobs = array_sum($job_counts);

// ── Admin-only stats ──────────────────────────────────────────────────────

if ($is_admin) {
    $pending_users = $db->query(
        'SELECT COUNT(*) FROM users WHERE is_active = 0 AND email_verified = 1'
    )->fetchColumn();

    $unverified_users = $db->query(
        'SELECT COUNT(*) FROM users WHERE email_verified = 0'
    )->fetchColumn();

    $pending_institutions = $db->query(
        'SELECT COUNT(*) FROM institutions WHERE is_approved = 0'
    )->fetchColumn();

    $pending_departments = $db->query(
        'SELECT COUNT(*) FROM departments WHERE is_approved = 0'
    )->fetchColumn();

    $pending_changes = $db->query(
        'SELECT COUNT(*) FROM user_change_requests WHERE status = \'pending\''
    )->fetchColumn();

    $total_users = $db->query(
        'SELECT COUNT(*) FROM users WHERE is_active = 1'
    )->fetchColumn();

    $total_all_jobs = $db->query(
        'SELECT COUNT(*) FROM processing_jobs'
    )->fetchColumn();
}

open_layout('Dashboard');
?>

<div class="row mb-4">
    <div class="col">
        <h2>
            Welcome back, <?= htmlspecialchars($_SESSION['username']) ?>
            <?php if ($is_admin): ?>
                <span class="badge bg-danger ms-2 fs-6">Admin</span>
            <?php endif; ?>
        </h2>
    </div>
    <div class="col-auto">
        <a href="/observations.php" class="btn btn-outline-primary btn-sm me-2">
            Browse Observations
        </a>
        <a href="/submit.php" class="btn btn-primary btn-sm">
            Submit for Processing
        </a>
    </div>
</div>

<!-- ── Announcements ──────────────────────────────────────────────────────── -->
<?php if (!empty($announcements)): ?>
<div class="mb-4">
    <?php foreach ($announcements as $ann): ?>
    <div class="alert alert-info d-flex gap-3 align-items-start">
        <div>
            <strong><?= htmlspecialchars($ann['title']) ?></strong>
            <span class="text-muted small ms-2">
                <?= htmlspecialchars($ann['created_at']) ?>
            </span>
            <div class="mt-1"><?= $ann['body'] ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Admin pending approvals ────────────────────────────────────────────── -->
<?php if ($is_admin): ?>
<?php $total_pending = (int)$pending_users + (int)$pending_institutions +
                       (int)$pending_departments + (int)$pending_changes; ?>
<?php if ($total_pending > 0): ?>
<div class="alert alert-warning mb-4">
    <strong>Pending Actions:</strong>
    <span class="ms-3">
        <?php if ($pending_users > 0): ?>
            <a href="/admin/users.php" class="alert-link me-3">
                <?= $pending_users ?> user<?= $pending_users > 1 ? 's' : '' ?> awaiting approval
            </a>
        <?php endif; ?>
        <?php if ($unverified_users > 0): ?>
            <a href="/admin/users.php" class="alert-link me-3">
                <?= $unverified_users ?> unverified email<?= $unverified_users > 1 ? 's' : '' ?>
            </a>
        <?php endif; ?>
        <?php if ((int)$pending_institutions + (int)$pending_departments > 0): ?>
            <a href="/admin/institutions.php" class="alert-link me-3">
                <?= (int)$pending_institutions + (int)$pending_departments ?>
                institution/department<?= ((int)$pending_institutions + (int)$pending_departments) > 1 ? 's' : '' ?>
                pending review
            </a>
        <?php endif; ?>
        <?php if ($pending_changes > 0): ?>
            <a href="/admin/change_requests.php" class="alert-link">
                <?= $pending_changes ?> profile change<?= $pending_changes > 1 ? 's' : '' ?> pending
            </a>
        <?php endif; ?>
    </span>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ── Stats row ──────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">

    <!-- Total observations -->
    <div class="col-md-3">
        <div class="card shadow-sm h-100 text-center">
            <div class="card-body">
                <div class="display-6 fw-bold text-primary">
                    <?= number_format($total_observations) ?>
                </div>
                <div class="text-muted small">Total Observations</div>
            </div>
        </div>
    </div>

    <!-- Observations by instrument -->
    <?php foreach ($obs_stats as $stat): ?>
    <div class="col-md-3">
        <div class="card shadow-sm h-100 text-center">
            <div class="card-body">
                <div class="display-6 fw-bold text-secondary">
                    <?= number_format((int)$stat['obs_count']) ?>
                </div>
                <div class="text-muted small">
                    <?= htmlspecialchars($stat['instrument_abbr']) ?> Observations
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- My processing jobs -->
    <div class="col-md-3">
        <div class="card shadow-sm h-100 text-center">
            <div class="card-body">
                <div class="display-6 fw-bold text-info">
                    <?= number_format($total_jobs) ?>
                </div>
                <div class="text-muted small">My Processing Jobs</div>
                <?php if ($total_jobs > 0): ?>
                <div class="mt-1">
                    <?php foreach ($job_counts as $status => $count): ?>
                    <?php
                    $badge = match($status) {
                        'complete' => 'bg-success',
                        'running'  => 'bg-primary',
                        'queued'   => 'bg-warning text-dark',
                        'failed'   => 'bg-danger',
                        default    => 'bg-secondary',
                    };
                    ?>
                    <span class="badge <?= $badge ?> me-1">
                        <?= $count ?> <?= $status ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <!-- Admin: total active users -->
    <div class="col-md-3">
        <div class="card shadow-sm h-100 text-center">
            <div class="card-body">
                <div class="display-6 fw-bold text-success">
                    <?= number_format((int)$total_users) ?>
                </div>
                <div class="text-muted small">Active Users</div>
            </div>
        </div>
    </div>

    <!-- Admin: total jobs -->
    <div class="col-md-3">
        <div class="card shadow-sm h-100 text-center">
            <div class="card-body">
                <div class="display-6 fw-bold text-warning">
                    <?= number_format((int)$total_all_jobs) ?>
                </div>
                <div class="text-muted small">Total Processing Jobs (All Users)</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ── Processing chart ───────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>My Cumulative Processing Jobs</strong>
        <div class="d-flex gap-2">
            <select id="chart-granularity" class="form-select form-select-sm"
                    style="width:auto;">
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly" selected>Monthly</option>
            </select>
            <select id="chart-range" class="form-select form-select-sm"
                    style="width:auto;">
                <option value="30days">Last 30 Days</option>
                <option value="12months">Last 12 Months</option>
                <option value="all" selected>All Time</option>
            </select>
        </div>
    </div>
    <div class="card-body">
        <div id="chart-empty" class="text-center text-muted py-4" style="display:none;">
            No processing jobs found for the selected range.
        </div>
        <canvas id="processingChart" height="100"></canvas>
    </div>
</div>

<!-- ── Recent observations ────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Recent Observations</strong>
        <a href="/observations.php" class="btn btn-outline-primary btn-sm">
            Browse All
        </a>
    </div>
    <?php if (empty($recent_observations)): ?>
        <div class="card-body text-muted">No observations in the database yet.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Native ID</th>
                    <th>Instrument</th>
                    <th>Body</th>
                    <th>Start Time</th>
                    <th>End Time</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent_observations as $obs): ?>
                <tr>
                    <td><code><?= htmlspecialchars($obs['native_id']) ?></code></td>
                    <td><?= htmlspecialchars($obs['instrument_abbr']) ?></td>
                    <td><?= htmlspecialchars($obs['body_name']) ?></td>
                    <td><?= htmlspecialchars($obs['start_time'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($obs['end_time']   ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── My recent processing jobs ──────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-header">
        <strong>My Recent Processing Jobs</strong>
    </div>
    <?php if (empty($recent_jobs)): ?>
        <div class="card-body text-muted">
            You have not submitted any processing jobs yet.
            <a href="/submit.php">Submit your first job</a>.
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Job ID</th>
                    <th>Observation</th>
                    <th>Instrument</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Completed</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent_jobs as $job): ?>
            <?php
            $badge = match($job['status']) {
                'complete' => 'bg-success',
                'running'  => 'bg-primary',
                'queued'   => 'bg-warning text-dark',
                'failed'   => 'bg-danger',
                default    => 'bg-secondary',
            };
            ?>
                <tr>
                    <td><code>#<?= $job['job_id'] ?></code>
                        <?php if ($job['batch_id']): ?>
                            <span class="badge bg-secondary ms-1" title="Batch <?= htmlspecialchars($job['batch_id']) ?>">
                                batch
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars($job['native_id']) ?></code></td>
                    <td><?= htmlspecialchars($job['instrument_abbr']) ?></td>
                    <td><?= htmlspecialchars($job['processing_type']) ?></td>
                    <td>
                        <span class="badge <?= $badge ?>">
                            <?= htmlspecialchars($job['status']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($job['submitted_at']) ?></td>
                    <td><?= $job['completed_at'] ? htmlspecialchars($job['completed_at']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ── Processing chart ──────────────────────────────────────────────────────

let chart = null;

function buildChart(labels, datasets) {
    const ctx   = document.getElementById('processingChart');
    const empty = document.getElementById('chart-empty');

    if (!labels.length) {
        ctx.style.display   = 'none';
        empty.style.display = 'block';
        return;
    }

    ctx.style.display   = 'block';
    empty.style.display = 'none';

    if (chart) chart.destroy();

    chart = new Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top' },
                tooltip: { mode: 'index' }
            },
            scales: {
                x: {
                    title: { display: true, text: 'Period' }
                },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Cumulative Jobs' },
                    ticks: { stepSize: 1 }
                }
            }
        }
    });
}

function fetchChartData() {
    const granularity = document.getElementById('chart-granularity').value;
    const range       = document.getElementById('chart-range').value;

    fetch(`/api/processing_stats.php?granularity=${granularity}&range=${range}`)
        .then(r => r.json())
        .then(data => buildChart(data.labels, data.datasets))
        .catch(() => {});
}

document.getElementById('chart-granularity').addEventListener('change', fetchChartData);
document.getElementById('chart-range').addEventListener('change', fetchChartData);

// Initial load
fetchChartData();
</script>

<?php close_layout(); ?>