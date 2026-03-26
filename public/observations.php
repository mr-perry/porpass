<?php
/**
 * observations.php — Browse and search radar sounder observations.
 *
 * Renders a search form allowing users to filter observations by instrument,
 * body, product type, date range, ground track length, bounding box, and
 * instrument-specific parameters. Results are displayed in a paginated table.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/layout.php';

use porpass\database\observationQuery;

session_start_secure();
require_login();

$db = get_db();

// ── Populate form dropdowns ────────────────────────────────────────────────

$bodies = $db->query(
    'SELECT b.body_id, b.body_name,
            GROUP_CONCAT(i.instrument_id ORDER BY i.instrument_id) AS instrument_ids,
            GROUP_CONCAT(i.instrument_abbr ORDER BY i.instrument_id) AS instrument_abbrs
     FROM bodies b
     JOIN instrument_bodies ib ON ib.body_id = b.body_id
     JOIN instruments i        ON i.instrument_id = ib.instrument_id
     GROUP BY b.body_id
     ORDER BY b.body_name'
)->fetchAll();

$sharad_modes = $db->query(
    'SELECT mode_id, mode_name, mode_type, presum, bits_per_sample
     FROM sharad_modes
     ORDER BY mode_name'
)->fetchAll();

$marsis_modes = $db->query(
    'SELECT mode_id, mode_name FROM marsis_modes ORDER BY mode_name'
)->fetchAll();

$marsis_forms = $db->query(
    'SELECT form_id, form_name FROM marsis_forms ORDER BY form_name'
)->fetchAll();

// ── Handle form submission ─────────────────────────────────────────────────

$results      = [];
$total        = 0;
$errors       = [];
$searched     = false;
$per_page     = 50;
$current_page = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $searched = true;

    // Pagination and sorting
    $per_page     = in_array((int)($_POST['per_page'] ?? 50), [25, 50, 100]) ? (int)$_POST['per_page'] : 50;
    $current_page = max(1, (int)($_POST['page'] ?? 1));
    $offset       = ($current_page - 1) * $per_page;
    $sort_col     = $_POST['sort_col']  ?? 'start_time';
    $sort_dir     = ($_POST['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    // Core filters
    $instrument_id = !empty($_POST['instrument_id']) ? (int)$_POST['instrument_id'] : null;
    $body_id       = !empty($_POST['body_id'])       ? (int)$_POST['body_id']       : null;
    $product_type  = !empty($_POST['product_type'])  ? $_POST['product_type']        : null;
    $date_start    = !empty($_POST['date_start'])    ? $_POST['date_start']          : null;
    $date_end      = !empty($_POST['date_end'])      ? $_POST['date_end']            : null;
    $length_min    = $_POST['length_min'] !== '' ? (float)$_POST['length_min'] : null;
    $length_max    = $_POST['length_max'] !== '' ? (float)$_POST['length_max'] : null;

    // Bounding box
    $bbox_min_lat  = $_POST['bbox_min_lat'] !== '' ? (float)$_POST['bbox_min_lat'] : null;
    $bbox_max_lat  = $_POST['bbox_max_lat'] !== '' ? (float)$_POST['bbox_max_lat'] : null;
    $bbox_min_lon  = $_POST['bbox_min_lon'] !== '' ? (float)$_POST['bbox_min_lon'] : null;
    $bbox_max_lon  = $_POST['bbox_max_lon'] !== '' ? (float)$_POST['bbox_max_lon'] : null;

    // Instrument-specific filters
    $lrs_modes    = $_POST['lrs_modes']    ?? [];
    $sza_min      = $_POST['sza_min']      !== '' ? (float)$_POST['sza_min']  : null;
    $sza_max      = $_POST['sza_max']      !== '' ? (float)$_POST['sza_max']  : null;
    $presums      = $_POST['presums']      ?? [];
    $max_roll     = $_POST['max_roll']     !== '' ? (float)$_POST['max_roll'] : null;
    $ls_min       = $_POST['ls_min']       !== '' ? (float)$_POST['ls_min']   : null;
    $ls_max       = $_POST['ls_max']       !== '' ? (float)$_POST['ls_max']   : null;
    $marsis_modes = $_POST['marsis_modes'] ?? [];
    $marsis_forms = $_POST['marsis_forms'] ?? [];
    $alt_min      = $_POST['alt_min']      !== '' ? (float)$_POST['alt_min']  : null;
    $alt_max      = $_POST['alt_max']      !== '' ? (float)$_POST['alt_max']  : null;

    try {
        $q = new observationQuery($db);

        if ($body_id)       $q->setBody($body_id);
        if ($instrument_id) $q->setInstrument($instrument_id);
        if ($product_type)  $q->setProductType($product_type);

        $q->setDateRange($date_start, $date_end);
        $q->setLengthRange($length_min, $length_max);

        if ($bbox_min_lat !== null && $bbox_max_lat !== null &&
            $bbox_min_lon !== null && $bbox_max_lon !== null) {
            $q->setBoundingBox($bbox_min_lat, $bbox_max_lat, $bbox_min_lon, $bbox_max_lon);
        }

        // Instrument-specific
        if ($instrument_id === 1) {
            if (!empty($lrs_modes)) $q->setLrsModes($lrs_modes);
            $q->setSzaRange($sza_min, $sza_max);
        } elseif ($instrument_id === 2) {
            if (!empty($presums))   $q->setPresumValues(array_map('intval', $presums));
            if ($max_roll !== null) $q->setMaxRoll($max_roll);
            $q->setSzaRange($sza_min, $sza_max);
            $q->setLsRange($ls_min, $ls_max);
        } elseif ($instrument_id === 3) {
            if (!empty($marsis_modes)) $q->setMarsisModes($marsis_modes);
            if (!empty($marsis_forms)) $q->setMarsisForms($marsis_forms);
            $q->setSzaRange($sza_min, $sza_max);
            $q->setLsRange($ls_min, $ls_max);
            $q->setAltitudeRange($alt_min, $alt_max);
        }

        $q->setOrderBy($sort_col, $sort_dir);
        $q->setPagination($per_page, $offset);

        $total   = $q->count();
        $results = $q->execute();

    } catch (\Exception $e) {
        $errors[] = 'Query failed: ' . $e->getMessage();
    }
}

$total_pages = $per_page > 0 ? (int)ceil($total / $per_page) : 1;

open_layout('Browse Observations');
?>

<div class="row mb-3">
    <div class="col">
        <h2>Browse Observations</h2>
        <p class="text-muted">Search the PORPASS observation catalogue across all radar sounder instruments.</p>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $e): ?>
        <div><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Search form ─────────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-header">
        <strong>Search Filters</strong>
    </div>
    <div class="card-body">
        <form method="POST" id="search-form">

            <!-- Row 1: Body, Instrument, Product Type -->
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Body</label>
                    <select name="body_id" id="body_id" class="form-select">
                        <option value="">— Any —</option>
                        <?php foreach ($bodies as $body): ?>
                        <option value="<?= $body['body_id'] ?>"
                            data-instruments="<?= htmlspecialchars($body['instrument_ids']) ?>"
                            data-instrument-names="<?= htmlspecialchars($body['instrument_abbrs']) ?>"
                            <?= (($_POST['body_id'] ?? '') == $body['body_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($body['body_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Instrument</label>
                    <select name="instrument_id" id="instrument_id" class="form-select">
                        <option value="">— Any —</option>
                        <!-- Populated by JavaScript based on body selection -->
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Product Type</label>
                    <select name="product_type" id="product_type" class="form-select">
                        <option value="">— Any —</option>
                        <option value="EDR" <?= (($_POST['product_type'] ?? '') === 'EDR') ? 'selected' : '' ?>>EDR</option>
                        <option value="RDR" <?= (($_POST['product_type'] ?? '') === 'RDR') ? 'selected' : '' ?>>RDR</option>
                        <option value="SCS" <?= (($_POST['product_type'] ?? '') === 'SCS') ? 'selected' : '' ?>>SCS</option>
                        <option value="CSC" <?= (($_POST['product_type'] ?? '') === 'CSC') ? 'selected' : '' ?>>CSC</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Ground Track Length (km)</label>
                    <div class="input-group">
                        <input type="number" name="length_min" class="form-control"
                               placeholder="Min" step="0.1" min="0"
                               value="<?= htmlspecialchars($_POST['length_min'] ?? '') ?>">
                        <span class="input-group-text">–</span>
                        <input type="number" name="length_max" class="form-control"
                               placeholder="Max" step="0.1" min="0"
                               value="<?= htmlspecialchars($_POST['length_max'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- Row 2: Date range -->
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Start Date</label>
                    <input type="date" name="date_start" class="form-control"
                           value="<?= htmlspecialchars($_POST['date_start'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">End Date</label>
                    <input type="date" name="date_end" class="form-control"
                           value="<?= htmlspecialchars($_POST['date_end'] ?? '') ?>">
                </div>
            </div>

            <!-- Bounding box (collapsible) -->
            <div class="mb-3">
                <a class="text-decoration-none small" data-bs-toggle="collapse"
                   href="#bbox-section" role="button" aria-expanded="false">
                    ▸ Geographic Bounding Box (optional)
                </a>
                <div class="collapse <?= (!empty($_POST['bbox_min_lat'])) ? 'show' : '' ?>"
                     id="bbox-section">
                    <div class="row g-3 mt-1">
                        <div class="col-md-3">
                            <label class="form-label">Min Latitude</label>
                            <input type="number" name="bbox_min_lat" class="form-control"
                                   placeholder="-90" step="0.001" min="-90" max="90"
                                   value="<?= htmlspecialchars($_POST['bbox_min_lat'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Max Latitude</label>
                            <input type="number" name="bbox_max_lat" class="form-control"
                                   placeholder="90" step="0.001" min="-90" max="90"
                                   value="<?= htmlspecialchars($_POST['bbox_max_lat'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Min Longitude</label>
                            <input type="number" name="bbox_min_lon" class="form-control"
                                   placeholder="-180" step="0.001" min="-180" max="180"
                                   value="<?= htmlspecialchars($_POST['bbox_min_lon'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Max Longitude</label>
                            <input type="number" name="bbox_max_lon" class="form-control"
                                   placeholder="180" step="0.001" min="-180" max="180"
                                   value="<?= htmlspecialchars($_POST['bbox_max_lon'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── LRS-specific filters ──────────────────────────────────── -->
            <div id="lrs-filters" class="instrument-filters" style="display:none;">
                <hr>
                <h6 class="text-muted mb-3">LRS Filters</h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Mode</label>
                        <div>
                            <?php foreach (['SW', 'SA'] as $mode): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox"
                                       name="lrs_modes[]" value="<?= $mode ?>"
                                       id="lrs_<?= $mode ?>"
                                       <?= in_array($mode, $_POST['lrs_modes'] ?? []) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="lrs_<?= $mode ?>"><?= $mode ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Solar Zenith Angle (°)</label>
                        <div class="input-group">
                            <input type="number" name="sza_min" class="form-control"
                                   placeholder="Min" step="0.1" min="0" max="180"
                                   value="<?= htmlspecialchars($_POST['sza_min'] ?? '') ?>">
                            <span class="input-group-text">–</span>
                            <input type="number" name="sza_max" class="form-control"
                                   placeholder="Max" step="0.1" min="0" max="180"
                                   value="<?= htmlspecialchars($_POST['sza_max'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── SHARAD-specific filters ───────────────────────────────── -->
            <div id="sharad-filters" class="instrument-filters" style="display:none;">
                <hr>
                <h6 class="text-muted mb-3">SHARAD Filters</h6>
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Presum</label>
                        <div>
                            <?php foreach ([1, 2, 4, 8, 16, 28, 32] as $p): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox"
                                       name="presums[]" value="<?= $p ?>"
                                       id="presum_<?= $p ?>"
                                       <?= in_array($p, $_POST['presums'] ?? []) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="presum_<?= $p ?>"><?= $p ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Max Roll ≤ (°)</label>
                        <input type="number" name="max_roll" class="form-control"
                               placeholder="e.g. 20" step="0.1"
                               value="<?= htmlspecialchars($_POST['max_roll'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Solar Zenith Angle (°)</label>
                        <div class="input-group">
                            <input type="number" name="sza_min" class="form-control"
                                   placeholder="Min" step="0.1" min="0" max="180"
                                   value="<?= htmlspecialchars($_POST['sza_min'] ?? '') ?>">
                            <span class="input-group-text">–</span>
                            <input type="number" name="sza_max" class="form-control"
                                   placeholder="Max" step="0.1" min="0" max="180"
                                   value="<?= htmlspecialchars($_POST['sza_max'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Solar Longitude L<sub>s</sub> (°)</label>
                        <div class="input-group">
                            <input type="number" name="ls_min" class="form-control"
                                   placeholder="Min" step="0.1" min="0" max="360"
                                   value="<?= htmlspecialchars($_POST['ls_min'] ?? '') ?>">
                            <span class="input-group-text">–</span>
                            <input type="number" name="ls_max" class="form-control"
                                   placeholder="Max" step="0.1" min="0" max="360"
                                   value="<?= htmlspecialchars($_POST['ls_max'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── MARSIS-specific filters ───────────────────────────────── -->
            <div id="marsis-filters" class="instrument-filters" style="display:none;">
                <hr>
                <h6 class="text-muted mb-3">MARSIS Filters</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Mode</label>
                        <div>
                            <?php foreach ($marsis_modes as $m): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox"
                                       name="marsis_modes[]"
                                       value="<?= htmlspecialchars($m['mode_name']) ?>"
                                       id="marsis_mode_<?= $m['mode_id'] ?>"
                                       <?= in_array($m['mode_name'], $_POST['marsis_modes'] ?? []) ? 'checked' : '' ?>>
                                <label class="form-check-label"
                                       for="marsis_mode_<?= $m['mode_id'] ?>">
                                    <?= htmlspecialchars($m['mode_name']) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Form</label>
                        <div>
                            <?php foreach ($marsis_forms as $f): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox"
                                       name="marsis_forms[]"
                                       value="<?= htmlspecialchars($f['form_name']) ?>"
                                       id="marsis_form_<?= $f['form_id'] ?>"
                                       <?= in_array($f['form_name'], $_POST['marsis_forms'] ?? []) ? 'checked' : '' ?>>
                                <label class="form-check-label"
                                       for="marsis_form_<?= $f['form_id'] ?>">
                                    <?= htmlspecialchars($f['form_name']) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Solar Zenith Angle (°)</label>
                        <div class="input-group">
                            <input type="number" name="sza_min" class="form-control"
                                   placeholder="Min" step="0.1" min="0" max="180"
                                   value="<?= htmlspecialchars($_POST['sza_min'] ?? '') ?>">
                            <span class="input-group-text">–</span>
                            <input type="number" name="sza_max" class="form-control"
                                   placeholder="Max" step="0.1" min="0" max="180"
                                   value="<?= htmlspecialchars($_POST['sza_max'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Solar Longitude L<sub>s</sub> (°)</label>
                        <div class="input-group">
                            <input type="number" name="ls_min" class="form-control"
                                   placeholder="Min" step="0.1" min="0" max="360"
                                   value="<?= htmlspecialchars($_POST['ls_min'] ?? '') ?>">
                            <span class="input-group-text">–</span>
                            <input type="number" name="ls_max" class="form-control"
                                   placeholder="Max" step="0.1" min="0" max="360"
                                   value="<?= htmlspecialchars($_POST['ls_max'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Altitude (km)</label>
                        <div class="input-group">
                            <input type="number" name="alt_min" class="form-control"
                                   placeholder="Min" step="1" min="0"
                                   value="<?= htmlspecialchars($_POST['alt_min'] ?? '') ?>">
                            <span class="input-group-text">–</span>
                            <input type="number" name="alt_max" class="form-control"
                                   placeholder="Max" step="1" min="0"
                                   value="<?= htmlspecialchars($_POST['alt_max'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit row -->
            <div class="row mt-4">
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
                <div class="col-auto">
                    <a href="/observations.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>

            <!-- Hidden pagination/sort fields -->
            <input type="hidden" name="page"     value="<?= $current_page ?>">
            <input type="hidden" name="per_page" value="<?= $per_page ?>">
            <input type="hidden" name="sort_col" value="<?= htmlspecialchars($sort_col ?? 'start_time') ?>">
            <input type="hidden" name="sort_dir" value="<?= htmlspecialchars($sort_dir ?? 'DESC') ?>">

        </form>
    </div>
</div>

<!-- ── Results ─────────────────────────────────────────────────────────────── -->
<?php if ($searched): ?>
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>
            <?= number_format($total) ?> observation<?= $total !== 1 ? 's' : '' ?> found
        </strong>
        <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 small text-muted">Per page:</label>
            <select class="form-select form-select-sm" style="width:auto;"
                    onchange="setPerPage(this.value)">
                <?php foreach ([25, 50, 100] as $n): ?>
                <option value="<?= $n ?>" <?= $n === $per_page ? 'selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (empty($results)): ?>
        <div class="card-body text-muted">No observations matched your search criteria.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-dark">
                <tr>
                    <?php
                    $cols = [
                        'native_id'        => 'Native ID',
                        'instrument_abbr'  => 'Instrument',
                        'body_name'        => 'Body',
                        'start_time'       => 'Start Time',
                        'stop_time'        => 'Stop Time',
                        'length_km'        => 'Length (km)',
                    ];
                    // Add instrument-specific columns
                    $inst = (int)($_POST['instrument_id'] ?? 0);
                    if ($inst === 1) {
                        $cols['mode']     = 'Mode';
                        $cols['mean_sza'] = 'Mean SZA (°)';
                    } elseif ($inst === 2) {
                        $cols['mode']          = 'Mode';
                        $cols['orbit_number']  = 'Orbit';
                        $cols['max_roll']      = 'Max Roll (°)';
                        $cols['mean_sza']      = 'Mean SZA (°)';
                        $cols['l_s']           = 'L<sub>s</sub> (°)';
                    } elseif ($inst === 3) {
                        $cols['mode']           = 'Mode';
                        $cols['form']           = 'Form';
                        $cols['orbit_number']   = 'Orbit';
                        $cols['mean_sza']       = 'Mean SZA (°)';
                        $cols['l_s']            = 'L<sub>s</sub> (°)';
                        $cols['start_altitude'] = 'Start Alt (km)';
                        $cols['stop_altitude']  = 'Stop Alt (km)';
                    }
                    ?>
                    <?php foreach ($cols as $key => $label): ?>
                    <th>
                        <?php if (in_array($key, ['start_time', 'stop_time', 'length_km',
                                                   'orbit_number', 'max_roll', 'mean_sza', 'l_s',
                                                   'start_altitude', 'stop_altitude'])): ?>
                        <a href="#" class="text-white text-decoration-none sort-link"
                           data-col="<?= $key ?>"
                           data-dir="<?= ($sort_col ?? '') === $key && ($sort_dir ?? '') === 'ASC' ? 'DESC' : 'ASC' ?>">
                            <?= $label ?>
                            <?php if (($sort_col ?? '') === $key): ?>
                                <?= ($sort_dir ?? '') === 'ASC' ? '▲' : '▼' ?>
                            <?php endif; ?>
                        </a>
                        <?php else: ?>
                            <?= $label ?>
                        <?php endif; ?>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($results as $row): ?>
                <tr>
                    <td><code><?= htmlspecialchars($row['native_id']) ?></code></td>
                    <td><?= htmlspecialchars($row['instrument_abbr']) ?></td>
                    <td><?= htmlspecialchars($row['body_name']) ?></td>
                    <td><?= htmlspecialchars($row['start_time'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($row['stop_time']  ?? '—') ?></td>
                    <td><?= $row['length_km'] !== null ? number_format((float)$row['length_km'], 1) : '—' ?></td>
                    <?php if ($inst === 1): ?>
                        <td><?= htmlspecialchars($row['mode']     ?? '—') ?></td>
                        <td><?= $row['mean_sza'] !== null ? number_format((float)$row['mean_sza'], 1) : '—' ?></td>
                    <?php elseif ($inst === 2): ?>
                        <td><?= htmlspecialchars($row['mode']         ?? '—') ?></td>
                        <td><?= htmlspecialchars($row['orbit_number'] ?? '—') ?></td>
                        <td><?= $row['max_roll']  !== null ? number_format((float)$row['max_roll'],  1) : '—' ?></td>
                        <td><?= $row['mean_sza']  !== null ? number_format((float)$row['mean_sza'],  1) : '—' ?></td>
                        <td><?= $row['l_s']       !== null ? number_format((float)$row['l_s'],       1) : '—' ?></td>
                    <?php elseif ($inst === 3): ?>
                        <td><?= htmlspecialchars($row['mode']           ?? '—') ?></td>
                        <td><?= htmlspecialchars($row['form']           ?? '—') ?></td>
                        <td><?= htmlspecialchars($row['orbit_number']   ?? '—') ?></td>
                        <td><?= $row['mean_sza']       !== null ? number_format((float)$row['mean_sza'],       1) : '—' ?></td>
                        <td><?= $row['l_s']            !== null ? number_format((float)$row['l_s'],            1) : '—' ?></td>
                        <td><?= $row['start_altitude'] !== null ? number_format((float)$row['start_altitude'], 1) : '—' ?></td>
                        <td><?= $row['stop_altitude']  !== null ? number_format((float)$row['stop_altitude'],  1) : '—' ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted">
            Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $total)) ?>
            of <?= number_format($total) ?>
        </small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link page-nav" href="#" data-page="<?= $current_page - 1 ?>">‹</a>
                </li>
                <?php
                $start_page = max(1, $current_page - 2);
                $end_page   = min($total_pages, $current_page + 2);
                if ($start_page > 1): ?>
                    <li class="page-item">
                        <a class="page-link page-nav" href="#" data-page="1">1</a>
                    </li>
                    <?php if ($start_page > 2): ?>
                        <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                <?php for ($p = $start_page; $p <= $end_page; $p++): ?>
                <li class="page-item <?= $p === $current_page ? 'active' : '' ?>">
                    <a class="page-link page-nav" href="#" data-page="<?= $p ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($end_page < $total_pages): ?>
                    <?php if ($end_page < $total_pages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                    <li class="page-item">
                        <a class="page-link page-nav" href="#" data-page="<?= $total_pages ?>">
                            <?= $total_pages ?>
                        </a>
                    </li>
                <?php endif; ?>
                <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link page-nav" href="#" data-page="<?= $current_page + 1 ?>">›</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>
<?php endif; ?>

<script>
// ── Body → Instrument cascade ─────────────────────────────────────────────

const allInstruments = {
    <?php
    $inst_map = [];
    foreach ($bodies as $body) {
        $ids   = explode(',', $body['instrument_ids']);
        $names = explode(',', $body['instrument_abbrs']);
        $inst_map[$body['body_id']] = array_combine($ids, $names);
    }
    foreach ($inst_map as $bid => $instruments) {
        echo (int)$bid . ': {';
        foreach ($instruments as $id => $name) {
            echo (int)$id . ': ' . json_encode($name) . ',';
        }
        echo '},';
    }
    ?>
}

const savedInstrument = <?= json_encode((int)($_POST['instrument_id'] ?? 0)) ?>;

function updateInstruments() {
    const bodyId  = parseInt(document.getElementById('body_id').value) || 0;
    const sel     = document.getElementById('instrument_id');
    sel.innerHTML = '<option value="">— Any —</option>';

    if (bodyId && allInstruments[bodyId]) {
        Object.entries(allInstruments[bodyId]).forEach(([id, name]) => {
            const opt    = document.createElement('option');
            opt.value    = id;
            opt.textContent = name;
            if (parseInt(id) === savedInstrument) opt.selected = true;
            sel.appendChild(opt);
        });
    }
    updateInstrumentFilters();
}

// ── Show/hide instrument-specific filter sections ─────────────────────────

function updateInstrumentFilters() {
    const instId = parseInt(document.getElementById('instrument_id').value) || 0;
    document.querySelectorAll('.instrument-filters').forEach(el => {
        el.style.display = 'none';
    });
    if (instId === 1) document.getElementById('lrs-filters').style.display    = 'block';
    if (instId === 2) document.getElementById('sharad-filters').style.display = 'block';
    if (instId === 3) document.getElementById('marsis-filters').style.display = 'block';
}

document.getElementById('body_id').addEventListener('change', updateInstruments);
document.getElementById('instrument_id').addEventListener('change', updateInstrumentFilters);

// Restore state on page load after POST
updateInstruments();

// ── Column sorting ────────────────────────────────────────────────────────

document.querySelectorAll('.sort-link').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelector('[name="sort_col"]').value = this.dataset.col;
        document.querySelector('[name="sort_dir"]').value = this.dataset.dir;
        document.querySelector('[name="page"]').value = 1;
        document.getElementById('search-form').submit();
    });
});

// ── Per-page selector ─────────────────────────────────────────────────────

function setPerPage(n) {
    document.querySelector('[name="per_page"]').value = n;
    document.querySelector('[name="page"]').value = 1;
    document.getElementById('search-form').submit();
}

// ── Pagination links ──────────────────────────────────────────────────────

document.querySelectorAll('.page-nav').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelector('[name="page"]').value = this.dataset.page;
        document.getElementById('search-form').submit();
    });
});
</script>

<?php close_layout(); ?>