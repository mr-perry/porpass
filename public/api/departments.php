<?php
/**
 * departments.php — JSON endpoint returning departments for a given institution.
 *
 * Called via fetch() from the registration form when a user selects an
 * institution. Returns an array of matching institution rows where
 * department is not null.
 *
 * Query parameters:
 *   institution_id (int) — the institution_id to filter by
 */

require_once __DIR__ . '/../../src/db.php';

header('Content-Type: application/json');

$institution_id = (int)($_GET['institution_id'] ?? 0);

if ($institution_id <= 0) {
    echo json_encode([]);
    exit;
}

$db   = get_db();
$stmt = $db->prepare(
    'SELECT institution_id, department, department_abbr
     FROM institutions
     WHERE institution_abbr = (
         SELECT institution_abbr FROM institutions WHERE institution_id = ?
     )
     AND department IS NOT NULL
     AND is_approved = 1
     ORDER BY department'
);
$stmt->execute([$institution_id, $institution_id]);
echo json_encode($stmt->fetchAll());