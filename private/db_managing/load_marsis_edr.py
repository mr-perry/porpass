#!/usr/bin/env python3
"""Import MARSIS EDR observations into the PORPASS database.

This script reads MARSIS auxiliary files from the Mars Express PDS EDR archive,
downloading them from the PDS Geosciences Node if not already cached locally,
computes sub-spacecraft ground track geometry using SPICE kernels via GRaSP,
decimates the ground track to 1-second intervals to control geometry size, and
inserts one observation row per auxiliary file into the PORPASS observations
table.

MARSIS EDRs provide ephemeris time (ET) directly, so no UTC conversion is
performed. Unlike MRO/SHARAD, Mars Express uses a single comprehensive
metakernel (MEX_V15.TM) covering the full mission, so no per-observation
kernel management is required.

Progress is committed to the database every --commit-interval successful
inserts (default: 100) to preserve work in the event of a crash or stall.

Usage:
    python load_marsis_edr.py [--dry-run] [--limit N] [--log-file PATH]
                              [--commit-interval N]

Options:
    --dry-run              Execute queries but roll back at the end (default: False)
    --limit N              Process only the first N rows of the index file
    --commit-interval N    Commit every N successful inserts (default: 100)
    --log-file PATH        Write log output to this file in addition to the console.
                           The file receives DEBUG-level output (more verbose than the
                           console, which shows INFO and above). Any text written to
                           stderr by third-party libraries (SpiceyPy, GRaSP C extensions,
                           etc.) is also captured in the log at WARNING level.

Examples:
    Dry run — validates without writing to the database::

        python load_marsis_edr.py --dry-run

    Import first 10 observations only::

        python load_marsis_edr.py --limit 10

    Full import with log file::

        python load_marsis_edr.py --log-file /tmp/marsis_edr_import.log

"""

import logging
import os
import sys
import argparse
import traceback
from pathlib import Path
from urllib.request import urlretrieve
from urllib.error import URLError

import numpy as np
import pandas as pd
from pyproj import Geod
from shapely.geometry import LineString
import pymysql as sql
from dotenv import load_dotenv
from spiceypy import unload

# Add GRaSP to path
sys.path.append("/Users/mrperry/python/grasp/")
import grasp


# ── Configuration ─────────────────────────────────────────────────────────────

ENV_PATH  = Path(__file__).resolve().parents[2] / '.env'
MK_FILE   = Path("/Volumes/data/NAIF/pds/mex-e_m-spice-6-v2.0/mexsp_2000/EXTRAS/MK/MEX_V15.TM")
AUX_DIR   = Path("/Volumes/data/MEx/marsis/pds/edr/aux_files/")
IDX_FILE  = Path("/Volumes/data/MEx/marsis/pds/edr/index_files/cumindex_trk.tab")
URL_MAIN  = 'https://pds-geosciences.wustl.edu/mex/'
INST_ID   = 3   # MARSIS instrument_id in porpass_dev
BODY_ID   = 1   # Mars body_id in porpass_dev
METHOD    = "NEAR POINT/ELLIPSOID"

EDR_NAMES = [
    'FILE_SPECIFICATION_NAME',
    'PRODUCT_ID',
    'PRODUCT_CREATION_TIME',
    'DATA_SET_ID',
    'RELEASE_ID',
    'REVISION_ID'
]

INSERT_QRY = '''INSERT INTO observations
    (instrument_id, body_id, native_id, start_time, stop_time,
     duration, length_km, geometry)
    VALUES (%s, %s, %s, %s, %s, %s, %s, ST_GeomFromText(%s, 0))'''

INSERT_CHILD_QRY = '''INSERT INTO marsis_observations
    (observation_id, mode_id, state_id, form_id, orbit_number,
     l_s, start_altitude, stop_altitude, start_sza, stop_sza, mean_sza)
    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)'''


# ── Logging ───────────────────────────────────────────────────────────────────

class _TeeStream:
    """Write to both a stream and a logging.Logger at a given level.

    Used to redirect sys.stderr into the log so that output from C extensions,
    SpiceyPy, or any other code that writes directly to stderr is captured.
    """

    def __init__(self, original_stream, logger, level=logging.WARNING):
        self._stream = original_stream
        self._logger = logger
        self._level  = level
        self._buf    = ''

    def write(self, msg):
        self._stream.write(msg)
        self._stream.flush()
        self._buf += msg
        while '\n' in self._buf:
            line, self._buf = self._buf.split('\n', 1)
            line = line.rstrip()
            if line:
                self._logger.log(self._level, '[stderr] %s', line)

    def flush(self):
        self._stream.flush()

    def fileno(self):          # needed by some C extensions
        return self._stream.fileno()

    def isatty(self):
        return self._stream.isatty()


