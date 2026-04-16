<?php
/**
 * map.php — Interactive GIS map for browsing radar sounder ground tracks.
 *
 * Displays an OpenLayers-based planetary map with instrument layers for
 * SHARAD, MARSIS (Mars and Phobos), and LRS (Moon). Basemaps and instrument
 * configurations are loaded from the FastAPI backend (/api/config/*), and
 * vector features are fetched per-viewport from /api/vectors/*.
 *
 * Requires authentication. The FastAPI backend is proxied through Apache
 * so all /api/* requests are relative.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/layout.php';

require_login();

// Extra <head> content for OpenLayers and the map styles
$head_extra = <<<'HEAD'
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol@9.2.4/dist/ol.css">
    <style>
    /* ===================================================================
       Map page layout — fill viewport between navbar and footer
       =================================================================== */
    body.page-map {
      display: flex;
      flex-direction: column;
      min-height: 100vh;
      overflow: hidden;
    }
    body.page-map main.container-fluid {
      flex: 1;
      padding: 0 !important;
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }
    body.page-map footer {
      display: none;
    }

    /* ===================================================================
       Map container
       =================================================================== */
    #porpass-map {
      position: absolute;
      top: 0; left: 0; right: 0; bottom: 0;
      background: #111;
    }

    /* ===================================================================
       GIS controls — all scoped under #porpass-map-wrap to avoid
       conflicts with Bootstrap / porpass.css
       =================================================================== */
    #porpass-map-wrap {
      flex: 1;
      position: relative;
      font-family: Arial, sans-serif;
    }

    /* Title box — top left */
    #gis-title-box {
      position: absolute;
      top: 10px; left: 10px;
      background: rgba(0, 0, 0, 0.75);
      color: #eee;
      border-radius: 12px;
      padding: 10px 14px;
      max-width: 260px;
      z-index: 10;
      box-shadow: 0 2px 8px rgba(0,0,0,0.4);
      font-family: 'Times New Roman', Times, serif;
    }
    #gis-title-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 8px;
    }
    .gis-title-name {
      font-size: 18px;
      font-weight: bold;
      line-height: 1.2;
    }
    #gis-info-btn {
      background: none;
      border: 1px solid #555;
      border-radius: 50%;
      color: #888;
      font-size: 10px;
      width: 18px; height: 18px;
      cursor: pointer;
      padding: 0;
      line-height: 18px;
      text-align: center;
      flex-shrink: 0;
      margin-top: 2px;
      font-family: Arial, sans-serif;
    }
    #gis-info-btn:hover { border-color: #aaa; color: #eee; }
    .gis-title-sub {
      font-size: 11px;
      font-weight: normal;
      color: #aaa;
      margin-top: 5px;
      line-height: 1.45;
    }

    /* Control panel — top right */
    #gis-controls {
      position: absolute;
      top: 10px; right: 10px;
      background: rgba(0, 0, 0, 0.65);
      color: #eee;
      padding: 10px 14px;
      border-radius: 12px;
      font-size: 13px;
      z-index: 10;
      width: 220px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.4);
    }
    #porpass-map-wrap .ctrl-row {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 8px;
    }
    #porpass-map-wrap .ctrl-row:last-child { margin-bottom: 0; }
    #porpass-map-wrap .ctrl-sep { border: none; border-top: 1px solid #2e2e2e; margin: 8px 0; }
    #porpass-map-wrap .ctrl-label {
      white-space: nowrap;
      color: #888;
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      min-width: 54px;
    }
    #porpass-map-wrap select {
      flex: 1;
      width: 100%;
      box-sizing: border-box;
      background: #2a2a2a;
      color: #ddd;
      border: 1px solid #555;
      border-radius: 3px;
      padding: 3px 6px;
      font-size: 12px;
      cursor: pointer;
      font-family: Arial, sans-serif;
    }
    #porpass-map-wrap select:focus { outline: none; border-color: #888; }

    /* Layer toggle */
    #porpass-map-wrap .layer-toggle {
      display: flex;
      align-items: center;
      gap: 7px;
      cursor: pointer;
      color: #ccc;
      font-size: 12px;
      user-select: none;
      width: 100%;
    }
    #porpass-map-wrap .layer-toggle input[type="checkbox"] {
      accent-color: #ff8c00;
      width: 14px; height: 14px;
      cursor: pointer;
      flex-shrink: 0;
    }
    #porpass-map-wrap .layer-toggle.pending {
      color: #555;
      cursor: default;
      font-style: italic;
    }
    #porpass-map-wrap .layer-toggle.pending input[type="checkbox"] {
      cursor: default;
    }

    /* Filter panel */
    #porpass-map-wrap .filter-row {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 6px;
      font-size: 11px;
    }
    #porpass-map-wrap .f-label {
      color: #999;
      min-width: 72px;
      flex-shrink: 0;
    }
    #porpass-map-wrap .f-range {
      display: flex;
      align-items: center;
      gap: 4px;
      flex: 1;
    }
    #porpass-map-wrap input[type="number"] {
      width: 52px;
      background: #2a2a2a;
      color: #ddd;
      border: 1px solid #555;
      border-radius: 3px;
      padding: 3px 5px;
      font-size: 11px;
      font-family: Arial, sans-serif;
      -moz-appearance: textfield;
    }
    #porpass-map-wrap input[type="number"]::-webkit-inner-spin-button { opacity: 0.4; }
    #porpass-map-wrap input[type="number"]:focus { outline: none; border-color: #888; }
    #porpass-map-wrap .f-input-full { width: 100%; box-sizing: border-box; }
    #porpass-map-wrap .f-dash { color: #555; }
    #porpass-map-wrap .filter-buttons {
      display: flex;
      gap: 6px;
      margin-top: 8px;
    }
    #porpass-map-wrap .f-btn {
      flex: 1;
      padding: 5px 0;
      border: none;
      border-radius: 3px;
      cursor: pointer;
      font-size: 11px;
      font-weight: 600;
      font-family: Arial, sans-serif;
    }
    #porpass-map-wrap .f-btn-apply { background: #c96d00; color: #fff; }
    #porpass-map-wrap .f-btn-apply:hover { background: #ff8c00; }
    #porpass-map-wrap .f-btn-reset { background: #2a2a2a; color: #999; border: 1px solid #444; }
    #porpass-map-wrap .f-btn-reset:hover { background: #383838; color: #ccc; }
    #porpass-map-wrap .f-count {
      font-size: 11px;
      color: #888;
      text-align: center;
      margin-top: 7px;
      min-height: 15px;
    }

    /* Planet toggle */
    #porpass-map-wrap .planet-toggle {
      display: flex;
      flex: 1;
      border: 1px solid #555;
      border-radius: 3px;
      overflow: hidden;
    }
    #porpass-map-wrap .planet-btn {
      flex: 1;
      background: #2a2a2a;
      color: #aaa;
      border: none;
      border-right: 1px solid #555;
      padding: 5px 0;
      font-size: 11px;
      cursor: pointer;
      font-weight: 600;
      letter-spacing: 0.04em;
      font-family: Arial, sans-serif;
    }
    #porpass-map-wrap .planet-btn:last-child { border-right: none; }
    #porpass-map-wrap .planet-btn.active     { background: #c96d00; color: #fff; }
    #porpass-map-wrap .planet-btn:hover:not(.active) { background: #383838; color: #ccc; }

    /* Projection toggle */
    #porpass-map-wrap .proj-toggle {
      display: flex;
      flex: 1;
      border: 1px solid #555;
      border-radius: 3px;
      overflow: hidden;
    }
    #porpass-map-wrap .proj-btn {
      flex: 1;
      background: #2a2a2a;
      color: #aaa;
      border: none;
      border-right: 1px solid #555;
      padding: 4px 0;
      font-size: 10px;
      cursor: pointer;
      font-weight: 500;
      font-family: Arial, sans-serif;
    }
    #porpass-map-wrap .proj-btn:last-child { border-right: none; }
    #porpass-map-wrap .proj-btn.active     { background: #c96d00; color: #fff; }
    #porpass-map-wrap .proj-btn:hover:not(.active) { background: #383838; color: #ccc; }

    /* Feature click popup */
    #gis-popup {
      position: absolute;
      background: rgba(0, 0, 0, 0.92);
      color: #eee;
      border: 1px solid #333;
      border-radius: 12px;
      padding: 10px 12px 10px 12px;
      font-size: 12px;
      min-width: 210px;
      max-width: 290px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.6);
      z-index: 20;
      font-family: Arial, sans-serif;
    }
    #gis-popup-closer {
      position: absolute;
      top: 6px; right: 9px;
      cursor: pointer;
      color: #777;
      font-size: 13px;
      line-height: 1;
      user-select: none;
    }
    #gis-popup-closer:hover { color: #fff; }
    .gis-popup-title {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: #888;
      margin-bottom: 7px;
      padding-right: 16px;
    }
    #porpass-map-wrap .popup-table { width: 100%; border-collapse: collapse; }
    #porpass-map-wrap .popup-table td {
      padding: 2px 4px;
      vertical-align: top;
      font-size: 11px;
    }
    #porpass-map-wrap .popup-table td:first-child {
      color: #888;
      white-space: nowrap;
      padding-right: 10px;
      width: 1%;
    }
    #porpass-map-wrap .popup-table a { color: #ff8c00; text-decoration: none; }
    #porpass-map-wrap .popup-table a:hover { text-decoration: underline; }

    /* Bottom left: logo + mouse position */
    #gis-bottom-left {
      position: absolute;
      bottom: 10px; left: 10px;
      display: flex;
      align-items: center;
      gap: 8px;
      z-index: 10;
    }
    #gis-logo {
      width: 40px; height: 40px;
      border-radius: 50%;
      object-fit: cover;
      flex-shrink: 0;
    }
    #gis-mouse-position {
      background: rgba(0, 0, 0, 0.65);
      color: #eee;
      font: 12px monospace;
      padding: 4px 10px;
      border-radius: 12px;
      pointer-events: none;
      white-space: nowrap;
    }

    /* Zoom controls — bottom right */
    #gis-zoom-controls {
      position: absolute;
      bottom: 10px; right: 10px;
      display: flex;
      flex-direction: column;
      gap: 2px;
      background: rgba(0, 0, 0, 0.65);
      border-radius: 12px;
      padding: 5px;
      z-index: 10;
      box-shadow: 0 2px 8px rgba(0,0,0,0.4);
    }
    #gis-zoom-controls button {
      width: 30px; height: 30px;
      background: transparent;
      border: none;
      color: #fff;
      font-size: 20px;
      font-weight: 400;
      line-height: 30px;
      text-align: center;
      cursor: pointer;
      border-radius: 8px;
      font-family: Arial, sans-serif;
      padding: 0;
    }
    #gis-zoom-controls button:hover { background: rgba(255, 255, 255, 0.12); }
    .ol-attribution { display: none !important; }

    /* Info modal */
    #gis-info-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0, 0, 0, 0.70);
      z-index: 100;
      display: none;
      align-items: center;
      justify-content: center;
    }
    #gis-info-modal {
      background: rgba(12, 12, 12, 0.97);
      border: 1px solid #2e2e2e;
      border-radius: 12px;
      padding: 20px 24px;
      max-width: 420px;
      width: 90%;
      max-height: 80vh;
      overflow-y: auto;
      color: #eee;
      position: relative;
      box-shadow: 0 4px 24px rgba(0,0,0,0.8);
      font-family: Arial, sans-serif;
    }
    #gis-info-close {
      position: absolute;
      top: 10px; right: 14px;
      background: none;
      border: none;
      color: #666;
      font-size: 16px;
      cursor: pointer;
      padding: 0;
      line-height: 1;
    }
    #gis-info-close:hover { color: #eee; }
    #porpass-map-wrap .modal-section { margin-bottom: 16px; }
    #porpass-map-wrap .modal-section:last-child { margin-bottom: 0; }
    #porpass-map-wrap .modal-heading {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #888;
      margin-bottom: 6px;
      font-weight: 600;
    }
    #porpass-map-wrap .modal-text {
      font-size: 12px;
      color: #ccc;
      line-height: 1.55;
      margin: 0 0 6px 0;
    }
    #porpass-map-wrap .modal-text:last-child { margin-bottom: 0; }
    #porpass-map-wrap .modal-text strong { color: #eee; }
    </style>
