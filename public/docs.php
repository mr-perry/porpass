<?php
/**
 * docs.php — PORPASS documentation landing page.
 *
 * Displays a cards grid linking to each documentation section:
 * instrument pages, GRaSP, OaRS, and FAQ. Content for each section
 * lives in its own file under public/docs/.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/layout.php';

session_start_secure();
require_login();

open_layout('Documentation');
?>

<h2 class="mb-1">Documentation</h2>
<p class="text-muted mb-5">
    Reference materials, instrument overviews, and user guides for PORPASS.
</p>

<!-- ── Instruments ────────────────────────────────────────────────────────── -->
<h4 class="border-bottom pb-2 mb-4">Instruments</h4>
<div class="row g-4 mb-5">

    <div class="col-md-4">
        <a href="/docs/sharad.php" class="text-decoration-none">
            <div class="card h-100 shadow-sm border-0 hover-card">
                <div class="card-body">
                    <h5 class="card-title">MRO SHARAD</h5>
                    <p class="card-text text-muted small">
                        Shallow Radar onboard NASA's Mars Reconnaissance Orbiter.
                        Operating since 2006 at 15–25 MHz with 15 m free-space
                        range resolution.
                    </p>
                </div>
                <div class="card-footer bg-transparent border-0 small text-primary">
                    View documentation →
                </div>
            </div>
        </a>
    </div>

    <div class="col-md-4">
        <a href="/docs/marsis.php" class="text-decoration-none">
            <div class="card h-100 shadow-sm border-0 hover-card">
                <div class="card-body">
                    <h5 class="card-title">MEx MARSIS</h5>
                    <p class="card-text text-muted small">
                        Mars Advanced Radar for Subsurface and Ionosphere Sounding
                        onboard ESA's Mars Express. Operating since 2005 at
                        1.3–5.5 MHz with 150 m free-space range resolution.
                    </p>
                </div>
                <div class="card-footer bg-transparent border-0 small text-primary">
                    View documentation →
                </div>
            </div>
        </a>
    </div>

    <div class="col-md-4">
        <a href="/docs/lrs.php" class="text-decoration-none">
            <div class="card h-100 shadow-sm border-0 hover-card">
                <div class="card-body">
                    <h5 class="card-title">SELENE LRS</h5>
                    <p class="card-text text-muted small">
                        Lunar Radar Sounder onboard JAXA's SELENE/Kaguya spacecraft.
                        Operated 2007–2009 at 4–6 MHz with 75 m free-space
                        range resolution.
                    </p>
                </div>
                <div class="card-footer bg-transparent border-0 small text-primary">
                    View documentation →
                </div>
            </div>
        </a>
    </div>

</div>

<!-- ── Software ───────────────────────────────────────────────────────────── -->
<h4 class="border-bottom pb-2 mb-4">Software</h4>
<div class="row g-4 mb-5">

    <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0 bg-light">
            <div class="card-body">
                <h5 class="card-title text-muted">GRaSP</h5>
                <p class="card-text text-muted small">
                    Generalized Radar Sounder Processor — the core processing
                    engine behind PORPASS. API reference documentation
                    auto-generated from the GRaSP Python library.
                </p>
            </div>
            <div class="card-footer bg-transparent border-0 small text-muted">
                Coming soon
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0 bg-light">
            <div class="card-body">
                <h5 class="card-title text-muted">OaRS</h5>
                <p class="card-text text-muted small">
                    Orbital Radar Simulator — user guide for simulating radar
                    sounder observations through free-form subsurface environments.
                </p>
            </div>
            <div class="card-footer bg-transparent border-0 small text-muted">
                Coming soon
            </div>
        </div>
    </div>

</div>

<!-- ── Other ──────────────────────────────────────────────────────────────── -->
<h4 class="border-bottom pb-2 mb-4">Other</h4>
<div class="row g-4">

    <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0 bg-light">
            <div class="card-body">
                <h5 class="card-title text-muted">FAQ</h5>
                <p class="card-text text-muted small">
                    Frequently asked questions about PORPASS, data access,
                    processing jobs, and account management.
                </p>
            </div>
            <div class="card-footer bg-transparent border-0 small text-muted">
                Coming soon
            </div>
        </div>
    </div>

</div>

<style>
.hover-card {
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    cursor: pointer;
}
.hover-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.1) !important;
}
.hover-card .card-title {
    color: var(--bs-body-color);
}
</style>

<?php close_layout(); ?>