def setup_logging(log_file=None):
    """Configure logging to the console and optionally to a file.

    Attaches handlers directly to the root logger rather than using
    ``basicConfig``, so the configuration takes effect even if an imported
    library (e.g. GRaSP, SpiceyPy, pandas) has already attached its own
    handler and made ``basicConfig`` a no-op.

    Also redirects ``sys.stderr`` through a tee so that any output written
    directly to stderr by C extensions or third-party libraries is captured
    in the log at WARNING level.

    Args:
        log_file (Path or None): If provided, log output is also written to
            this file (appended if it already exists). If None, output goes
            to the console only. Defaults to None.
    """
    fmt       = '%(asctime)s  %(levelname)-8s  %(message)s'
    datefmt   = '%Y-%m-%d %H:%M:%S'
    formatter = logging.Formatter(fmt, datefmt=datefmt)

    root = logging.getLogger()
    root.setLevel(logging.DEBUG)

    # Remove any handlers that imported libraries may have already registered,
    # then re-add ours so format and level are fully under our control.
    root.handlers.clear()

    console = logging.StreamHandler(sys.stdout)
    console.setLevel(logging.INFO)
    console.setFormatter(formatter)
    root.addHandler(console)

    if log_file:
        fh = logging.FileHandler(log_file, mode='a', encoding='utf-8')
        fh.setLevel(logging.DEBUG)
        fh.setFormatter(formatter)
        root.addHandler(fh)

    # Redirect stderr so that C-level / SpiceyPy / GRaSP prints are captured.
    sys.stderr = _TeeStream(sys.__stderr__, root, level=logging.WARNING)

# ── SPICE ─────────────────────────────────────────────────────────────────────

def setup_spice(mk_file):
    """Furnish SPICE kernels and return Mars Express mission parameters.

    Loads the MEX comprehensive metakernel via GRaSP and retrieves the
    mission-specific SPICE parameters needed for state vector and geometry
    computations. Unlike MRO, Mars Express uses a single metakernel covering
    the full mission so this function is called once at startup.

    Args:
        mk_file (Path): Absolute path to the MEX SPICE metakernel (.TM) file.

    Returns:
        dict: A dictionary containing the following keys:

            - ``obsrvr_str`` (str): SPICE observer string for Mars Express.
            - ``fix_ref_str`` (str): Body-fixed reference frame string.
            - ``ab_corr`` (str): Aberration correction flag (e.g. ``'LT+S'``).
            - ``target_str`` (str): SPICE target body string (e.g. ``'MARS'``).
            - ``utc`` (bool): Whether observation times are in UTC (always False).
    """
    grasp.furnish(str(mk_file))
    params = grasp.grab_spice_params('MEX')
    return {
        'obsrvr_str':  params['OBSRVRSTR'],
        'fix_ref_str': params['FIXREFSTR'],
        'ab_corr':     params['ABCORR'],
        'target_str':  params['TARGETSTR'],
        'utc':         False,
    }


# ── Database ──────────────────────────────────────────────────────────────────

def get_connection(env_path):
    """Load database credentials from a .env file and return a PyMySQL connection.

    Args:
        env_path (Path): Absolute path to the .env file containing DB_HOST,
            DB_DATABASE, DB_USERNAME, and DB_PASSWORD.

    Returns:
        pymysql.connections.Connection: An open PyMySQL database connection.

    Raises:
        pymysql.err.OperationalError: If the connection cannot be established
            with the provided credentials.
    """
    load_dotenv(env_path)
    return sql.connect(
        host=os.getenv('DB_HOST'),
        database=os.getenv('DB_DATABASE'),
        user=os.getenv('DB_USERNAME'),
        password=os.getenv('DB_PASSWORD'),
    )


