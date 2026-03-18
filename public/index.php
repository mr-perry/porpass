<?php
/**
 * index.php — PORPASS public landing page.
 *
 * Displays project information, supported instruments, and team details.
 * Authenticated users are redirected to the dashboard automatically.
 */

require_once __DIR__ . '/../src/auth.php';

session_start_secure();

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PORPASS: The Planetary Orbital Radar Processing and Simulation System</title>
    <link href="/resources/css/bootstrap.min.css" rel="stylesheet">
    <link href="/resources/css/porpass.css"       rel="stylesheet">
</head>
<body>

<!-- ── Navbar ─────────────────────────────────────────────────────────────── -->
<nav class="navbar navbar-expand-lg navbar-dark bg-secondary">
    <div class="container">
        <!-- <a class="navbar-brand fw-bold" href="/index.php">PORPASS</a> -->
        <ul class="navbar-nav ms-auto">
            <li class="nav-item">
                <a class="nav-link btn btn-outline-dark btn-sm px-3 ms-2"
                   href="/login.php">Sign In</a>
            </li>
        </ul>
    </div>
</nav>

<!-- ── Hero ───────────────────────────────────────────────────────────────── -->
<header class="bg-secondary text-white py-5">
    <div class="container text-center">
        <h1 class="display-5 fw-bold">
            The Planetary Orbital Radar Processing and Simulation System
        </h1>
        <a href="/register.php" class="btn btn-primary btn-lg mt-3 me-2">Request an Account</a>
    </div>
</header>

