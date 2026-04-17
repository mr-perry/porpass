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

// ── Handle form submission (POST) or GIS link (GET) ─────────────────────

$results      = [];
$total        = 0;
$errors       = [];
$searched     = false;
$per_page     = 50;
$current_page = 1;

// Accept both POST (form submission) and GET (from GIS "Browse Details" link)
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

if (!empty($input) && (isset($input['instrument_id']) || isset($input['body_id']))) {
    $searched = true;
    file_put_contents('/tmp/porpass_post.txt', print_r($input, true));
    // Pagination and sorting
    $per_page     = in_array((int)($input['per_page'] ?? 50), [25, 50, 100]) ? (int)($input['per_page'] ?? 50) : 50;
    $current_page = max(1, (int)($input['page'] ?? 1));
    $offset       = ($current_page - 1) * $per_page;
    $sort_col     = $input['sort_col']  ?? 'start_time';
    $sort_dir     = ($input['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    // Core filters
    $instrument_id = !empty($input['instrument_id']) ? (int)$input['instrument_id'] : null;
    $body_id       = !empty($input['body_id'])       ? (int)$input['body_id']       : null;
    $product_type  = !empty($input['product_type'])  ? $input['product_type']        : null;
    $date_start    = !empty($input['date_start'])    ? $input['date_start']          : null;
    $date_end      = !empty($input['date_end'])      ? $input['date_end']            : null;
    $length_min    = ($input['length_min'] ?? '') !== '' ? (float)$input['length_min'] : null;
    $length_max    = ($input['length_max'] ?? '') !== '' ? (float)$input['length_max'] : null;

    // Bounding box
    $bbox_min_lat  = ($input['bbox_min_lat'] ?? '') !== '' ? (float)$input['bbox_min_lat'] : null;
    $bbox_max_lat  = ($input['bbox_max_lat'] ?? '') !== '' ? (float)$input['bbox_max_lat'] : null;
    $bbox_min_lon  = ($input['bbox_min_lon'] ?? '') !== '' ? (float)$input['bbox_min_lon'] : null;
    $bbox_max_lon  = ($input['bbox_max_lon'] ?? '') !== '' ? (float)$input['bbox_max_lon'] : null;

    // Instrument-specific filters
    $lrs_modes    = $input['lrs_modes']    ?? [];
    $sza_min      = ($input['sza_min'] ?? '')  !== '' ? (float)$input['sza_min']  : null;
    $sza_max      = ($input['sza_max'] ?? '')  !== '' ? (float)$input['sza_max']  : null;
    $presums      = $input['presums']      ?? [];
    $max_roll     = ($input['max_roll'] ?? '')  !== '' ? (float)$input['max_roll'] : null;
    $ls_min       = ($input['ls_min'] ?? '')    !== '' ? (float)$input['ls_min']   : null;
    $ls_max       = ($input['ls_max'] ?? '')    !== '' ? (float)$input['ls_max']   : null;
    $marsis_modes = $input['marsis_modes'] ?? [];
    $marsis_forms = $input['marsis_forms'] ?? [];
    $alt_min      = ($input['alt_min'] ?? '')   !== '' ? (float)$input['alt_min']  : null;
    $alt_max      = ($input['alt_max'] ?? '')   !== '' ? (float)$input['alt_max']  : null;
    $orbit_min    = ($input['orbit_min'] ?? '')  !== '' ? (int)$input['orbit_min']  : null;
    $orbit_max    = ($input['orbit_max'] ?? '')  !== '' ? (int)$input['orbit_max']  : null;

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
            $q->setOrbitRange($orbit_min, $orbit_max);
        } elseif ($instrument_id === 3) {
            if (!empty($marsis_modes)) $q->setMarsisModes($marsis_modes);
            if (!empty($marsis_forms)) $q->setMarsisForms($marsis_forms);
            $q->setSzaRange($sza_min, $sza_max);
            $q->setLsRange($ls_min, $ls_max);
            $q->setAltitudeRange($alt_min, $alt_max);
            $q->setOrbitRange($orbit_min, $orbit_max);
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
                            <?= (($input['body_id'] ?? '') == $body['body_id']) ? 'selected' : '' ?>>
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
                        <option value="EDR" <?= (($input['product_type'] ?? '') === 'EDR') ? 'selected' : '' ?>>EDR</option>
                        <option value="RDR" <?= (($input['product_type'] ?? '') === 'RDR') ? 'selected' : '' ?>>RDR</option>
                        <option value="SCS" <?= (($input['product_type'] ?? '') === 'SCS') ? 'selected' : '' ?>>SCS</option>
                        <option value="CSC" <?= (($input['product_type'] ?? '') === 'CSC') ? 'selected' : '' ?>>CSC</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Ground Track Length (km)</label>
                    <div class="input-group">
                        <input type="number" name="length_min" class="form-control"
                               placeholder="Min" step="0.1" min="0"
                               value="<?= htmlspecialchars($input['length_min'] ?? '') ?>">
                        <span class="input-group-text">–</span>
                        <input type="number" name="length_max" class="form-control"
                               placeholder="Max" step="0.1" min="0"
                               value="<?= htmlspecialchars($input['length_max'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- Row 2: Date range -->
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Start Date</label>
                    <input type="date" name="date_start" class="form-control"
                           value="<?= htmlspecialchars($input['date_start'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">End Date</label>
                    <input type="date" name="date_end" class="form-control"
                           value="<?= htmlspecialchars($input['date_end'] ?? '') ?>">
                </div>
            </div>

            <!-- Bounding box (collapsible) -->
            <div class="mb-3">
                <a class="text-decoration-none small" data-bs-toggle="collapse"
                   href="#bbox-section" role="button" aria-expanded="false">
                    ▸ Geographic Bounding Box (optional)
                </a>
                <div class="collapse <?= (!empty($input['bbox_min_lat'])) ? 'show' : '' ?>"
                     id="bbox-section">
                    <div class="row g-3 mt-1">
                        <div class="col-md-3">
                            <label class="form-label">Min Latitude</label>
                            <input type="number" name="bbox_min_lat" class="form-control"
                                   placeholder="-90" step="0.001" min="-90" max="90"
                                   value="<?= htmlspecialchars($input['bbox_min_lat'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Max Latitude</label>
                            <input type="number" name="bbox_max_lat" class="form-control"
                                   placeholder="90" step="0.001" min="-90" max="90"
                                   value="<?= htmlspecialchars($input['bbox_max_lat'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Min Longitude</label>
                            <input type="number" name="bbox_min_lon" class="form-control"
                                   placeholder="-180" step="0.001" min="-180" max="180"
                                   value="<?= htmlspecialchars($input['bbox_min_lon'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Max Longitude</label>
                            <input type="number" name="bbox_max_lon" class="form-control"
                                   placeholder="180" step="0.001" min="-180" max="180"
                                   value="<?= htmlspecialchars($input['bbox_max_lon'] ?? '') ?>">
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
                                       <?= in_array($mode, $input['lrs_modes'] ?? []) ? 'checked' : '' ?>>
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
                                   value="<?= htmlspecialchars($input['sza_min'] ?? '') ?>">
                            <span class="input-group-text">–</span>
                            <input type="number" name="sza_max" class="form-control"
                                   placeholder="Max" step="0.1" min="0" max="180"
                                   value="<?= htmlspecialchars($input['sza_max'] ?? '') ?>">
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
                                       <?= in_array($p, $input['presums'] ?? []) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="presum_<?= $p ?>"><?= $p ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Max Roll ≤ (°)</label>
                        <input type="number" name="max_roll" class="form-control"
                               placeholder="e.g. 20" step="0.1"
                               value="<?= htmlspecialchars($input['max_roll'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Orbit Number</label>
                        <div class="input-group">
                            <input type="number" name="orbit_min" class="form-control"
                                   placeholder="Min" step="1" min="0"
                                   value="<?= htmlspecialchars($input['orbit_min'] ?? '') ?>">
                            <span class="input-group-text">–</span>
                            <input type="number" name="orbit_max" class="form-control"
                                   placeholder="Max" step="1" min="0"
                                   value="<?= htmlspecialchars($input['orbit_max'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Solar Zenith Angle (°)</label>
                        <div class="input-group">
                            <input type="number" name="sza_min" class="form-control"
                                   placeholder="Min" step="0.1" min="0" max="180"
                                   value="<?= htmlspecialchars($input['sza_min'] ?? '') ?>">
                            <span class="input-group-text">–</span>
                            <input type="number" name="sza_max" class="form-control"
                                   placeholder="Max" step="0.1" min="0" max="180"
                                   value="<?= htmlspecialchars($input['sza_max'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Solar Longitude L<sub>s</sub> (°)</label>
                        <div class="input-group">
                            <input type="number" name="ls_min" class="form-control"
                                   placeholder="Min" step="0.1" min="0" max="360"
                                   value="<?= htmlspecialchars($input['ls_min'] ?? '') ?>">
                            <span class="input-group-text">–</span>
                            <input type="number" name="ls_max" class="form-control"
                                   placeholder="Max" step="0.1" min="0" max="360"
                                   value="<?= htmlspecialchars($input['ls_max'] ?? '') ?>">
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
                                       <?= in_array($m['mode_name'], $input['marsis_modes'] ?? []) ? 'checked' : '' ?>>
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
                                       <?= in_array($f['form_name'], $input['marsis_forms'] ?? []) ? 'checked' : '' ?>>
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
                                   value="<?= htmlspecialchars($input['sza_min'] ?? '') ?>">
                            <span class="input-group-text">–</span>
                            <input type="number" name="sza_max" class="form-control"
                                   placeholder="Max" step="0.1" min="0" max="180"
                                   value="<?= htmlspecialchars($input['sza_max'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Solar Longitude L<sub>s</sub> (°)</label>
                        <div class="input-group">
                            <input type="number" name="ls_min" class="form-control"
                                   placeholder="Min" step="0.1" min="0" max="360"
                                   value="<?= htmlspecialchars($input['ls_min'] ?? '') ?>">
                            <span class="input-group-text">–</span>
                            <input type="number" name="ls_max" class="form-control"
                                   placeholder="Max" step="0.1" min="0" max="360"
                                   value="<?= htmlspecialchars($input['ls_max'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Altitude (km)</label>
                        <div class="input-group">
                            <input type="number" name="alt_min" class="form-control"
                                   placeholder="Min" step="1" min="0"
                                   value="<?= htmlspecialchars($input['alt_min'] ?? '') ?>">
                            <span class="input-group-text">–</span>
                            <input type="number" name="alt_max" class="form-control"
                                   placeholder="Max" step="1" min="0"
                                   value="<?= htmlspecialchars($input['alt_max'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Orbit Number</label>
                        <div class="input-group">
                            <input type="number" name="orbit_min" class="form-control"
                                   placeholder="Min" step="1" min="0"
                                   value="<?= htmlspecialchars($input['orbit_min'] ?? '') ?>">
                            <span class="input-group-text">–</span>
                            <input type="number" name="orbit_max" class="form-control"
                                   placeholder="Max" step="1" min="0"
                                   value="<?= htmlspecialchars($input['orbit_max'] ?? '') ?>">
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
            <?php if ($total > 0): ?>
            <a href="#" id="view-on-map-btn" class="btn btn-sm btn-outline-warning" title="View results on GIS map">
                🗺 View on Map
            </a>
            <?php endif; ?>
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
                    $inst = (int)($input['instrument_id'] ?? 0);
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

const savedInstrument = <?= json_encode((int)($input['instrument_id'] ?? 0)) ?>;

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
        el.querySelectorAll('input, select').forEach(inp => inp.disabled = true);
    });
    if (instId === 1) {
        document.getElementById('lrs-filters').style.display = 'block';
        document.getElementById('lrs-filters').querySelectorAll('input, select').forEach(inp => inp.disabled = false);
    }
    if (instId === 2) {
        document.getElementById('sharad-filters').style.display = 'block';
        document.getElementById('sharad-filters').querySelectorAll('input, select').forEach(inp => inp.disabled = false);
    }
    if (instId === 3) {
        document.getElementById('marsis-filters').style.display = 'block';
        document.getElementById('marsis-filters').querySelectorAll('input, select').forEach(inp => inp.disabled = false);
    }
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
// ── View on Map ──────────────────────────────────────────────────────────

const viewOnMapBtn = document.getElementById('view-on-map-btn');
if (viewOnMapBtn) {
    viewOnMapBtn.addEventListener('click', function(e) {
        e.preventDefault();

        // Map GIS instrument string IDs and body/planet info from PHP instrument_id
        const INST_MAP = {
            1: { gis: 'lrs',    planet: 'moon',   body_id: 3 },
            2: { gis: 'sharad', planet: 'mars',   body_id: 1 },
            3: { gis: 'marsis', planet: 'mars',   body_id: 1 },
        };
        // MARSIS on Phobos
        const MARSIS_PHOBOS = { gis: 'marsis_phobos', planet: 'phobos', body_id: 4 };

        const instId = parseInt(document.getElementById('instrument_id').value) || 0;
        const bodyId = parseInt(document.getElementById('body_id').value) || 0;

        // Determine which GIS instruments to enable
        var gisInstruments = [];
        if (instId > 0) {
            if (instId === 3 && bodyId === 4) {
                gisInstruments.push(MARSIS_PHOBOS);
            } else if (INST_MAP[instId]) {
                gisInstruments.push(INST_MAP[instId]);
            }
        } else if (bodyId > 0) {
            // No specific instrument — enable all for this body
            Object.values(INST_MAP).forEach(function(m) {
                if (m.body_id === bodyId) gisInstruments.push(m);
            });
            if (bodyId === 4) gisInstruments.push(MARSIS_PHOBOS);
        }

        if (gisInstruments.length === 0) {
            alert('Please select a body or instrument before viewing on the map.');
            return;
        }

        // Build query params from the active form fields
        var params = new URLSearchParams();
        params.set('planet', gisInstruments[0].planet);
        params.set('instruments', gisInstruments.map(function(g) { return g.gis; }).join(','));

        // Bounding box
        var bboxMinLat = document.querySelector('[name="bbox_min_lat"]').value;
        var bboxMaxLat = document.querySelector('[name="bbox_max_lat"]').value;
        var bboxMinLon = document.querySelector('[name="bbox_min_lon"]').value;
        var bboxMaxLon = document.querySelector('[name="bbox_max_lon"]').value;
        if (bboxMinLat && bboxMaxLat && bboxMinLon && bboxMaxLon) {
            params.set('bbox', bboxMinLon + ',' + bboxMinLat + ',' + bboxMaxLon + ',' + bboxMaxLat);
        }

        // Instrument-specific filters — translate PHP param names to GIS API names
        if (instId === 2) {  // SHARAD
            var szaMin = document.querySelector('#sharad-filters [name="sza_min"]');
            var szaMax = document.querySelector('#sharad-filters [name="sza_max"]');
            if (szaMin && szaMin.value) params.set('mean_sza_min', szaMin.value);
            if (szaMax && szaMax.value) params.set('mean_sza_max', szaMax.value);

            var lsMin = document.querySelector('#sharad-filters [name="ls_min"]');
            var lsMax = document.querySelector('#sharad-filters [name="ls_max"]');
            if (lsMin && lsMin.value) params.set('l_s_min', lsMin.value);
            if (lsMax && lsMax.value) params.set('l_s_max', lsMax.value);

            var maxRoll = document.querySelector('#sharad-filters [name="max_roll"]');
            if (maxRoll && maxRoll.value) params.set('max_roll_max', maxRoll.value);

            var presums = document.querySelectorAll('#sharad-filters [name="presums[]"]:checked');
            presums.forEach(function(cb) { params.append('presum', cb.value); });

            var orbitMin = document.querySelector('#sharad-filters [name="orbit_min"]');
            var orbitMax = document.querySelector('#sharad-filters [name="orbit_max"]');
            if (orbitMin && orbitMin.value) params.set('orbit_number_min', orbitMin.value);
            if (orbitMax && orbitMax.value) params.set('orbit_number_max', orbitMax.value);

        } else if (instId === 3) {  // MARSIS
            var szaMin = document.querySelector('#marsis-filters [name="sza_min"]');
            var szaMax = document.querySelector('#marsis-filters [name="sza_max"]');
            if (szaMin && szaMin.value) params.set('mean_sza_min', szaMin.value);
            if (szaMax && szaMax.value) params.set('mean_sza_max', szaMax.value);

            var lsMin = document.querySelector('#marsis-filters [name="ls_min"]');
            var lsMax = document.querySelector('#marsis-filters [name="ls_max"]');
            if (lsMin && lsMin.value) params.set('l_s_min', lsMin.value);
            if (lsMax && lsMax.value) params.set('l_s_max', lsMax.value);

        } else if (instId === 1) {  // LRS
            var szaMin = document.querySelector('#lrs-filters [name="sza_min"]');
            var szaMax = document.querySelector('#lrs-filters [name="sza_max"]');
            if (szaMin && szaMin.value) params.set('mean_sza_min', szaMin.value);
            if (szaMax && szaMax.value) params.set('mean_sza_max', szaMax.value);
        }

        window.location.href = '/map.php?' + params.toString();
    });
}

</script>

<?php close_layout(); ?>