def get_body_radii(cursor, body_id):
    """Query the bodies table and return a pyproj Geod for the given body.

    Retrieves the equatorial and polar radii from the PORPASS bodies table and
    constructs a pyproj Geod object representing the body's ellipsoid. This is
    used to compute accurate geodesic ground track lengths.

    Args:
        cursor (pymysql.cursors.Cursor): An open database cursor.
        body_id (int): The body_id to look up in the bodies table.

    Returns:
        pyproj.Geod: A Geod object initialised with the body's ellipsoid.

    Raises:
        ValueError: If no body with the given body_id exists in the table.
    """
    cursor.execute(
        'SELECT equatorial_radius_km, polar_radius_km FROM bodies WHERE body_id = %s',
        (body_id,)
    )
    row = cursor.fetchone()
    if row is None:
        raise ValueError(f"No body found with body_id={body_id}")
    a_km, b_km = row
    # pyproj expects metres; cast from decimal.Decimal to float
    return Geod(a=float(a_km) * 1000, b=float(b_km) * 1000)


# ── Geometry helpers ──────────────────────────────────────────────────────────

def decimate_et(et):
    """Decimate an ET array to approximately 1-second intervals.

    Selects one sample per elapsed second relative to the observation start,
    always preserving the first and last samples to ensure the ground track
    endpoints are accurate.

    Args:
        et (np.ndarray): Array of ephemeris times in seconds past J2000.

    Returns:
        np.ndarray: Integer indices into ``et`` of the decimated samples.
    """
    et_rel  = et - et[0]
    _, idx  = np.unique(np.floor(et_rel).astype(int), return_index=True)
    idx     = np.sort(np.union1d(idx, [len(et) - 1]))
    return idx


def geodesic_length_km(lon, lat, geod):
    """Compute the total geodesic length of a ground track in kilometres.

    Sums the ellipsoidal geodesic distances between consecutive (lon, lat)
    pairs using a pyproj Geod object. This accounts for the body's flattening
    and is more accurate than a spherical haversine approximation.

    Args:
        lon (np.ndarray): Longitudes in degrees.
        lat (np.ndarray): Latitudes in degrees.
        geod (pyproj.Geod): A Geod object for the target body's ellipsoid,
            as returned by :func:`get_body_radii`.

    Returns:
        float: Total ground track length in kilometres.
    """
    _, _, distances_m = geod.inv(lon[:-1], lat[:-1], lon[1:], lat[1:])
    return float(np.sum(distances_m) / 1000.0)


# ── Import ────────────────────────────────────────────────────────────────────

def fetch_aux_file(row):
    """Resolve the PDS URL for a MARSIS auxiliary file and download if needed.

    Constructs the URL from the index row's DATA_SET_ID and
    FILE_SPECIFICATION_NAME, derives the local cache path, and downloads the
    file if it is not already present locally.

    Args:
        row (pandas.core.frame.pandas): A named tuple row from the EDR index
            DataFrame, as produced by ``df.itertuples()``.

    Returns:
        Path: Local path to the auxiliary file.

    Raises:
        URLError: If the file cannot be downloaded from the PDS server.
        ValueError: If the DATA_SET_ID in the index row is not recognised.
    """
    if row.DATA_SET_ID.strip() == 'MEX-M-MARSIS-2-EDR-V2.0':
        data_set_id = 'MEX-M-MARSIS-2-EDR-V2'
        archive = "1001"
    elif row.DATA_SET_ID.strip() == 'MEX-M-MARSIS-2-EDR-EXT1-V2.0':
        data_set_id = 'MEX-M-MARSIS-2-EDR-EXT1-V2'
        archive = "1002"
    elif row.DATA_SET_ID.strip() == 'MEX-M-MARSIS-2-EDR-EXT2-V1.0':
        data_set_id = 'MEX-M-MARSIS-2-EDR-EXT2-V1'
        archive = "1003"
    elif row.DATA_SET_ID.strip() == 'MEX-M-MARSIS-2-EDR-EXT3-V1.0':
        data_set_id = 'MEX-M-MARSIS-2-EDR-EXT3-V1'
        archive = "1004"
    elif row.DATA_SET_ID.strip() == 'MEX-M-MARSIS-2-EDR-EXT4-V1.0':
        data_set_id = 'MEX-M-MARSIS-2-EDR-EXT4-V1'
        archive = "1005"
    elif row.DATA_SET_ID.strip() == 'MEX-M-MARSIS-2-EDR-EXT5-V1.0':
        data_set_id = 'MEX-M-MARSIS-2-EDR-EXT5-V1'
        archive = "1006"
    elif row.DATA_SET_ID.strip() == 'MEX-M-MARSIS-2-EDR-EXT6-V1.0':
        data_set_id = 'MEX-M-MARSIS-2-EDR-EXT6-V1'
        archive = "1007"
    elif row.DATA_SET_ID.strip() == 'MEX-M-MARSIS-2-EDR-EXT7-V1.0':
        data_set_id = 'MEX-M-MARSIS-2-EDR-EXT7-V1'
        archive = "1008"
    else:
        logging.warning("Unrecognised DATA_SET_ID: %s", row.DATA_SET_ID.strip())
        raise ValueError(f"Unrecognised DATA_SET_ID: {row.DATA_SET_ID.strip()}")
    arch = f"mexme_{archive}"
    url  = URL_MAIN
    url += data_set_id + '/' + arch
    url += '/' + row.FILE_SPECIFICATION_NAME.strip()
    url  = url[:-4] + "_G.DAT"

    local_path = AUX_DIR / os.path.basename(url).lower()

    if not local_path.exists():
        logging.debug("Downloading %s", url)
        urlretrieve(url, local_path)

    return local_path