HEAD;

open_layout('Map', $head_extra, 'page-map');
?>

<!-- ================================================================
     Map wrapper — all GIS elements are children of this container
     ================================================================ -->
<div id="porpass-map-wrap">

  <div id="porpass-map"></div>

  <!-- Title box — top left -->
  <div id="gis-title-box">
    <div id="gis-title-header">
      <span class="gis-title-name">PORPASS GIS</span>
      <button id="gis-info-btn" title="About">ⓘ</button>
    </div>
    <div class="gis-title-sub">The Planetary Orbital Radar Processing and Simulation System Geographic Information System</div>
  </div>

  <!-- Control panel — top right -->
  <div id="gis-controls">

    <!-- Planet switcher -->
    <div class="ctrl-row">
      <div id="planet-toggle" class="planet-toggle">
        <button class="planet-btn active" data-planet="mars">Mars</button>
        <button class="planet-btn"        data-planet="moon">Moon</button>
        <button class="planet-btn"        data-planet="phobos">Phobos</button>
      </div>
    </div>

    <hr class="ctrl-sep">

    <!-- Basemap selector -->
    <div class="ctrl-row">
      <span class="ctrl-label">Basemap</span>
      <select id="basemap-select"><option>Loading…</option></select>
    </div>

    <!-- Projection toggle -->
    <div id="proj-row" class="ctrl-row">
      <span class="ctrl-label">View</span>
      <div id="proj-toggle" class="proj-toggle">
        <button class="proj-btn active" data-proj="cylindrical">Cylindrical</button>
        <button class="proj-btn"        data-proj="north_polar">N Polar</button>
        <button class="proj-btn"        data-proj="south_polar">S Polar</button>
      </div>
    </div>

    <hr class="ctrl-sep">

    <!-- Instrument layer toggles — injected dynamically -->
    <div id="instrument-layers"></div>

  </div>

  <!-- Bottom left: institute logo + mouse position -->
  <div id="gis-bottom-left">
    <img id="gis-logo" src="/resources/img/logo.png" alt="PORPASS">
    <div id="gis-mouse-position">—</div>
  </div>

  <!-- Feature click popup (managed as an ol.Overlay) -->
  <div id="gis-popup" style="display:none">
    <span id="gis-popup-closer">✕</span>
    <div class="gis-popup-title" id="gis-popup-title"></div>
    <div id="gis-popup-content"></div>
  </div>

  <!-- Zoom controls — bottom right -->
  <div id="gis-zoom-controls">
    <button id="gis-zoom-in">+</button>
    <button id="gis-zoom-out">−</button>
  </div>

  <!-- Info modal -->
  <div id="gis-info-overlay">
    <div id="gis-info-modal">
      <button id="gis-info-close">✕</button>

      <div class="modal-section">
        <div class="modal-heading">About PORPASS GIS</div>
        <p class="modal-text">PORPASS GIS provides interactive map access to orbital radar sounder ground track coverage for planetary science missions.</p>
      </div>

      <div class="modal-section">
        <div class="modal-heading">Instruments</div>
        <p class="modal-text"><strong>SHARAD</strong> — SHAllow RADar aboard NASA's Mars Reconnaissance Orbiter. Provided by ASI.</p>
        <p class="modal-text"><strong>MARSIS</strong> — Mars Advanced Radar for Subsurface and Ionosphere Sounding aboard ESA's Mars Express.</p>
        <p class="modal-text"><strong>LRS</strong> — Lunar Radar Sounder aboard JAXA's Kaguya/SELENE lunar orbiter.</p>
      </div>

      <div class="modal-section">
        <div class="modal-heading">Data Sources</div>
        <p class="modal-text">Basemap tiles: USGS Astrogeology Science Center<br>Radar coverage: NASA Planetary Data System (PDS)</p>
      </div>

      <div class="modal-section">
        <div class="modal-heading">Funding</div>
        <p class="modal-text">Development funded by the NASA Planetary Data Archival, Restoration, and Tools (PDART) Program, grant number 80NSSC20K1057.</p>
      </div>

      <div class="modal-section">
        <div class="modal-heading">Technical</div>
        <p class="modal-text">Built with OpenLayers, FastAPI, MariaDB.</p>
      </div>
    </div>
  </div>

