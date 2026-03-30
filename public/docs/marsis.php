<?php
/**
 * marsis.php — MEx MARSIS instrument documentation.
 *
 * Covers overview, technical specifications, instrument design,
 * selected science highlights, and references for the Mars Advanced
 * Radar for Subsurface and Ionosphere Sounding (MARSIS) onboard
 * ESA's Mars Express spacecraft.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_login();

open_layout('MARSIS — Documentation');
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
                    <p class="text-muted small fw-bold mb-1 ps-1 mt-1">MEx MARSIS</p>
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

        <h2 class="mb-1">MEx MARSIS</h2>
        <p class="text-muted mb-4">
            Mars Advanced Radar for Subsurface and Ionosphere Sounding &mdash;
            Mars Express (ESA)
        </p>

        <div class="mb-3">
            <a href="https://www.esa.int/Science_Exploration/Space_Science/Mars_Express/MARSIS"
               target="_blank" rel="noopener noreferrer"
               class="btn btn-outline-secondary btn-sm me-2">ESA/MARSIS ↗</a>
            <a href="https://pds-geosciences.wustl.edu/missions/mars_express/marsis.htm"
               target="_blank" rel="noopener noreferrer"
               class="btn btn-outline-secondary btn-sm">PDS Archive ↗</a>
        </div>

        <hr class="mb-4">

        <!-- Overview -->
        <section id="overview" class="mb-5">
            <h4 class="mb-3">Overview</h4>
            <p>
                The Mars Advanced Radar for Subsurface and Ionosphere Sounding
                (MARSIS) is a low-frequency, dual-channel radar sounder onboard the
                European Space Agency's (ESA) Mars Express (MEx) spacecraft.
                Developed by an Italian–US team, MARSIS is the first spaceborne
                sounding radar to operate at Mars and the first instrument of its
                kind since the Apollo Lunar Sounder Experiment (ALSE) in 1972.
                MARSIS began acquiring scientific data in July 2005 following
                successful deployment of its 40-m antenna (Orosei et al., 2015).
            </p>
            <p>
                MARSIS operates from a highly elliptical orbit, acquiring subsurface
                sounding data at altitudes below 900 km and ionospheric sounding data
                at altitudes up to 1200 km. The instrument transmits low-frequency,
                radio pulses through a 40-m dipole antenna. A second 7-m
                monopole antenna provides a surface clutter cancellation channel.
                The subsurface sounder operates over four frequency bands of 1 MHz
                bandwidth each in the range 1.3–5.5 MHz, with the bands centered at
                3, 4, and 5 MHz most commonly used for subsurface sounding
                (Jordan et al., 2009).
            </p>
            <p>
                MARSIS data are complementary to SHARAD: operating at lower
                frequencies with a narrower bandwidth, MARSIS achieves greater
                penetration depth — up to several kilometers in ice-rich polar
                terrains — at the cost of coarser vertical resolution. The nominal
                free-space range resolution is 150 m, compared to SHARAD's 15 m. MARSIS
                also successfully sounded Phobos during several close flybys of Mars
                Express, becoming the first radar sounder to observe an asteroid-like
                body (Orosei et al., 2015).
            </p>
        </section>

        <!-- Technical specs -->
        <section id="specs" class="mb-5">
            <h4 class="mb-3">Technical Specifications</h4>
            <div class="row g-4">
                <div class="col-md-6">
                    <h6 class="text-muted small text-uppercase">Subsurface Sounder</h6>
                    <table class="table table-sm table-bordered">
                        <tbody>
                            <tr><th class="table-light" style="width:50%">Platform</th><td>Mars Express (ESA)</td></tr>
                            <tr><th class="table-light">Target Body</th><td>Mars, Phobos</td></tr>
                            <tr><th class="table-light">Frequency Range</th><td>1.3–5.5 MHz (4 bands)</td></tr>
                            <tr><th class="table-light">Bandwidth per Band</th><td>1 MHz</td></tr>
                            <tr><th class="table-light">Pulse Width</th><td>250 µs</td></tr>
                            <tr><th class="table-light">PRF</th><td>127 Hz</td></tr>
                            <tr><th class="table-light">Peak TX Power</th><td>1.5–5 W</td></tr>
                            <tr><th class="table-light">Range Resolution</th><td>150 m (free-space)</td></tr>
                            <tr><th class="table-light">Dynamic Range</th><td>40–50 dB</td></tr>
                            <tr><th class="table-light">Depth Window</th><td>~15 km (nominal)</td></tr>
                            <tr><th class="table-light">Dipole Antenna</th><td>40 m tip-to-tip</td></tr>
                            <tr><th class="table-light">Monopole Antenna</th><td>7 m</td></tr>
                            <tr><th class="table-light">SS Altitude Range</th><td>250–900 km</td></tr>
                            <tr><th class="table-light">Operations</th><td>Jun 2005 – present</td></tr>
                        </tbody>
                    </table>
                </div>
                <!--
                <div class="col-md-6">
                    <h6 class="text-muted small text-uppercase">Ionospheric Sounder (AIS)</h6>
                    <table class="table table-sm table-bordered">
                        <tbody>
                            <tr><th class="table-light" style="width:50%">Frequency Range</th><td>0.1–5.5 MHz</td></tr>
                            <tr><th class="table-light">Discrete Frequencies</th><td>160</td></tr>
                            <tr><th class="table-light">Pulse Width</th><td>91.4 µs</td></tr>
                            <tr><th class="table-light">PRF</th><td>127 Hz</td></tr>
                            <tr><th class="table-light">Ionogram Interval</th><td>7.54 s</td></tr>
                            <tr><th class="table-light">Detection Bandwidth</th><td>10 kHz</td></tr>
                            <tr><th class="table-light">AIS Altitude Range</th><td>~800–1200 km</td></tr>
                        </tbody>
                    </table>
                </div>
                -->
            </div>
        </section>

        <!-- Instrument design -->
        <section id="design" class="mb-5">
            <h4 class="mb-3">Instrument Design</h4>
            <p>
                MARSIS consists of four subsystems: a sounder channel with a
                programmable signal generator, a 40-m dipole antenna transmitter and
                receiver; a surface clutter cancellation channel using a 7-m monopole
                antenna and second receiver; a dual-channel data processor; and a
                digital electronics and power control subsystem (Jordan et al., 2009).
                The monopole antenna has a null in the nadir direction and is intended
                to receive only off-nadir surface returns, enabling cross-track clutter
                cancellation in ground processing — though elevated noise in this
                channel has in practice limited its use.
            </p>

            <h5 class="mt-4">Subsurface Sounding Modes</h5>
            <p>
                MARSIS has five subsurface sounding modes (SS1–SS5), differing in the
                number of frequency bands, antennas, and Doppler filters downlinked.
                Mode SS3 — two frequency bands, dipole antenna only, three Doppler
                filters per frame — has been the most widely used because it preserves
                complex I/Q data for flexible ground processing. Mode SS2 applies
                onboard non-coherent multilook integration and downlinks a single
                amplitude profile per frame, reducing data volume at the cost of
                flexibility. Due to excessive noise in the monopole channel, modes
                requiring cross-track clutter cancellation (SS1, SS4) have seen limited
                use in practice (Jordan et al., 2009).
            </p>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered small">
                    <thead class="table-light">
                        <tr>
                            <th>Mode</th>
                            <th>Freq. Bands</th>
                            <th>Antenna</th>
                            <th>Doppler Filters</th>
                            <th>Range Processing</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>SS1</td><td>2</td><td>Dipole + Monopole</td><td>1</td><td>Ground</td></tr>
                        <tr><td>SS2</td><td>2</td><td>Dipole only</td><td>1 (multilook onboard)</td><td>Onboard</td></tr>
                        <tr><td>SS3</td><td>2</td><td>Dipole only</td><td>3</td><td>Ground</td></tr>
                        <tr><td>SS4</td><td>1</td><td>Dipole + Monopole</td><td>5</td><td>Ground</td></tr>
                        <tr><td>SS5</td><td>1</td><td>Dipole + Monopole</td><td>3 (short pulse)</td><td>Ground</td></tr>
                    </tbody>
                </table>
            </div>
            <!--
            <h5 class="mt-4">Active Ionospheric Sounding (AIS)</h5>
            <p>
                When Mars Express is above approximately 900 km altitude, MARSIS
                operates as a topside ionosonde. The instrument sweeps 160
                quasi-logarithmically spaced frequencies from 100 kHz to 5.5 MHz,
                transmitting a 91.4-µs tone at each frequency at a pulse repetition
                rate of 127 Hz and recording the returned echo intensity and delay
                time. A complete ionogram is generated every 7.54 seconds. The
                resulting ionogram records reflected signal as a function of sounding
                frequency and delay time, from which electron density profiles can be
                reconstructed by inversion of the ionospheric trace (Orosei et al., 2015).
            </p>
            -->
            <h5 class="mt-4">Key Processing Challenges</h5>
            <p>
                The primary processing challenge for MARSIS subsurface data is
                ionospheric defocusing — plasma in the Martian ionosphere acts as a
                dispersive medium, causing frequency-dependent propagation delays that
                broaden the received pulse and degrade both signal-to-noise ratio and
                range resolution. This effect is most severe during periods of high
                solar activity, when it can render data unintelligible. Several
                correction algorithms have been developed, including the contrast method
                used for publicly-available PDS products (Orosei et al., 2015).
                A useful byproduct of this correction is an estimate of the ionospheric
                total electron content (TEC) along the propagation path.
            </p>
        </section>

        <!-- Science highlights -->
        <section id="science" class="mb-5">
            <h4 class="mb-3">Selected Science Highlights</h4>
            <ul class="list-group list-group-flush mb-3">
                <li class="list-group-item px-0">
                    <strong>North polar layered deposits</strong> — Early MARSIS observations
                    of Planum Boreum detected the base of the north polar layered deposits
                    (NPLD) and the underlying Basal Unit, confirming the predominantly icy
                    composition of the NPLD (dielectric constant consistent with nearly
                    pure water ice) and revealing an absence of lithospheric deflection
                    implying a thick elastic lithosphere beneath the north pole.
                </li>
                <li class="list-group-item px-0">
                    <strong>South polar layered deposits</strong> — MARSIS mapped the
                    thickness and extent of the south polar layered deposits (SPLD),
                    estimating a total volume of approximately 1.6 × 10⁶ km³ — equivalent
                    to a global water layer of 11 ± 1.4 m. Regions of anomalously bright
                    basal reflections are consistent with a 10–100 m overlying CO₂ ice
                    layer.
                </li>
                <li class="list-group-item px-0">
                    <strong>Vastitas Borealis Formation</strong> — Global surface
                    reflectivity mapping revealed low dielectric constants across the
                    Vastitas Borealis Formation, consistent with ice-rich material to at
                    least several tens of meters depth — possibly the sublimation residue
                    of a late Hesperian ocean fed by outflow channels approximately 3 Ga.
                </li>
                <li class="list-group-item px-0">
                    <strong>Medusae Fossae Formation</strong> — MARSIS characterized the
                    dielectric properties of the MFF, finding a bulk dielectric constant
                    of approximately 2.9 ± 0.4 and loss tangent of 0.002–0.006, consistent
                    with either low-density unconsolidated volcanic material or an ice-rich
                    deposit.
                </li>
                <li class="list-group-item px-0">
                    <strong>Ionospheric structure</strong> — Active ionospheric sounding
                    has revealed the complex interplay between the Martian ionosphere,
                    crustal remanent magnetic fields, and the solar wind — including
                    three-dimensional plasma structures, a transient Martian ionopause
                    (occurring ~20% of the time), magnetic flux ropes, and the response
                    of the ionosphere to solar energetic particle events and coronal mass
                    ejections.
                </li>
                <li class="list-group-item px-0">
                    <strong>Phobos</strong> — MARSIS successfully sounded Phobos during
                    close flybys of Mars Express with signal-to-noise ratios of ~25 dB,
                    becoming the first radar sounder to observe an asteroid-like body.
                    Dielectric variations across the Phobos surface were detected in
                    simulation comparisons, though no subsurface interfaces have been
                    positively identified.
                </li>
            </ul>
        </section>

        <!-- References -->
        <section id="references" class="mb-5">
            <h4 class="mb-3">References</h4>
            <p class="small text-muted">
                Jordan, R., Picardi, G., Plaut, J., et al. (2009).
                The Mars Express MARSIS sounder instrument.
                <em>Planetary and Space Science</em>, 57, 1975–1986.
                <a href="https://doi.org/10.1016/j.pss.2009.09.016"
                   target="_blank" rel="noopener noreferrer">
                    https://doi.org/10.1016/j.pss.2009.09.016
                </a>
            </p>
            <p class="small text-muted">
                Orosei, R., Jordan, R.L., Morgan, D.D., et al. (2015).
                Mars Advanced Radar for Subsurface and Ionospheric Sounding (MARSIS)
                after nine years of operation: A summary.
                <em>Planetary and Space Science</em>, 112, 98–114.
                <a href="https://doi.org/10.1016/j.pss.2014.07.010"
                   target="_blank" rel="noopener noreferrer">
                    https://doi.org/10.1016/j.pss.2014.07.010
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