def process_row(row, cursor, spice, geod):
    """Process one EDR index row and insert one observation into the database.

    Downloads the MARSIS auxiliary file if not already cached, determines the
    target body from the filename designator (m=Mars, p=Phobos, t=transit),
    skips transit observations, computes sub-spacecraft ground track geometry
    via GRaSP, decimates to 1-second intervals, and executes a parameterised
    INSERT into the observations table. Metakernel furnishing is handled by
    the caller before this function is invoked.

    Args:
        row (pandas.core.frame.pandas): A named tuple row from the EDR index
            DataFrame, as produced by ``df.itertuples()``.
        cursor (pymysql.cursors.Cursor): An open database cursor.
        spice (dict): MRO SPICE parameter dictionary from
            ``grasp.grab_spice_params('MEX')``.
        geod (pyproj.Geod): Geod object for Mars's ellipsoid, as returned by
            :func:`get_body_radii`.

    Returns:
        bool: True if the INSERT was executed successfully, False if the
            observation was skipped.

    Raises:
        Exception: Any exception raised during processing is propagated to the
            caller, which logs it and continues to the next row.
    """
    local_path = fetch_aux_file(row)

    # Derive native_id before reading the aux file so we can check for
    # duplicates without paying the cost of a full file read.
    bn        = local_path.name
    prts      = bn.split('_')
    native_id = '_'.join(prts[0:6])
    target    = prts[5]   # single character: m=Mars, p=Phobos, t=transit

    if target == 't':
        logging.info("SKIP %s: transit observation", local_path.name)
        return False
    elif target == 'm':
        body_id = 1   # Mars
    elif target == 'p':
        body_id = 4   # Phobos
    else:
        raise ValueError(f"Unrecognised target designator '{target}' in {local_path.name}")

    cursor.execute(
        '''SELECT o.observation_id
           FROM observations o
           LEFT JOIN marsis_observations mo ON mo.observation_id = o.observation_id
           WHERE o.instrument_id = %s AND o.native_id = %s''',
        (INST_ID, native_id),
    )
    existing = cursor.fetchone()
    if existing is not None:
        obs_id = existing[0]
        cursor.execute(
            'SELECT 1 FROM marsis_observations WHERE observation_id = %s',
            (obs_id,)
        )
        if cursor.fetchone() is not None:
            raise sql.IntegrityError(f"Duplicate native_id: {native_id}")
        # Base row exists but child row is missing — fall through to insert child only
        insert_base = False
    else:
        insert_base = True

    aux  = grasp.read(local_path)
    et   = aux['GEOMETRY_EPHEMERIS_TIME']

    if len(et) < 2:
        logging.warning(
            "SKIP %s: only %d valid data record(s) — need ≥ 2 for a valid LineString",
            local_path.name, len(et),
        )
        return False

    start_time = aux['GEOMETRY_EPOCH'][0]
    stop_time  = aux['GEOMETRY_EPOCH'][-1]

    vctrs = grasp.compute_state_vectors(
        et,
        spice['obsrvr_str'],
        spice['target_str'],
        spice['fix_ref_str'],
        ab_corr=spice['ab_corr'],
        intercept_method_str=METHOD,
        utc=False,
    )
    geom = grasp.compute_geometry(vctrs.r_t, vctrs.r_s, vctrs.r_st)

    # Decimate to ~1-second intervals before constructing the LineString
    idx = decimate_et(et)
    lon = np.atleast_1d(geom.longitude)[idx]
    lat = np.atleast_1d(geom.latitude)[idx]

    coords = [(lo, la) for lo, la in zip(lon, lat)]
    line   = LineString(coords)

    duration  = float((et[-1] - et[0]))
    length_km = geodesic_length_km(lon, lat, geod)

    # MARSIS child table fields
    mode_name    = prts[2].upper()   # e.g. ss2 -> SS2
    state_name   = prts[3].upper()   # e.g. trk -> TRK
    form_name    = prts[4].upper()   # e.g. cmp -> CMP
    orbit_number = int(prts[1])
    l_s          = float(np.mean(aux['MARS_SOLAR_LONGITUDE']))
    altitude     = aux['SPACECRAFT_ALTITUDE']
    sza          = aux['SOLAR_ZENITH_ANGLE']
    start_altitude = float(altitude[0])
    stop_altitude  = float(altitude[-1])
    start_sza      = float(sza[0])
    stop_sza       = float(sza[-1])
    mean_sza       = float(np.mean(sza))

    # Look up mode_id, state_id, form_id from lookup tables
    cursor.execute('SELECT mode_id FROM marsis_modes WHERE mode_name = %s', (mode_name,))
    mode_row = cursor.fetchone()
    if mode_row is None:
        raise ValueError(f"Unrecognised MARSIS mode '{mode_name}' in {local_path.name}")
    mode_id = mode_row[0]

    cursor.execute('SELECT state_id FROM marsis_states WHERE state_name = %s', (state_name,))
    state_row = cursor.fetchone()
    if state_row is None:
        raise ValueError(f"Unrecognised MARSIS state '{state_name}' in {local_path.name}")
    state_id = state_row[0]

    cursor.execute('SELECT form_id FROM marsis_forms WHERE form_name = %s', (form_name,))
    form_row = cursor.fetchone()
    if form_row is None:
        raise ValueError(f"Unrecognised MARSIS form '{form_name}' in {local_path.name}")
    form_id = form_row[0]

    if insert_base:
        cursor.execute(INSERT_QRY, (
            INST_ID,
            body_id,
            native_id,
            pd.Timestamp(start_time),
            pd.Timestamp(stop_time),
            duration,
            length_km,
            line.wkt,
        ))
        obs_id = cursor.lastrowid

    cursor.execute(INSERT_CHILD_QRY, (
        obs_id,
        mode_id,
        state_id,
        form_id,
        orbit_number,
        l_s,
        start_altitude,
        stop_altitude,
        start_sza,
        stop_sza,
        mean_sza,
    ))

    return True