</div><!-- /porpass-map-wrap -->

<!-- proj4js must load before OpenLayers -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/proj4js/2.11.0/proj4.js"></script>
<script src="https://cdn.jsdelivr.net/npm/ol@9.2.4/dist/ol.js"></script>

<script>
  // =========================================================================
  // PORPASS GIS — OpenLayers map (integrated into PHP layout)
  //
  // Element IDs have been prefixed with "gis-" where needed to avoid
  // collisions with Bootstrap / PORPASS classes. Internal IDs used only
  // by the instrument/filter system (inst-row-*, inst-toggle-*, etc.)
  // are unchanged since they are generated dynamically.
  // =========================================================================

  // -----------------------------------------------------------------------
  // 1. CRS registration — Mars + Moon + Phobos, cylindrical and polar
  // -----------------------------------------------------------------------
  proj4.defs('IAU:49900',
    '+proj=eqc +lat_ts=0 +lon_0=0 +a=3396190 +b=3376200 +units=m +no_defs');
  proj4.defs('IAU:49918',
    '+proj=stere +lat_0=90 +lon_0=0 +k=1 +a=3396190 +b=3376200 +units=m +no_defs');
  proj4.defs('IAU:49920',
    '+proj=stere +lat_0=-90 +lon_0=0 +k=1 +a=3396190 +b=3376200 +units=m +no_defs');
  proj4.defs('IAU:30100',
    '+proj=eqc +lat_ts=0 +lon_0=0 +a=1737400 +b=1737400 +units=m +no_defs');
  proj4.defs('IAU:30118',
    '+proj=stere +lat_0=90 +lon_0=0 +k=1 +a=1737400 +b=1737400 +units=m +no_defs');
  proj4.defs('IAU:30120',
    '+proj=stere +lat_0=-90 +lon_0=0 +k=1 +a=1737400 +b=1737400 +units=m +no_defs');
  proj4.defs('IAU:40100',
    '+proj=eqc +lat_ts=0 +lon_0=0 +a=11080 +b=11080 +units=m +no_defs');
  proj4.defs('EPSG:32661',
    '+proj=stere +lat_0=90 +lon_0=0 +k=1 +a=3396190 +b=3376200 +units=m +no_defs');
  proj4.defs('EPSG:32761',
    '+proj=stere +lat_0=-90 +lon_0=0 +k=1 +a=3396190 +b=3376200 +units=m +no_defs');
  ol.proj.proj4.register(proj4);

  // Extents
  const MARS_A            = 3396190;
  const MOON_A            = 1737400;
  const PHOBOS_A          = 11080;
  const DEG2RAD           = Math.PI / 180;
  const RAD2DEG           = 180 / Math.PI;

  const PHOBOS_EXTENT         = [-34809,    -17404,   34809,    17404];
  const MARS_EXTENT           = [-10669320, -5334660, 10669320, 5334660];
  const MARS_POLAR_EXTENT     = [-5000000,  -5000000, 5000000,  5000000];
  const MARS_WMS_POLAR_EXTENT = [-2357032,  -2357032, 2357032,  2357032];
  const MOON_EXTENT           = [-5458510,  -2729255, 5458510,  2729255];
  const MOON_POLAR_EXTENT     = [-2000000,  -2000000, 2000000,  2000000];
  const MOON_WMS_POLAR_EXTENT = [-931067,   -931067,  931067,   931067];

  ol.proj.get('IAU:40100').setExtent(PHOBOS_EXTENT);
  ol.proj.get('IAU:40100').setWorldExtent([-180, -90, 180, 90]);
  ol.proj.get('IAU:49900').setExtent(MARS_EXTENT);
  ol.proj.get('IAU:49900').setWorldExtent([-180, -90, 180, 90]);
  ol.proj.get('IAU:49918').setExtent(MARS_POLAR_EXTENT);
  ol.proj.get('IAU:49920').setExtent(MARS_POLAR_EXTENT);
  ol.proj.get('EPSG:32661').setExtent(MARS_WMS_POLAR_EXTENT);
  ol.proj.get('EPSG:32761').setExtent(MARS_WMS_POLAR_EXTENT);
  ol.proj.get('IAU:30100').setExtent(MOON_EXTENT);
  ol.proj.get('IAU:30100').setWorldExtent([-180, -90, 180, 90]);
  ol.proj.get('IAU:30118').setExtent(MOON_POLAR_EXTENT);
  ol.proj.get('IAU:30120').setExtent(MOON_POLAR_EXTENT);

  // Custom transforms
  ol.proj.addCoordinateTransforms('EPSG:4326', 'IAU:49900',
    function (c) { return [c[0] * DEG2RAD * MARS_A, c[1] * DEG2RAD * MARS_A]; },
    function (c) { return [c[0] / MARS_A * RAD2DEG, c[1] / MARS_A * RAD2DEG]; }
  );
  ol.proj.addCoordinateTransforms('EPSG:4326', 'IAU:30100',
    function (c) { return [c[0] * DEG2RAD * MOON_A, c[1] * DEG2RAD * MOON_A]; },
    function (c) { return [c[0] / MOON_A * RAD2DEG, c[1] / MOON_A * RAD2DEG]; }
  );
  ol.proj.addCoordinateTransforms('EPSG:4326', 'IAU:40100',
    function (c) { return [c[0] * DEG2RAD * PHOBOS_A, c[1] * DEG2RAD * PHOBOS_A]; },
    function (c) { return [c[0] / PHOBOS_A * RAD2DEG, c[1] / PHOBOS_A * RAD2DEG]; }
  );

  function setPolarWmsCrs(a, b, wmsExtent) {
    proj4.defs('EPSG:32661',
      '+proj=stere +lat_0=90 +lon_0=0 +k=1 +a=' + a + ' +b=' + b + ' +units=m +no_defs');
    proj4.defs('EPSG:32761',
      '+proj=stere +lat_0=-90 +lon_0=0 +k=1 +a=' + a + ' +b=' + b + ' +units=m +no_defs');
    ol.proj.proj4.register(proj4);
    ol.proj.get('EPSG:32661').setExtent(wmsExtent);
    ol.proj.get('EPSG:32761').setExtent(wmsExtent);
  }

  // -----------------------------------------------------------------------
  // 2. Projection config
  // -----------------------------------------------------------------------
  var currentPlanet = 'mars';
  var currentProj   = 'cylindrical';

  var BODY_PROJ_CFG = {
    mars: {
      cylindrical: { crs: 'IAU:49900', center: [0, 0], zoom: 2, extent: MARS_EXTENT,       basemapKey: 'cylindrical' },
      north_polar: { crs: 'IAU:49918', center: [0, 0], zoom: 4, extent: MARS_POLAR_EXTENT, basemapKey: 'polar_north', wmsCrs: 'EPSG:32661' },
      south_polar: { crs: 'IAU:49920', center: [0, 0], zoom: 4, extent: MARS_POLAR_EXTENT, basemapKey: 'polar_south', wmsCrs: 'EPSG:32761' },
    },
    moon: {
      cylindrical: { crs: 'IAU:30100', center: [0, 0], zoom: 2, extent: MOON_EXTENT,       basemapKey: 'cylindrical' },
      north_polar: { crs: 'IAU:30118', center: [0, 0], zoom: 4, extent: MOON_POLAR_EXTENT, basemapKey: 'polar_north', wmsCrs: 'EPSG:32661' },
      south_polar: { crs: 'IAU:30120', center: [0, 0], zoom: 4, extent: MOON_POLAR_EXTENT, basemapKey: 'polar_south', wmsCrs: 'EPSG:32761' },
    },
    phobos: {
      cylindrical: { crs: 'IAU:40100', center: [0, 0], zoom: 3, extent: PHOBOS_EXTENT, basemapKey: 'cylindrical' },
    },
  };

  var PROJ_CFG = BODY_PROJ_CFG.mars;

  // -----------------------------------------------------------------------
  // 3. Basemap layer
  // -----------------------------------------------------------------------
  var basemapSource = new ol.source.TileWMS({
    url: 'https://planetarymaps.usgs.gov/cgi-bin/mapserv?map=/maps/mars/mars_simp_cyl.map',
    params: { LAYERS: 'MOLA_color', FORMAT: 'image/jpeg', VERSION: '1.1.1' },
    serverType: 'mapserver',
    projection: 'EPSG:4326',
  });
  var basemapLayer = new ol.layer.Tile({ source: basemapSource });

  function setBasemapSource(url, layerId, wmsCrs) {
    basemapSource = new ol.source.TileWMS({
      url: url,
      params: { LAYERS: layerId, FORMAT: 'image/jpeg', VERSION: '1.1.1' },
      serverType: 'mapserver',
      projection: wmsCrs || 'EPSG:4326',
    });
    basemapLayer.setSource(basemapSource);
  }

  // -----------------------------------------------------------------------
  // 4. Map
  // -----------------------------------------------------------------------
  const map = new ol.Map({
    target: 'porpass-map',
    controls: [],
    layers: [basemapLayer],
    view: new ol.View({
      projection: 'IAU:49900',
      center: [0, 0],
      zoom: 2,
      extent: MARS_EXTENT,
    }),
  });

  // -----------------------------------------------------------------------
  // 5. Mouse position
  // -----------------------------------------------------------------------
  const mousePosEl = document.getElementById('gis-mouse-position');
  map.on('pointermove', function (evt) {
    if (evt.dragging) return;
    var ll  = ol.proj.toLonLat(evt.coordinate, map.getView().getProjection().getCode());
    var lon = ll[0];
    mousePosEl.textContent =
      'Lon: ' + lon.toFixed(2) + '\u00b0  Lat: ' + ll[1].toFixed(2) + '\u00b0';
  });
  map.on('pointerout', function () { mousePosEl.textContent = '\u2014'; });

  // -----------------------------------------------------------------------
  // 6. Basemap switcher
  // -----------------------------------------------------------------------
  var basemapFullCfg = null;

  function repopulateBasemapSelect(layers, activeId) {
    var sel = document.getElementById('basemap-select');
    sel.innerHTML = '';
    layers.forEach(function (layer) {
      var opt = document.createElement('option');
      opt.value = layer.id; opt.textContent = layer.label;
      sel.appendChild(opt);
    });
    sel.value = activeId;
  }

  async function initBasemapSwitcher() {
    var sel = document.getElementById('basemap-select');
    try {
      var resp = await fetch('/api/config/basemaps');
      if (!resp.ok) throw new Error(resp.statusText);
      basemapFullCfg = await resp.json();
      repopulateBasemapSelect(
        basemapFullCfg.mars.cylindrical.layers,
        'MOLA_color'
      );
    } catch (err) {
      console.warn('Could not load basemap config:', err);
      sel.innerHTML = '<option value="MDIM21">MDIM 2.1</option>';
    }
    sel.addEventListener('change', function () {
      basemapSource.updateParams({ LAYERS: sel.value });
    });
  }

  // -----------------------------------------------------------------------
  // 7. Instrument layer state
  // -----------------------------------------------------------------------
  var instruments = {};

  var LAYER_COLORS = {
    sharad:        'rgba(255, 140,   0, 0.65)',
    marsis:        'rgba( 80, 180, 255, 0.65)',
    lrs:           'rgba(120, 220, 120, 0.65)',
    marsis_phobos: 'rgba(255, 100, 180, 0.65)',
  };
  var DEFAULT_COLOR = 'rgba(200, 200, 200, 0.65)';

  function getViewBbox() {
    var ext = map.getView().calculateExtent(map.getSize());
    var crs = map.getView().getProjection().getCode();

    if (currentProj !== 'cylindrical') {
      var lats = [
        [ext[0], ext[1]], [ext[2], ext[1]],
        [ext[2], ext[3]], [ext[0], ext[3]],
      ].map(function (c) { return ol.proj.transform(c, crs, 'EPSG:4326')[1]; });
      var minlat = Math.min.apply(null, lats);
      var maxlat = Math.max.apply(null, lats);
      return currentProj === 'north_polar'
        ? '-180,' + minlat.toFixed(4) + ',180,90'
        : '-180,-90,180,' + maxlat.toFixed(4);
    }

    var sw = ol.proj.transform([ext[0], ext[1]], crs, 'EPSG:4326');
    var ne = ol.proj.transform([ext[2], ext[3]], crs, 'EPSG:4326');
    var minlon = sw[0];
    var maxlon = ne[0];
    if (minlon > maxlon) return null;
    return minlon.toFixed(4) + ',' + sw[1].toFixed(4) + ','
         + maxlon.toFixed(4) + ',' + ne[1].toFixed(4);
  }

  function buildVectorUrl(instId) {
    var inst  = instruments[instId];
    var parts = [];
    var bbox  = getViewBbox();
    if (bbox)                  parts.push('bbox=' + bbox);
    if (inst.activeFilters)    parts.push(inst.activeFilters);
    return '/api/vectors/' + instId + (parts.length ? '?' + parts.join('&') : '');
  }

  async function fetchInstrument(instId) {
    var inst = instruments[instId];
    if (!inst || !inst.enabled) return;
    var url = buildVectorUrl(instId);
    try {
      var resp = await fetch(url);
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      var geojson  = await resp.json();
      var format   = new ol.format.GeoJSON();
      var features = format.readFeatures(geojson, {
        dataProjection:    'EPSG:4326',
        featureProjection: PROJ_CFG[currentProj].crs,
      });
      inst.source.clear(true);
      inst.source.addFeatures(features);
    } catch (err) {
      console.error(instId + ' fetch failed:', err);
    }
  }

  function enableInstrument(instId) {
    var inst  = instruments[instId];
    var color = LAYER_COLORS[instId] || DEFAULT_COLOR;
    inst.source = new ol.source.Vector();
    inst.layer  = new ol.layer.Vector({
      source: inst.source,
      style: new ol.style.Style({
        stroke: new ol.style.Stroke({ color: color, width: 1.5 }),
      }),
    });
    map.addLayer(inst.layer);
    inst.enabled = true;
    showFilterPanel(instId);
    document.getElementById('inst-f-count-' + instId).textContent =
      'Apply filters to load tracks.';
  }

  function disableInstrument(instId) {
    var inst = instruments[instId];
    inst.enabled = false;
    clearTimeout(inst.timer);
    if (inst.layer) { map.removeLayer(inst.layer); inst.layer = null; inst.source = null; }
    hideFilterPanel(instId);
    closePopup();
  }

  map.on('moveend', function () {
    Object.keys(instruments).forEach(function (instId) {
      var inst = instruments[instId];
      if (!inst.enabled || !inst.initialLoadDone || inst.filterLocked) return;
      clearTimeout(inst.timer);
      inst.timer = setTimeout(function () { fetchInstrument(instId); }, 400);
    });
  });

  // -----------------------------------------------------------------------
  // 8. Projection toggle
  // -----------------------------------------------------------------------
  function switchProjection(key) {
    if (key === currentProj) return;
    currentProj = key;
    var pc = PROJ_CFG[key];

    document.querySelectorAll('.proj-btn').forEach(function (btn) {
      btn.classList.toggle('active', btn.dataset.proj === key);
    });

    map.setView(new ol.View({
      projection: pc.crs,
      center:     pc.center,
      zoom:       pc.zoom,
      extent:     pc.extent,
    }));

    if (basemapFullCfg) {
      var bm      = basemapFullCfg[currentPlanet][pc.basemapKey];
      var firstId = bm.layers[0].id;
      setBasemapSource(bm.url, firstId, pc.wmsCrs);
      repopulateBasemapSelect(bm.layers, firstId);
    }

    closePopup();
    Object.keys(instruments).forEach(function (instId) {
      if (instruments[instId].enabled) fetchInstrument(instId);
    });
  }

  document.getElementById('proj-toggle').addEventListener('click', function (e) {
    var btn = e.target.closest('.proj-btn');
    if (btn) switchProjection(btn.dataset.proj);
  });

  // -----------------------------------------------------------------------
  // 9. Planet switcher
  // -----------------------------------------------------------------------
  function switchPlanet(planet) {
    if (planet === currentPlanet) return;
    currentPlanet = planet;

    document.querySelectorAll('.planet-btn').forEach(function (btn) {
      btn.classList.toggle('active', btn.dataset.planet === planet);
    });

    if (planet === 'mars') {
      setPolarWmsCrs(3396190, 3376200, MARS_WMS_POLAR_EXTENT);
    } else if (planet === 'moon') {
      setPolarWmsCrs(1737400, 1737400, MOON_WMS_POLAR_EXTENT);
    }

    document.getElementById('proj-row').style.display = planet === 'phobos' ? 'none' : '';

    PROJ_CFG    = BODY_PROJ_CFG[planet];
    currentProj = 'cylindrical';
    document.querySelectorAll('.proj-btn').forEach(function (btn) {
      btn.classList.toggle('active', btn.dataset.proj === 'cylindrical');
    });

    var pc = PROJ_CFG.cylindrical;
    map.setView(new ol.View({
      projection: pc.crs,
      center:     pc.center,
      zoom:       pc.zoom,
      extent:     pc.extent,
    }));

    if (basemapFullCfg) {
      var bm      = basemapFullCfg[planet].cylindrical;
      var firstId = bm.layers[0].id;
      setBasemapSource(bm.url, firstId);
      repopulateBasemapSelect(bm.layers, firstId);
    }

    closePopup();

    Object.keys(instruments).forEach(function (instId) {
      var inst    = instruments[instId];
      var rowEl   = document.getElementById('inst-row-' + instId);
      var cbEl    = document.getElementById('inst-toggle-' + instId);
      var matches = inst.config.body === planet;

      if (rowEl) rowEl.style.display = matches ? '' : 'none';

      if (inst.enabled) {
        cbEl.checked = false;
        disableInstrument(instId);
      }
    });
  }

  document.getElementById('planet-toggle').addEventListener('click', function (e) {
    var btn = e.target.closest('.planet-btn');
    if (btn) switchPlanet(btn.dataset.planet);
  });

  // -----------------------------------------------------------------------
  // 10. Feature click popup
  // -----------------------------------------------------------------------
  var popupEl = document.getElementById('gis-popup');
  var popupOverlay = new ol.Overlay({
    element:     popupEl,
    positioning: 'bottom-left',
    stopEvent:   true,
    offset:      [8, -8],
  });
  map.addOverlay(popupOverlay);

  function closePopup() {
    popupOverlay.setPosition(undefined);
    popupEl.style.display = 'none';
  }

  document.getElementById('gis-popup-closer').addEventListener('click', closePopup);

  map.on('singleclick', function (evt) {
    var feature  = null;
    var instId   = null;

    Object.keys(instruments).forEach(function (id) {
      var inst = instruments[id];
      if (!inst.enabled || !inst.layer || feature) return;
      map.forEachFeatureAtPixel(evt.pixel, function (f, layer) {
        if (layer === inst.layer) { feature = f; instId = id; return true; }
      }, { hitTolerance: 6 });
    });

    if (!feature || !instId) { closePopup(); return; }

    var inst       = instruments[instId];
    var popupFields = inst.config.popup_fields || [];
    var props      = feature.getProperties();

    var rows = popupFields.map(function (pf) {
      var val = props[pf.field];
      if (val === null || val === undefined || val === '') val = null;
      var display;
      if (pf.type === 'url' && val) {
        display = '<a href="' + val + '" target="_blank" rel="noopener">View \u2192</a>';
      } else {
        display = val !== null ? String(val) : '<span style="color:#555">\u2014</span>';
      }
      return '<tr><td>' + pf.label + '</td><td>' + display + '</td></tr>';
    }).join('');

    document.getElementById('gis-popup-title').textContent = inst.config.label + ' Track';
    document.getElementById('gis-popup-content').innerHTML =
      '<table class="popup-table">' + rows + '</table>';

    popupEl.style.display = '';
    popupOverlay.setPosition(evt.coordinate);
  });

  // -----------------------------------------------------------------------
  // 11. Filter panel
  // -----------------------------------------------------------------------
  function showFilterPanel(instId) {
    var panelEl = document.getElementById('inst-filters-' + instId);
    if (!panelEl) return;
    panelEl.style.display = '';
    if (instruments[instId].filtersBuilt) return;
    buildFilterPanel(instId);
  }

  function hideFilterPanel(instId) {
    var panelEl = document.getElementById('inst-filters-' + instId);
    if (panelEl) panelEl.style.display = 'none';
  }

  function buildFilterPanel(instId) {
    var inst    = instruments[instId];
    var defs    = (inst.config.filters || []).filter(function (f) { return f.type !== 'bbox'; });
    inst.filterDefs   = defs;
    inst.filtersBuilt = true;

    var container = document.getElementById('inst-f-rows-' + instId);
    defs.forEach(function (f) {
      var row = document.createElement('div');
      row.className = 'filter-row';

      if (f.type === 'range') {
        if (f.ui === 'max_only') {
          row.innerHTML =
            '<span class="f-label">' + f.label + ' \u2264</span>'
            + '<input type="number" id="f-' + instId + '-' + f.id + '"'
            + '  min="' + f.min + '" max="' + f.max + '"'
            + '  placeholder="' + f.max + '"'
            + '  class="f-input-full">';
        } else {
          row.innerHTML =
            '<span class="f-label">' + f.label + '</span>'
            + '<div class="f-range">'
            + '  <input type="number" id="f-' + instId + '-' + f.id + '-min"'
            + '    min="' + f.min + '" max="' + f.max + '"'
            + '    placeholder="' + f.min + '">'
            + '  <span class="f-dash">\u2013</span>'
            + '  <input type="number" id="f-' + instId + '-' + f.id + '-max"'
            + '    min="' + f.min + '" max="' + f.max + '"'
            + '    placeholder="' + f.max + '">'
            + '</div>';
        }
      } else if (f.type === 'dropdown') {
        row.innerHTML =
          '<span class="f-label">' + f.label + '</span>'
          + '<select id="f-' + instId + '-' + f.id + '" style="flex:1">'
          + '<option value="">All</option>'
          + '</select>';
      }
      container.appendChild(row);
      if (f.type === 'dropdown') {
        if (f.options && f.options.length > 0) {
          var sel = document.getElementById('f-' + instId + '-' + f.id);
          f.options.forEach(function (v) {
            var opt = document.createElement('option');
            opt.value = v; opt.textContent = v;
            sel.appendChild(opt);
          });
        } else {
          populateFilterDropdown(instId, f);
        }
      }
    });
  }

  async function populateFilterDropdown(instId, filterDef) {
    try {
      var resp = await fetch('/api/vectors/' + instId + '/field-values/' + filterDef.field);
      if (!resp.ok) throw new Error(resp.statusText);
      var data = await resp.json();
      var sel  = document.getElementById('f-' + instId + '-' + filterDef.id);
      data.values.forEach(function (v) {
        var opt = document.createElement('option');
        opt.value = v; opt.textContent = v;
        sel.appendChild(opt);
      });
    } catch (err) {
      console.warn('Could not load field values for ' + filterDef.field, err);
    }
  }

  function buildActiveFilters(instId) {
    var inst = instruments[instId];
    if (!inst.filterDefs) return '';
    var parts = [];

    inst.filterDefs.forEach(function (f) {
      if (f.type === 'range') {
        if (f.ui === 'max_only') {
          var el = document.getElementById('f-' + instId + '-' + f.id);
          if (el && el.value !== '') parts.push(f.field + '_max=' + encodeURIComponent(el.value));
        } else {
          var minEl = document.getElementById('f-' + instId + '-' + f.id + '-min');
          var maxEl = document.getElementById('f-' + instId + '-' + f.id + '-max');
          if (minEl && minEl.value !== '') parts.push(f.field + '_min=' + encodeURIComponent(minEl.value));
          if (maxEl && maxEl.value !== '') parts.push(f.field + '_max=' + encodeURIComponent(maxEl.value));
        }
      } else if (f.type === 'dropdown') {
        var sel = document.getElementById('f-' + instId + '-' + f.id);
        if (sel && sel.value !== '') parts.push(f.field + '=' + encodeURIComponent(sel.value));
      }
    });

    return parts.join('&');
  }

  async function applyFilters(instId) {
    var inst = instruments[instId];
    inst.activeFilters   = buildActiveFilters(instId);
    inst.initialLoadDone = true;
    inst.filterLocked    = true;
    fetchInstrument(instId);

    var bbox = getViewBbox();
    var countParts = [];
    if (bbox) countParts.push('bbox=' + bbox);
    if (inst.activeFilters) countParts.push(inst.activeFilters);
    var countUrl = '/api/vectors/' + instId + '/count'
                 + (countParts.length ? '?' + countParts.join('&') : '');
    try {
      var resp = await fetch(countUrl);
      if (!resp.ok) throw new Error(resp.statusText);
      var data = await resp.json();
      document.getElementById('inst-f-count-' + instId).textContent =
        'Showing ' + data.filtered.toLocaleString()
        + ' of ' + data.total.toLocaleString() + ' features';
    } catch (err) {
      console.warn('Count fetch failed:', err);
    }
  }

  function resetFilters(instId) {
    var inst = instruments[instId];
    if (!inst.filterDefs) return;
    inst.filterDefs.forEach(function (f) {
      if (f.type === 'range') {
        if (f.ui === 'max_only') {
          var el = document.getElementById('f-' + instId + '-' + f.id);
          if (el) el.value = '';
        } else {
          var minEl = document.getElementById('f-' + instId + '-' + f.id + '-min');
          var maxEl = document.getElementById('f-' + instId + '-' + f.id + '-max');
          if (minEl) minEl.value = '';
          if (maxEl) maxEl.value = '';
        }
      } else if (f.type === 'dropdown') {
        var sel = document.getElementById('f-' + instId + '-' + f.id);
        if (sel) sel.value = '';
      }
    });
    inst.activeFilters   = '';
    inst.initialLoadDone = false;
    inst.filterLocked    = false;
    if (inst.source) inst.source.clear(true);
    document.getElementById('inst-f-count-' + instId).textContent =
      'Apply filters to load tracks.';
  }

  // -----------------------------------------------------------------------
  // 12. Instrument panel
  // -----------------------------------------------------------------------
  async function initInstrumentPanel() {
    var container = document.getElementById('instrument-layers');
    var cfg;
    try {
      var resp = await fetch('/api/config/instruments');
      if (!resp.ok) throw new Error(resp.statusText);
      cfg = await resp.json();
    } catch (err) {
      console.error('Could not load instrument config:', err);
      container.innerHTML = '<div style="color:#555;font-size:11px">Could not load instruments</div>';
      return;
    }

    cfg.instruments.forEach(function (instCfg) {
      var id      = instCfg.id;
      var enabled = instCfg.enabled;

      instruments[id] = {
        config:       instCfg,
        layer:        null,
        source:       null,
        enabled:      false,
        timer:        null,
        activeFilters: '',
        filterDefs:    null,
        filtersBuilt:  false,
        initialLoadDone: false,
        filterLocked:    false,
      };

      var row = document.createElement('div');
      row.className   = 'ctrl-row';
      row.id          = 'inst-row-' + id;
      row.style.display = instCfg.body === currentPlanet ? '' : 'none';

      if (enabled) {
        row.innerHTML =
          '<label class="layer-toggle">'
          + '<input type="checkbox" id="inst-toggle-' + id + '">'
          + instCfg.label
          + '</label>';
      } else {
        row.innerHTML =
          '<label class="layer-toggle pending">'
          + '<input type="checkbox" id="inst-toggle-' + id + '" disabled>'
          + instCfg.label + ' \u2014 data pending'
          + '</label>';
      }
      container.appendChild(row);

      if (enabled) {
        document.getElementById('inst-toggle-' + id).addEventListener('change', function (e) {
          if (e.target.checked) { enableInstrument(id); } else { disableInstrument(id); }
        });
      }

      if (!enabled || !instCfg.filters || instCfg.filters.length === 0) return;

      var panel = document.createElement('div');
      panel.id            = 'inst-filters-' + id;
      panel.style.display = 'none';
      panel.innerHTML =
        '<hr class="ctrl-sep">'
        + '<div id="inst-f-rows-' + id + '"></div>'
        + '<div class="filter-buttons">'
        + '  <button class="f-btn f-btn-apply" id="inst-f-apply-' + id + '">Apply Filters</button>'
        + '  <button class="f-btn f-btn-reset" id="inst-f-reset-' + id + '">Reset</button>'
        + '</div>'
        + '<div class="f-count" id="inst-f-count-' + id + '"></div>';
      container.appendChild(panel);

      (function (instId) {
        document.getElementById('inst-f-apply-' + instId).addEventListener('click', function () {
          applyFilters(instId);
        });
        document.getElementById('inst-f-reset-' + instId).addEventListener('click', function () {
          resetFilters(instId);
        });
      })(id);
    });
  }

  // -----------------------------------------------------------------------
  // 13. Init
  // -----------------------------------------------------------------------
  initBasemapSwitcher();
  initInstrumentPanel();

  // -----------------------------------------------------------------------
  // 14. Info modal
  // -----------------------------------------------------------------------
  document.getElementById('gis-info-btn').addEventListener('click', function () {
    document.getElementById('gis-info-overlay').style.display = 'flex';
  });
  document.getElementById('gis-info-close').addEventListener('click', function () {
    document.getElementById('gis-info-overlay').style.display = 'none';
  });
  document.getElementById('gis-info-overlay').addEventListener('click', function (e) {
    if (e.target === this) this.style.display = 'none';
  });

  document.getElementById('gis-zoom-in').onclick = function () {
    var view = map.getView();
    view.setZoom(view.getZoom() + 1);
  };
  document.getElementById('gis-zoom-out').onclick = function () {
    var view = map.getView();
    view.setZoom(view.getZoom() - 1);
  };
</script>

<?php close_layout(); ?>
