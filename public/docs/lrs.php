<?php
/**
 * lrs.php — SELENE LRS instrument documentation.
 *
 * Covers overview, technical specifications, instrument design,
 * selected science highlights, and references for the Lunar Radar
 * Sounder (LRS) onboard JAXA's SELENE/Kaguya spacecraft.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_login();

open_layout('LRS — Documentation');
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
                    <p class="text-muted small fw-bold mb-1 ps-1 mt-1">SELENE LRS</p>
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

        <h2 class="mb-1">SELENE LRS</h2>
        <p class="text-muted mb-4">
            Lunar Radar Sounder &mdash; SELENE/Kaguya (JAXA)
        </p>

        <div class="mb-3">
            <a href="https://www.kaguya.jaxa.jp/en/equipment/lrs_e.htm"
               target="_blank" rel="noopener noreferrer"
               class="btn btn-outline-secondary btn-sm me-2">JAXA/Kaguya ↗</a>
            <a href="https://darts.isas.jaxa.jp/planet/pdap/selene/"
               target="_blank" rel="noopener noreferrer"
               class="btn btn-outline-secondary btn-sm">DARTS Archive ↗</a>
        </div>

        <hr class="mb-4">

        <!-- Overview -->
        <section id="overview" class="mb-5">
            <h4 class="mb-3">Overview</h4>
            <p>
                The Lunar Radar Sounder (LRS) was a scientific instrument onboard
                the Selenological and Engineering Explorer (SELENE) spacecraft (also known as Kaguya), a JAXA lunar orbiter
                launched on September 14, 2007. LRS began radar sounder operations
                on October 29, 2007. The mission concluded with a controlled spacecraft
                impact on June 10, 2009.
            </p>
            <p>
                LRS is a frequency-modulated continuous-wave (FMCW) radar sounder
                designed to probe the subsurface structure of the Moon at depths of
                up to approximately 5 km. Operating from a circular polar orbit at an
                altitude of approximately 100 km, LRS performed near-global coverage
                of the lunar surface, acquiring 2,363 hours of radar sounder data and
                8,961 hours of natural radio and plasma wave data over the course of
                the mission (Ono et al., 2010).
            </p>
            <p>
                LRS is the only orbital radar sounder to have operated at the Moon
                since the Apollo Lunar Sounder Experiment (ALSE) during the Apollo 17
                mission in 1972. Compared to ALSE, LRS achieves improved range
                resolution (75 m vs. 280 m free-space in the ALSE HF1 band), employs
                full digital signal processing, and provides near-global coverage
                enabled by SELENE's polar orbit.
            </p>
        </section>

        <!-- Technical specs -->
        <section id="specs" class="mb-5">
            <h4 class="mb-3">Technical Specifications</h4>
            <div class="row g-4">
                <div class="col-md-6">
                    <h6 class="text-muted small text-uppercase">Sounder (SDR)</h6>
                    <table class="table table-sm table-bordered">
                        <tbody>
                            <tr><th class="table-light" style="width:50%">Platform</th><td>SELENE/Kaguya (JAXA)</td></tr>
                            <tr><th class="table-light">Target Body</th><td>Moon</td></tr>
                            <tr><th class="table-light">Technique</th><td>FMCW chirp</td></tr>
                            <tr><th class="table-light">Frequency Range</th><td>4–6 MHz (nominal)<br>14–16 MHz, 1 MHz (optional)</td></tr>
                            <tr><th class="table-light">Bandwidth</th><td>2 MHz</td></tr>
                            <tr><th class="table-light">Sweep Rate</th><td>10 kHz/µs</td></tr>
                            <tr><th class="table-light">Pulse Width</th><td>200 µs</td></tr>
                            <tr><th class="table-light">PRF (SDR-W)</th><td>20 Hz</td></tr>
                            <tr><th class="table-light">PRF (SDR-A)</th><td>2.5 Hz</td></tr>
                            <tr><th class="table-light">TX Power</th><td>800 W</td></tr>
                            <tr><th class="table-light">Range Resolution</th><td>75 m (free-space)<br>~37.5 m in ε = 4 medium</td></tr>
                            <tr><th class="table-light">Max Sounding Depth</th><td>~5 km</td></tr>
                            <tr><th class="table-light">Sampling Rate</th><td>6.25 MSPS</td></tr>
                            <tr><th class="table-light">Sampling Accuracy</th><td>12 bits</td></tr>
                            <tr><th class="table-light">Orbital Altitude</th><td>~100 km</td></tr>
                            <tr><th class="table-light">Operations</th><td>Oct 2007 – Jun 2009</td></tr>
                        </tbody>
                    </table>
                </div>
                <!--
                <div class="col-md-6">
                    
                    <h6 class="text-muted small text-uppercase">Antenna &amp; Passive Receivers</h6>
                    <table class="table table-sm table-bordered">
                        <tbody>
                            <tr><th class="table-light" style="width:50%">Antenna Type</th><td>2 × cross-dipole</td></tr>
                            <tr><th class="table-light">Tip-to-tip Length</th><td>30 m (each dipole)</td></tr>
                            <tr><th class="table-light">Antenna Material</th><td>BeCu alloy</td></tr>
                            <tr><th class="table-light">NPW Freq. Range</th><td>20 kHz – 30 MHz</td></tr>
                            <tr><th class="table-light">WFC-H Freq. Range</th><td>1 kHz – 1 MHz</td></tr>
                            <tr><th class="table-light">WFC-L Freq. Range</th><td>100 Hz – 100 kHz</td></tr>
                        </tbody>
                    </table>

                    <h6 class="text-muted small text-uppercase mt-3">Data Transmission</h6>
                    <table class="table table-sm table-bordered">
                        <tbody>
                            <tr><th class="table-light" style="width:50%">Standard Rate</th><td>22 kBytes/s</td></tr>
                            <tr><th class="table-light">High Rate</th><td>61.5 kBytes/s</td></tr>
                            <tr><th class="table-light">Total SDR Data</th><td>2,363 hours</td></tr>
                            <tr><th class="table-light">Total Wave Data</th><td>8,961 hours</td></tr>
                        </tbody>
                    </table>
                </div>
                -->
            </div>
        </section>

        <!-- Instrument design -->
        <section id="design" class="mb-5">
            <h4 class="mb-3">Instrument Design</h4>

            <h5>FMCW Sounder (SDR)</h5>
            <p>
                LRS employs a frequency-modulated continuous-wave (FMCW) technique.
                The transmitted signal is swept linearly from 4 MHz to 6 MHz over a
                200-µs pulse width, at a sweep rate of 10 kHz/µs. A synchronized swept
                local signal is mixed with received echoes to convert the time delay of
                each echo into a proportional intermediate frequency, which is then
                analyzed by Fast Fourier Transform (FFT) — on the ground after downlinking raw waveforms (Ono &amp; Oya, 2000).
                This range compression technique allows a long, high-power pulse to be
                used while achieving the range resolution set by the 2-MHz bandwidth
                rather than the pulse duration.
            </p>
            <p>
                The free-space range resolution is 75 m, equivalent to approximately
                37.5 m in a medium with relative permittivity ε = 4, representative
                of typical lunar regolith (Ono et al., 2010). A maximum theoretical
                sounding depth of approximately 5 km is achieved for materials with
                loss tangent tan δ ≤ 0.01.
            </p>
            <p>
                A sine-shaped envelope is applied to the transmitted pulse to suppress
                spurious sidelobes in the FFT spectrum, enabling detection of subsurface
                echoes as weak as −50 dB relative to the surface return. This envelope
                reduces transmit power by approximately 2 dB but is essential for
                discriminating weak subsurface echoes from the intense surface reflection.
            </p>

            <h5 class="mt-4">Observation Modes</h5>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered small">
                    <thead class="table-light">
                        <tr>
                            <th>Mode</th>
                            <th>PRF</th>
                            <th>Data Type</th>
                            <th>Interval</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>SDR-W</td>
                            <td>20 Hz</td>
                            <td>IF waveform</td>
                            <td>50 ms</td>
                            <td>Primary sounder mode</td>
                        </tr>
                        <tr>
                            <td>SDR-A</td>
                            <td>2.5 Hz</td>
                            <td>IF waveform</td>
                            <td>400 ms</td>
                            <td>Low data-rate mode</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <h5 class="mt-4">Passive Receivers (NPW &amp; WFC)</h5>
            <p>
                In addition to the active sounder, LRS includes two passive subsystems.
                The Natural Plasma Wave (NPW) receiver is a sweep-frequency analyzer
                covering 20 kHz to 30 MHz in 512 frequency steps across four bands,
                with a sweep time of 2 seconds. The Waveform Capture (WFC) receiver
                consists of two sub-receivers: WFC-H (1 kHz – 1 MHz, fast-sweep
                frequency analyzer) and WFC-L (100 Hz – 100 kHz, direct waveform
                capture). Together, these subsystems cover a continuous frequency range
                from 100 Hz to 30 MHz, enabling observations of electron plasma waves,
                electrostatic solitary waves, auroral kilometric radiation, and planetary
                radio emissions (Ono et al., 2010).
            </p>

            <h5 class="mt-4">Antenna System</h5>
            <p>
                LRS employs four antenna elements (LRS-A1 through A4), each 15 m long
                and made of BeCu alloy, forming two orthogonal 30-m tip-to-tip cross-
                dipole antennas. The crossed dipole configuration enables polarization
                measurements of natural radio and plasma waves. Because KAGUYA is
                three-axis stabilized rather than spin-stabilized, rigid Bi-Stem antenna
                elements were used in place of deployable wire antennas. The antenna
                plane is maintained facing the lunar surface by spacecraft attitude
                control. Elements were deployed sequentially following lunar orbit
                insertion due to power supply limitations.
            </p>
        </section>

        <!-- Science highlights -->
        <section id="science" class="mb-5">
            <h4 class="mb-3">Selected Science Highlights</h4>
            <ul class="list-group list-group-flush mb-3">
                <li class="list-group-item px-0">
                    <strong>Nearside mare stratigraphy</strong> — Distinct subsurface
                    reflectors at apparent depths of several hundred meters were detected
                    in multiple nearside maria, interpreted as buried regolith layers
                    covered by basalt lava flows. The presence of subsurface reflectors
                    rather than off-nadir surface echoes was confirmed by comparing
                    radargrams across different orbital longitudes.
                </li>
                <li class="list-group-item px-0">
                    <strong>Mare ridge tectonics</strong> — Subsurface data from Mare
                    Serenitatis revealed that mare ridges are surface manifestations of
                    anticlines formed by compressional deformation of stacked basalt
                    flows. The absence of growth structures in the folded layers indicates
                    post-depositional deformation younger than approximately 2.84 Ga.
                </li>
                <li class="list-group-item px-0">
                    <strong>TiO₂ echo masking</strong> — Analysis of regions with and
                    without clear subsurface echoes found a clear anti-correlation with
                    TiO₂-rich surface areas. High-ilmenite content increases the loss
                    tangent of surface materials, attenuating subsurface returns and
                    creating apparent gaps in subsurface echo coverage.
                </li>
                <li class="list-group-item px-0">
                    <strong>Near-global coverage</strong> — LRS achieved radar sounder
                    coverage of nearly the entire lunar surface — including farside
                    highlands and polar regions — across 2,363 hours of operation,
                    producing the most comprehensive lunar subsurface radar dataset since
                    ALSE. SDR-W data with 50-ms time resolution were obtained over most
                    of the lunar surface, enabling SAR analysis.
                </li>
                <li class="list-group-item px-0">
                    <strong>Auroral kilometric radiation</strong> — Passive observations
                    detected AKR from Earth with interference patterns caused by
                    reflections off the lunar surface, enabling a new method for probing
                    lunar surface reflectivity and searching for evidence of a lunar
                    ionosphere via ray-tracing analysis of the interference pattern.
                </li>
                <li class="list-group-item px-0">
                    <strong>Plasma environment of the lunar wake</strong> — WFC
                    observations revealed electrostatic solitary waves (ESW) and electron
                    plasma oscillations around the lunar wake boundary, providing direct
                    in-situ electron density measurements and characterizing the complex
                    plasma environment on the lunar dayside and in the wake region.
                </li>
            </ul>
        </section>

        <!-- References -->
        <section id="references" class="mb-5">
            <h4 class="mb-3">References</h4>
            <p class="small text-muted">
                Ono, T. and Oya, H. (2000).
                Lunar Radar Sounder (LRS) experiment on-board the SELENE spacecraft.
                <em>Earth, Planets and Space</em>, 52, 629–637.
                <a href="https://doi.org/10.1186/BF03352248"
                   target="_blank" rel="noopener noreferrer">
                    https://doi.org/10.1186/BF03352248
                </a>
            </p>
            <p class="small text-muted">
                Ono, T., Kumamoto, A., Kasahara, Y., et al. (2010).
                The Lunar Radar Sounder (LRS) onboard the KAGUYA (SELENE) spacecraft.
                <em>Space Science Reviews</em>, 154, 145–192.
                <a href="https://doi.org/10.1007/s11214-010-9673-8"
                   target="_blank" rel="noopener noreferrer">
                    https://doi.org/10.1007/s11214-010-9673-8
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