def load_marsis_edr(dry_run=False, limit=None, log_file=None, commit_interval=100):
    """Import MARSIS EDR observations from the PDS archive into the PORPASS database.

    Reads the cumulative EDR index file, iterates over each row, downloads the
    corresponding auxiliary file if needed, and calls :func:`process_row` for
    each one. Failed rows are logged and skipped; processing continues with the
    next row. At the end of the run, changes are either committed (live mode)
    or rolled back (dry run mode).

    Args:
        dry_run (bool): If True, execute all INSERT statements but roll back
            the transaction at the end so no data is written to the database.
            Defaults to False.
        limit (int or None): If provided, process only the first ``limit`` rows
            of the index file. Useful for testing. If None, all rows are
            processed. Defaults to None.
        log_file (Path or None): If provided, log output is written to this
            file in addition to the console. Passed directly to
            :func:`setup_logging`. Defaults to None.
        commit_interval (int): Number of successful inserts between intermediate
            commits. Ignored in dry run mode. Defaults to 100.

    Returns:
        None

    Raises:
        SystemExit: If any of the required paths (MK_PATH, AUX_DIR, IDX_FILE,
            or .env file) do not exist, the function logs an error message and
            exits with status 1.
    """
    setup_logging(log_file)

    # Validate paths
    for path, label in [
        (MK_FILE,   'SPICE metakernel directory'),
        (AUX_DIR,   'Auxiliary file cache directory'),
        (IDX_FILE,  'EDR index file'),
        (ENV_PATH,  '.env file'),
    ]:
        if not path.exists():
            logging.error(f"{label} not found: {path}")
            sys.exit(1)

    logging.info(f"Mode: {'DRY RUN' if dry_run else 'LIVE'}")

    # Set up SPICE
    logging.info("Furnishing SPICE kernels...")
    spice = setup_spice(MK_FILE)

    # Load EDR index
    logging.info(f"Reading EDR index: {IDX_FILE}")
    df = pd.read_csv(IDX_FILE, names=EDR_NAMES, header=None, engine='python')
    if limit:
        df = df.iloc[:limit]
    logging.info(f"Processing {len(df)} index rows...")

    # Connect to database
    logging.info("Connecting to database...")
    cnx    = get_connection(ENV_PATH)
    cursor = cnx.cursor()

    # Build geodesic object from body radii stored in the bodies table
    logging.info("Loading body radii from database...")
    geod = get_body_radii(cursor, BODY_ID)

    # Import loop
    n_success   = 0
    n_duplicate = 0
    n_skipped   = 0
    n_failed    = 0
    n_total     = 0
    for row in df.itertuples():
        n_total += 1
        logging.debug("Row %d: %s", row.Index, row.FILE_SPECIFICATION_NAME.strip())

        try:
            result = process_row(row, cursor, spice, geod)
            if result:
                n_success += 1
                logging.info(f"  OK        {row.PRODUCT_ID.strip()}")
                if not dry_run and n_success % commit_interval == 0:
                    cnx.commit()
                    logging.debug("Intermediate commit at %d successful inserts.", n_success)
            else:
                n_skipped += 1
        except URLError as e:
            n_failed += 1
            logging.warning(
                "  FAIL (download) %s: %s",
                row.FILE_SPECIFICATION_NAME.strip(), e,
            )
            continue
        except sql.IntegrityError:
            n_duplicate += 1
            logging.info(f"  DUPLICATE {row.PRODUCT_ID.strip()}")
            continue
        except Exception as e:
            n_failed += 1
            logging.warning(
                "  FAIL %s: %s\n%s",
                row.FILE_SPECIFICATION_NAME.strip(),
                e,
                traceback.format_exc().rstrip(),
            )
            continue

    # Commit or rollback
    logging.info(
        f"Results: {n_success} inserted, {n_duplicate} duplicate, "
        f"{n_skipped} skipped, {n_failed} failed out of {n_total} total."
    )
    if dry_run:
        logging.info("Dry run complete — rolling back.")
        cnx.rollback()
    else:
        cnx.commit()
        logging.info("Committed successfully.")

    cursor.close()
    cnx.close()


