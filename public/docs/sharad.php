<?php
/**
 * sharad.php — MRO SHARAD instrument documentation.
 *
 * Covers overview, technical specifications, instrument design,
 * selected science highlights, and references for the Shallow Radar
 * (SHARAD) onboard NASA's Mars Reconnaissance Orbiter.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_login();

open_layout('SHARAD — Documentation');
?>

<div class="row">

    <!-- ── Left sidebar ──────────────────────────────────────────────────── -->
    <div class="col-md-3 col-lg-2">
        <div class="sticky-top pt-2" style="top: 80px;">
            <nav id="docs-nav" class="navbar navbar-light flex-column align-items-start p-0">
                <p class="text-muted small text-uppercase fw-bold mb-2 ps-1">Documentation</p>
                <nav class="nav nav-pills flex-column w-100">
                    <a class="nav-link ps-1 py-1" href="/docs.php">← All Docs</a>
                    <hr class="w-100 my-1">
                    <p class="text-muted small fw-bold mb-1 ps-1 mt-1">MRO SHARAD</p>
                    <a class="nav-link ps-1 py-1 small" href="#overview">Overview</a>
                    <a class="nav-link ps-1 py-1 small" href="#specs">Technical Specs</a>
                    <a class="nav-link ps-1 py-1 small" href="#design">Instrument Design</a>
                    <a class="nav-link ps-1 py-1 small" href="#science">Science Highlights</a>
                    <a class="nav-link ps-1 py-1 small" href="#references">References</a>
                </nav>
            </nav>
        </div>
    </div>

    <!-- ── Main content ──────────────────────────────────────────────────── -->
    <div class="col-md-9 col-lg-10">

        <h2 class="mb-1">MRO SHARAD</h2>
        <p class="text-muted mb-4">Shallow Radar &mdash; Mars Reconnaissance Orbiter (NASA)</p>

        <div class="mb-3">
            <a href="https://sharad.psi.edu" target="_blank" rel="noopener noreferrer"
               class="btn btn-outline-secondary btn-sm me-2">SHARAD at PSI ↗</a>
            <a href="https://pds-geosciences.wustl.edu/missions/mro/sharad.htm"
               target="_blank" rel="noopener noreferrer"
               class="btn btn-outline-secondary btn-sm me-2">PDS Archive ↗</a>
            <a href="https://mropd.psi.edu/browse.php?inst=SHARAD"
               target="_blank" rel="noopener noreferrer"
               class="btn btn-outline-secondary btn-sm">Publication Database ↗</a>
        </div>

        <hr class="mb-4">

        <!-- Overview -->
        <section id="overview" class="mb-5">
            <h4 class="mb-3">Overview</h4>
            <p>
                The Shallow Radar (SHARAD) is a subsurface sounding radar onboard
                NASA's Mars Reconnaissance Orbiter (MRO). SHARAD began its primary
                science phase in October 2006 and has been in continuous operation for
                nearly 20 years, acquiring data along more than 38,000 discrete orbit
                segments covering approximately 62% of the Martian surface at its
                nominal 3-km cross-track resolution. Coverage is greater than 90%
                poleward of approximately 75–80° latitude in each hemisphere.
            </p>
            <p>
                SHARAD's primary objective is to map dielectric interfaces in the
                Martian subsurface to depths of up to a few kilometers and to interpret
                these results in terms of the distribution of rock, sediment, regolith,
                water, and ice. The instrument has provided transformative insights into
                Martian polar stratigraphy, mid-latitude glacial deposits, volcanic
                structure, and the behavior of the Martian ionosphere.
            </p>
            <p>
                SHARAD provides complementary data to MARSIS onboard Mars Express.
                Operating at higher frequencies and narrower bandwidth than MARSIS,
                SHARAD achieves finer vertical resolution at the cost of shallower
                penetration depth.
            </p>
            <p>
                For a comprehensive list of SHARAD-related publications, visit the
                <a href="https://mropd.psi.edu/browse.php?inst=SHARAD" target="_blank"
                   rel="noopener noreferrer">MRO Publication Database</a>.
            </p>
        </section>

        <!-- Technical specs -->
        <section id="specs" class="mb-5">
            <h4 class="mb-3">Technical Specifications</h4>
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-bordered">
                        <tbody>
                            <tr><th class="table-light" style="width:45%">Platform</th><td>MRO (NASA)</td></tr>
                            <tr><th class="table-light">Target Body</th><td>Mars</td></tr>
                            <tr><th class="table-light">Frequency Range</th><td>15–25 MHz</td></tr>
                            <tr><th class="table-light">Bandwidth</th><td>10 MHz</td></tr>
                            <tr><th class="table-light">Pulse Width</th><td>85 µs</td></tr>
                            <tr><th class="table-light">PRF</th><td>700.28 Hz</td></tr>
                            <tr><th class="table-light">Peak TX Power</th><td>10 W</td></tr>
                            <tr><th class="table-light">Range Resolution</th><td>15 m (free-space); ~10–20 m effective</td></tr>
                            <tr><th class="table-light">Along-track Res.</th><td>300–450 m</td></tr>
                            <tr><th class="table-light">Cross-track Res.</th><td>~3 km (Fresnel zone)</td></tr>
                            <tr><th class="table-light">Orbital Altitude</th><td>~300 km</td></tr>
                            <tr><th class="table-light">Operations</th><td>Oct 2006 – present</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Instrument design -->
        <section id="design" class="mb-5">
            <h4 class="mb-3">Instrument Design</h4>
            <p>
                SHARAD transmits chirped pulses downswept from 25 to 15 MHz over an
                85-µs pulse width, with a pulse repetition frequency of 700.28 Hz. The
                10-MHz bandwidth yields a nominal 15-m free-space range resolution.
                The instrument uses a 10-m dipole antenna for both transmitting and
                receiving. Upon reaching the surface from MRO's orbital altitude of
                approximately 300 km, the Fresnel zone of the signal extends over a
                roughly 3-km circular area, establishing the nominal lateral resolution.
            </p>
            <p>
                Data are processed into two-dimensional radargrams — profile images
                showing returned radar power with delay time on the vertical axis and
                along-track distance on the horizontal axis. Three processing pipelines
                are available through the Colorado Shallow Radar Processing System
                (CO-SHARPS): the Italian team processor (primary PDS archive), the
                US JPL processor, and the US Smithsonian Institution (SI) processor.
                Each applies synthetic-aperture processing, ionospheric correction, and
                range compression but differs in aperture length, datum, and
                ionospheric correction method.
            </p>
            <p>
                Effective subsurface resolution in processed radargrams is approximately
                10–20 m rather than the nominal 15 m, due to frequency-domain windowing
                applied to suppress sidelobe ringing. Advanced techniques including
                subband processing, coherent summing, superresolution, and full
                three-dimensional radar imaging have been developed over the mission
                to extend data utility.
            </p>
            <p>
                Because SHARAD's antenna was mounted in a non-optimal position on MRO's
                zenith deck, the MRO Project executes roll maneuvers during observations
                to reduce spacecraft body interference with the radar signal. Moderate
                rolls of up to 28° provide an average 6-dB signal-to-noise improvement.
                A 120° roll test conducted in May 2023 demonstrated a further 9-dB
                improvement, pointing toward a new high-value observing mode for future
                operations.
            </p>
        </section>

        <!-- Science highlights -->
        <section id="science" class="mb-5">
            <h4 class="mb-3">Selected Science Highlights</h4>
            <ul class="list-group list-group-flush mb-3">
                <li class="list-group-item px-0">
                    <strong>Polar stratigraphy</strong> — SHARAD has detected up to 48
                    reflecting interfaces within the Martian polar layered deposits,
                    penetrating to depths of 2–3 km and revealing complex climate records
                    in the late Amazonian period. Three-dimensional radar imaging of both
                    polar regions has clarified internal stratigraphy and resolved
                    off-nadir surface clutter.
                </li>
                <li class="list-group-item px-0">
                    <strong>Carbon dioxide ice</strong> — Near-surface deposits of massive
                    CO₂ ice were discovered within the south polar layered deposits at
                    Australe Mensa, containing sufficient mass to more than double
                    atmospheric pressure if sublimated.
                </li>
                <li class="list-group-item px-0">
                    <strong>Mid-latitude glaciers</strong> — Strong basal reflections and
                    low dielectric loss confirm that lobate debris aprons are ice-rich,
                    debris-covered glaciers, with hundreds of meters of nearly pure ice
                    beneath thin debris layers of 4–7 m.
                </li>
                <li class="list-group-item px-0">
                    <strong>Subsurface water ice mapping</strong> — SHARAD surface and
                    subsurface reflections have been combined with neutron and thermal
                    spectrometer data to map the consistency of near-surface ice across
                    the Martian mid-latitudes, informing future human landing site planning.
                </li>
                <li class="list-group-item px-0">
                    <strong>Volcanic stratigraphy</strong> — Stacks of at least five lava
                    flows have been mapped in Elysium Planitia and other Amazonian volcanic
                    provinces, constraining eruption sources and preexisting terrain
                    morphology.
                </li>
                <li class="list-group-item px-0">
                    <strong>Ionospheric sounding</strong> — SHARAD signals are measurably
                    affected by the Martian ionosphere, enabling mapping of total electron
                    content variability in space and time, including the influence of
                    crustal remanent magnetic fields.
                </li>
            </ul>
        </section>

        <!-- References -->
        <section id="references" class="mb-5">
            <h4 class="mb-3">References</h4>
            <p class="small text-muted">
                Putzig, N.E., Seu, R., Morgan, G.A., Smith, I.B., Campbell, B.A.,
                Perry, M.R., Mastrogiuseppe, M., and the MRO SHARAD team (2024).
                Science results from sixteen years of MRO SHARAD operations.
                <em>Icarus</em>, 419, 115715.
                <a href="https://doi.org/10.1016/j.icarus.2023.115715"
                   target="_blank" rel="noopener noreferrer">
                    https://doi.org/10.1016/j.icarus.2023.115715
                </a>
            </p>
            <p class="small text-muted">
                Seu, R., Phillips, R.J., Biccari, D., et al. (2007).
                SHARAD sounding radar on the Mars Reconnaissance Orbiter.
                <em>Journal of Geophysical Research: Planets</em>, 112, E05S05.
                <a href="https://doi.org/10.1029/2006JE002745"
                   target="_blank" rel="noopener noreferrer">
                    https://doi.org/10.1029/2006JE002745
                </a>
            </p>
        </section>

    </div>
</div>

<script>
const sections = document.querySelectorAll('section[id]');
const navLinks = document.querySelectorAll('#docs-nav .nav-link');
window.addEventListener('scroll', () => {
    let current = '';
    sections.forEach(s => {
        if (window.scrollY >= s.offsetTop - 120) current = s.getAttribute('id');
    });
    navLinks.forEach(l => {
        l.classList.remove('active');
        if (l.getAttribute('href') === '#' + current) l.classList.add('active');
    });
});
</script>

<?php close_layout(); ?>