#!/usr/bin/env python3
"""Import SHARAD EDR observations into the PORPASS database.

This script reads SHARAD auxiliary files from the MRO PDS EDR archive,
downloading them from the PDS Geosciences Node if not already cached locally,
computes sub-spacecraft ground track geometry using SPICE kernels via GRaSP,
decimates the ground track to 1-second intervals, applies Ramer-Douglas-Peucker
simplification (tolerance=0.001°) to reduce point count while preserving track shape,
converts longitudes from 0-360 to -180/180 convention, and
inserts one observation row per auxiliary file into the PORPASS observations
table.

SHARAD EDRs provide ephemeris time (ET) directly, so no UTC conversion is
performed. MRO SPICE metakernels are year-based; the correct metakernel is
selected per observation from the START_TIME and STOP_TIME fields of the EDR
index file. If an observation spans a year boundary, both metakernels are
furnished. Previously furnished metakernels are unloaded before loading new
ones to avoid exceeding SPICE's kernel pool limits.

Progress is committed to the database every --commit-interval successful
inserts (default: 100) to preserve work in the event of a crash or stall.

Usage:
    python load_sharad_edr.py [--dry-run] [--limit N] [--log-file PATH]
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

        python load_sharad_edr.py --dry-run

    Import first 10 observations only::

        python load_sharad_edr.py --limit 10

    Full import with log file::

        python load_sharad_edr.py --log-file /tmp/sharad_edr_import.log

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
MK_PATH   = Path("/Volumes/data/NAIF/pds/mro-m-spice-6-v1.0/mrosp_1000/extras/mk/")
AUX_DIR   = Path("/Volumes/data/MRO/sharad/pds/edr/aux_files/")
IDX_FILE  = Path("/Volumes/data/MRO/sharad/pds/edr/cumindex.tab")
URL_MAIN  = 'https://pds-geosciences.wustl.edu/mro/mro-m-sharad-3-edr-v1/'
INST_ID   = 2   # SHARAD instrument_id in porpass_dev
BODY_ID   = 1   # Mars body_id in porpass_dev
METHOD    = "NEAR POINT/ELLIPSOID"

EDR_NAMES = [
    'VOLUME_ID',
    'RELEASE_ID',
    'FILE_SPECIFICATION_NAME',
    'PRODUCT_ID',
    'PRODUCT_CREATION_TIME',
    'PRODUCT_VERSION_ID',
    'PRODUCT_VERSION_TYPE',
    'PRODUCT_TYPE',
    'MISSION_PHASE_NAME',
    'ORBIT_NUMBER',
    'START_TIME',
    'STOP_TIME',
    'SPACECRAFT_CLOCK_START_COUNT',
    'SPACECRAFT_CLOCK_STOP_COUNT',
    'MRO:START_SUB_SPACECRAFT_LATITUDE',
    'MRO:STOP_SUB_SPACECRAFT_LATITUDE',
    'MRO:START_SUB_SPACECRAFT_LONGITUDE',
    'MRO:STOP_SUB_SPACECRAFT_LONGITUDE',
    'INSTRUMENT_MODE_ID',
    'DATA_QUALITY_ID',
]

INSERT_QRY = '''INSERT INTO observations
    (instrument_id, body_id, native_id, start_time, stop_time,
     duration, length_km, geometry)
    VALUES (%s, %s, %s, %s, %s, %s, %s, ST_GeomFromText(%s, 0))'''

INSERT_CHILD_QRY = '''INSERT INTO sharad_observations
    (observation_id, mode_id, observation_type, orbit_number,
     max_roll, start_sza, stop_sza, mean_sza, l_s)
    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)'''


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


# ── SPICE ─────────────────────────────────────────────────────────────────────

def furnish_metakernels(start_year, stop_year, prev_mk_files):
    """Select, unload, and furnish the correct MRO SPICE metakernel(s).

    MRO metakernels are year-based. This function determines which metakernel
    file(s) are required for the observation, unloads any previously furnished
    metakernels if the year has changed, and furnishes the new ones.

    If an observation spans a year boundary both the start-year and stop-year
    metakernels are furnished.

    Args:
        start_year (int): Four-digit calendar year of the observation start,
            derived from the START_TIME field of the EDR index.
        stop_year (int): Four-digit calendar year of the observation stop,
            derived from the STOP_TIME field of the EDR index.
        prev_mk_files (list of Path): Metakernel files furnished for the
            previous observation. These are unloaded if the year has changed.

    Returns:
        list of Path: The metakernel file(s) furnished for this observation.
            Pass this return value as ``prev_mk_files`` for the next call.

    Raises:
        IndexError: If no metakernel matching the required year is found
            in MK_PATH.
    """
    mk_files = [sorted(MK_PATH.glob(f"*{start_year}*.tm"))[-1]]
    if stop_year != start_year:
        mk_files.append(sorted(MK_PATH.glob(f"*{stop_year}*.tm"))[-1])

    if mk_files != prev_mk_files:
        for mk in prev_mk_files:
            unload(str(mk))
        for mk in mk_files:
            grasp.furnish(str(mk))
        logging.debug("Furnished metakernel(s): %s", [mk.name for mk in mk_files])

    return mk_files


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
    """Resolve the PDS URL for a SHARAD auxiliary file and download if needed.

    Constructs the URL from the index row's VOLUME_ID and
    FILE_SPECIFICATION_NAME, derives the local cache path, and downloads the
    file if it is not already present locally.

    Args:
        row (pandas.core.frame.pandas): A named tuple row from the EDR index
            DataFrame, as produced by ``df.itertuples()``.

    Returns:
        Path: Local path to the auxiliary file.

    Raises:
        URLError: If the file cannot be downloaded from the PDS server.
    """
    url  = URL_MAIN
    url += row.VOLUME_ID.strip()
    url += '/' + row.FILE_SPECIFICATION_NAME.strip()
    url  = url[:-4] + "_A.DAT"

    local_path = AUX_DIR / os.path.basename(url).lower()

    if not local_path.exists():
        logging.debug("Downloading %s", url)
        urlretrieve(url, local_path)

    return local_path


def process_row(row, cursor, spice, geod):
    """Process one EDR index row and insert one observation into the database.

    Downloads the SHARAD auxiliary file if not already cached, computes
    sub-spacecraft ground track geometry via GRaSP, decimates to 1-second
    intervals, and executes a parameterised INSERT into the observations table.
    Metakernel furnishing is handled by the caller before this function is
    invoked.

    Args:
        row (pandas.core.frame.pandas): A named tuple row from the EDR index
            DataFrame, as produced by ``df.itertuples()``.
        cursor (pymysql.cursors.Cursor): An open database cursor.
        spice (dict): MRO SPICE parameter dictionary from
            ``grasp.grab_spice_params('MRO')``.
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
    native_id = '_'.join(prts[0:4])

    cursor.execute(
        '''SELECT o.observation_id
           FROM observations o
           LEFT JOIN sharad_observations so ON so.observation_id = o.observation_id
           WHERE o.instrument_id = %s AND o.native_id = %s''',
        (INST_ID, native_id),
    )
    existing = cursor.fetchone()
    if existing is not None:
        obs_id = existing[0]
        # Check if sharad_observations row is missing (self-healing re-run)
        cursor.execute(
            'SELECT 1 FROM sharad_observations WHERE observation_id = %s',
            (obs_id,)
        )
        if cursor.fetchone() is not None:
            raise sql.IntegrityError(f"Duplicate native_id: {native_id}")
        # Base row exists but child row is missing — fall through to insert child only
        insert_base = False
    else:
        insert_base = True

    aux  = grasp.read(local_path)
    mask = aux['CORRUPTED_DATA_FLAG'] == 0
    et   = aux['EPHEMERIS_TIME'][mask]

    if len(et) < 2:
        logging.warning(
            "SKIP %s: only %d valid data record(s) — need ≥ 2 for a valid LineString",
            local_path.name, len(et),
        )
        return False

    start_time = aux['GEOMETRY_EPOCH'][mask][0]
    stop_time  = aux['GEOMETRY_EPOCH'][mask][-1]

    vctrs = grasp.compute_state_vectors(
        et,
        spice['OBSRVRSTR'],
        spice['TARGETSTR'],
        spice['FIXREFSTR'],
        ab_corr=spice['ABCORR'],
        intercept_method_str=METHOD,
        utc=False,
    )

    geom = grasp.compute_geometry(vctrs.r_t, vctrs.r_s, vctrs.r_st)

    # Decimate to ~1-second intervals before constructing the LineString
    idx = decimate_et(et)
    lon = np.atleast_1d(geom.longitude)[idx]
    lon = np.where(lon > 180, lon - 360, lon)   # convert 0-360 to -180/180
    lat = np.atleast_1d(geom.latitude)[idx]

    coords = [(lo, la) for lo, la in zip(lon, lat)]
    line   = LineString(coords).simplify(0.001, preserve_topology=False)

    duration  = float((et[-1] - et[0]))
    length_km = geodesic_length_km(lon, lat, geod)

    # SHARAD child table fields
    mode_raw  = prts[3].upper()
    mode_name = mode_raw[0:2] + mode_raw[2:].zfill(2)   # e.g. SS7 -> SS07
    orbit_number = int(prts[1])
    max_roll  = float(np.max(aux['SC_ROLL_ANGLE'][mask]))
    sza       = aux['SOLAR_ZENITH_ANGLE'][mask]
    start_sza = float(sza[0])
    stop_sza  = float(sza[-1])
    mean_sza  = float(np.mean(sza))
    l_s       = float(np.mean(aux['SOLAR_LONGITUDE'][mask]))

    # Look up mode_id from sharad_modes
    cursor.execute(
        'SELECT mode_id FROM sharad_modes WHERE mode_name = %s',
        (mode_name,)
    )
    mode_row = cursor.fetchone()
    if mode_row is None:
        raise ValueError(f"Unrecognised SHARAD mode '{mode_name}' in {local_path.name}")
    mode_id = mode_row[0]

    if insert_base:
        cursor.execute(INSERT_QRY, (
            INST_ID,
            BODY_ID,
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
        'EDR',
        orbit_number,
        max_roll,
        start_sza,
        stop_sza,
        mean_sza,
        l_s,
    ))

    return True