# ── Entry point ───────────────────────────────────────────────────────────────

def parse_args():
    """Parse command-line arguments for the MARSIS EDR import script.

    Returns:
        argparse.Namespace: Parsed arguments with the following attributes:

            - ``dry_run`` (bool): Whether to roll back instead of committing.
            - ``limit`` (int or None): Maximum number of index rows to process,
              or None to process all.
            - ``log_file`` (Path or None): Path to a log file, or None if not
              specified.
            - ``commit_interval`` (int): Number of successful inserts between
              intermediate commits.
    """
    parser = argparse.ArgumentParser(
        description="Import MARSIS EDR observations into the PORPASS database."
    )
    parser.add_argument(
        '--dry-run',
        action='store_true',
        default=False,
        help='Execute queries but roll back at the end (no data written).'
    )
    parser.add_argument(
        '--limit',
        type=int,
        default=None,
        metavar='N',
        help='Process only the first N rows of the EDR index file.'
    )
    parser.add_argument(
        '--log-file',
        type=Path,
        default=None,
        metavar='PATH',
        help='Path to a log file. If omitted, output goes to the console only.'
    )
    parser.add_argument(
        '--commit-interval',
        type=int,
        default=100,
        metavar='N',
        help='Commit to the database every N successful inserts (default: 100). Ignored in dry run mode.'
    )
    return parser.parse_args()


if __name__ == '__main__':
    args = parse_args()
    load_marsis_edr(
        dry_run=args.dry_run,
        limit=args.limit,
        log_file=args.log_file,
        commit_interval=args.commit_interval,
    )