<main class="container py-5">

    <!-- ── About ──────────────────────────────────────────────────────────── -->
    <section id="about" class="mb-5">
        <p class="lead">
            PORPASS provides users with a web application designed to facilitate
            custom processing and simulations of planetary radar data. The overarching
            goal of PORPASS is to enhance mission legacy by providing custom processing
            and simulation of planetary radar datasets beyond the life of any particular
            orbiting planetary science mission, ensuring data and code longevity and
            relevance as well as opening the door to the next generation of researchers.
        </p>
    </section>

    <!-- ── Radargram example ──────────────────────────────────────────────── -->
    <figure class="text-center mb-5">
        <img src="/resources/img/SHARAD_Radargram.png"
             alt="Example SHARAD Radargram"
             class="img-fluid rounded shadow-sm">
        <figcaption class="text-muted mt-2">
            <em>Example SHARAD radargram of Mars' North Polar Layered Deposits and surrounding terrain.</em>
        </figcaption>
    </figure>

    <!-- ── Instruments ────────────────────────────────────────────────────── -->
    <section class="mb-5">
        <h2 class="mb-4 text-center">Supported Instruments</h2>
        <p>For this initial development, we focus our efforts on radar sounding data
           from SHARAD, MARSIS, and LRS.</p>

        <div class="row g-4 mt-2">

            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h3 class="card-title h5 text-center">
                            <a href="https://sharad.psi.edu" target="_blank"
                               rel="noopener noreferrer">MRO SHARAD</a>
                        </h3>
                        <p class="card-text">
                            The Mars Reconnaissance Orbiter (MRO) Shallow Radar (SHARAD)
                            has been collecting information on the surface and subsurface
                            of Mars since late 2006. SHARAD emits a 10-watt chirped pulse
                            downswept from 25 to 15 MHz, yielding a 15-meter range
                            resolution in free-space.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h3 class="card-title h5 text-center">
                            <a href="https://mars.nasa.gov/express/mission/sc_science_marsis01.html"
                               target="_blank" rel="noopener noreferrer">MEX MARSIS</a>
                        </h3>
                        <p class="card-text">
                            The Mars Advanced Radar for Ionosphere and Subsurface Sounding
                            (MARSIS) onboard ESA's Mars Express (MEX) spacecraft has been
                            observing Mars since 2005. MARSIS operates in various modes,
                            and the 1-MHz bands centered at 3, 4, and 5 MHz are used for
                            subsurface sounding.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h3 class="card-title h5 text-center">
                            <a href="https://www.kaguya.jaxa.jp/en/equipment/lrs_e.htm"
                               target="_blank" rel="noopener noreferrer">SELENE (Kaguya) LRS</a>
                        </h3>
                        <p class="card-text">
                            The Selenological and Engineering Explorer's (SELENE, AKA Kaguya)
                            Lunar Radar Sounder (LRS) is a frequency-modulated /
                            continuous-wave radar sounder with a 2-MHz bandwidth centered
                            at 5 MHz. LRS was in operation from 2007–2009.
                        </p>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- ── Applications ───────────────────────────────────────────────────── -->
    <section class="mb-5">
        <h2 class="mb-4 text-center">Applications</h2>
        <p>PORPASS features two main software applications as well as a GIS environment.</p>

        <div class="row g-4 mt-2">

            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h3 class="card-title h5 text-center">GRaSP</h3>
                        <p class="card-text">
                            The Generalized Radar Sounder Processor (GRaSP) is the heart
                            of PORPASS. Most modern sounder systems rely on synthetic-aperture
                            radar (SAR) processing to enhance along-track resolution and boost
                            the effective signal-to-noise ratio. Despite the various differences
                            in operations, all radar sounders operate under the same physics
                            regime, therefore allowing one to design a generic processor for
                            any radar sounder system once the various instrument differences
                            have been accounted for.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h3 class="card-title h5 text-center">OaRS</h3>
                        <p class="card-text">
                            The Orbital Radar Simulator (OaRS) is an attempt to answer a
                            long-standing issue in planetary radar sounder science: the lack
                            of any publicly-available, open-source full-waveform radar
                            simulator. Developed by members of the Center of Wave
                            Phenomena at the Colorado School of Mines, OaRS provides
                            end-users with the ability to simulate radar data from various
                            instruments through free-form subsurface environments.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h3 class="card-title h5 text-center">PORPASS GIS</h3>
                        <p class="card-text">
                            To accompany the processing and simulation capabilities of
                            PORPASS, we host an interactive geographic information
                            system (GIS) that displays radar ground-tracks over various
                            basemaps. The GIS allows users to select radar observations
                            over regions-of-interest and select bulk processing parameters.
                            Both Mars and Earth's Moon are accessible as basemaps.
                        </p>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- ── Team ───────────────────────────────────────────────────────────── -->
    <section class="mb-5">
        <h2 class="mb-3">The PORPASS Team</h2>
        <p>
            The PORPASS Project is managed by Matthew R. Perry (PSI) on behalf of
            Principal Investigator (PI) Nathaniel Putzig (PSI). Other Investigators
            involved in the development of PORPASS include Megan B. Russell (PSI),
            Gareth Morgan (PSI), Frederick Foss (Freestyle Analytical and Quantitative
            Services, LLC), Paul Sava (Colorado School of Mines), Dylan Hickson
            (Colorado School of Mines), Bruce Campbell (Smithsonian Institution), and
            Andrew Kopf (US Naval Observatory).
        </p>
    </section>

    <!-- ── Account request ────────────────────────────────────────────────── -->
    <section class="mb-5">
        <h2 class="mb-3">Request an Account</h2>
        <p>
            PORPASS is currently in active development and access is available to
            collaborators and researchers. To request an account, please
            <a href="mailto:contact@example.com">contact the PORPASS team</a>
            or <a href="/register.php">register here</a> — your account will be
            reviewed and approved by an administrator.
        </p>
    </section>

    <!-- ── Launch date ────────────────────────────────────────────────────── -->
    <section class="mb-5">
        <h2 class="mb-3">Launch Date</h2>
        <p>PORPASS is expected to launch in May 2026.</p>
    </section>

    <!-- ── Related resources ──────────────────────────────────────────────── -->
    <section class="mb-5">
        <h2 class="mb-3">Related Resources</h2>
        <ul>
            <li><a href="https://pds.nasa.gov" target="_blank" rel="noopener noreferrer">NASA Planetary Data System (PDS)</a></li>
            <li><a href="https://psi.edu" target="_blank" rel="noopener noreferrer">Planetary Science Institute (PSI)</a></li>
            <li><a href="https://sharad.psi.edu" target="_blank" rel="noopener noreferrer">SHARAD at PSI</a></li>
            <li><a href="https://mines.edu" target="_blank" rel="noopener noreferrer">Colorado School of Mines</a></li>
        </ul>
    </section>

</main>

<!-- ── Footer ─────────────────────────────────────────────────────────────── -->
<footer class="border-top bg-secondary text-white py-4 mt-5">
    <div class="container text-center">
        <img src="/resources/img/PSI_Logo.png"
             alt="Planetary Science Institute"
             class="mb-3"
             style="max-height: 150px;">
        <p class="mb-1">
            PORPASS is hosted by <strong>The Planetary Science Institute</strong><br>
            1700 East Fort Lowell, Suite 106, Tucson, AZ 85719-2395 &mdash; (520) 622-6300
        </p>
        <p class="text-white-50 small mt-2">
            Development funded by the NASA Planetary Data Archival, Restoration, and Tools
            (PDART) Program, grant number 80NSSC20K1057.
        </p>
        <p class="text-white-50 small">
            PORPASS &mdash; Planetary Orbital Radar Processing and Simulation System
        </p>
    </div>
</footer>

<script src="/resources/js/bootstrap.bundle.min.js"></script>
</body>
</html>