def load_sharad_edr(dry_run=False, limit=None, log_file=None, commit_interval=100):
    """Import SHARAD EDR observations from the PDS archive into the PORPASS database.

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
        (MK_PATH,   'SPICE metakernel directory'),
        (AUX_DIR,   'Auxiliary file cache directory'),
        (IDX_FILE,  'EDR index file'),
        (ENV_PATH,  '.env file'),
    ]:
        if not path.exists():
            logging.error(f"{label} not found: {path}")
            sys.exit(1)

    logging.info(f"Mode: {'DRY RUN' if dry_run else 'LIVE'}")

    # Load EDR index
    logging.info(f"Reading EDR index: {IDX_FILE}")
    df = pd.read_csv(IDX_FILE, names=EDR_NAMES, header=None, engine='python')
    if limit:
        df = df.iloc[:limit]
    logging.info(f"Processing {len(df)} index rows...")

    # Grab MRO SPICE parameters (does not furnish kernels yet)
    spice = grasp.grab_spice_params('MRO')

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
    prev_mk_files = []

    for row in df.itertuples():
        n_total += 1
        logging.debug("Row %d: %s", row.Index, row.FILE_SPECIFICATION_NAME.strip())

        # Furnish the correct metakernel(s) based on the index timestamps.
        # This is done outside the try/except so prev_mk_files is always
        # updated even when process_row raises an exception or is skipped.
        try:
            start_year    = pd.to_datetime(row.START_TIME.strip(), format='%Y-%jT%H:%M:%S.%f').year
            stop_year     = pd.to_datetime(row.STOP_TIME.strip(),  format='%Y-%jT%H:%M:%S.%f').year
            prev_mk_files = furnish_metakernels(start_year, stop_year, prev_mk_files)
        except Exception as e:
            n_failed += 1
            logging.warning(
                "  FAIL (metakernel) %s: %s",
                row.FILE_SPECIFICATION_NAME.strip(), e,
            )
            continue

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
    """Parse command-line arguments for the SHARAD EDR import script.

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
        description="Import SHARAD EDR observations into the PORPASS database."
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
    load_sharad_edr(
        dry_run=args.dry_run,
        limit=args.limit,
        log_file=args.log_file,
        commit_interval=args.commit_interval,
    )
