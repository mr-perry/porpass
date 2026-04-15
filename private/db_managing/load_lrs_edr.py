#!/usr/bin/env python3
"""Import LRS EDR observations into the PORPASS database.

This script reads LRS waveform science files from the SELENE/Kaguya PDS archive,
computes sub-spacecraft ground track geometry and illumination angles using SPICE
kernels via GRaSP, and inserts one observation row per science file into the
PORPASS observations and lrs_observations tables. Both SW and SA mode files are
handled automatically based on the filename.

Progress is committed to the database every --commit-interval successful inserts
(default: 100) to preserve work in the event of a crash or stall.

Usage:
    python load_lrs_edr.py [--dry-run] [--limit N] [--log-file PATH]
                           [--commit-interval N]

Options:
    --dry-run              Execute queries but roll back at the end (default: False)
    --limit N              Process only the first N date directories (useful for testing)
    --commit-interval N    Commit every N successful inserts (default: 100)
    --log-file PATH        Write log output to this file in addition to the console.
                           The file receives DEBUG-level output (more verbose than
                           the console). stderr from third-party libraries is also
                           captured at WARNING level.

Examples:
    Dry run::

        python load_lrs_edr.py --dry-run

    Import first 5 date directories only::

        python load_lrs_edr.py --limit 5

    Full import with log file::

        python load_lrs_edr.py --log-file /tmp/lrs_edr_import.log

"""

import logging
import os
import sys
import argparse
import traceback
from pathlib import Path

import numpy as np
import pandas as pd
from pyproj import Geod
from shapely.geometry import LineString
import pymysql as sql
from dotenv import load_dotenv

# Add GRaSP to path
sys.path.append("/Users/mrperry/python/grasp/")
import grasp


# ── Configuration ────────────────────────────────────────────────────────────

ENV_PATH    = Path(__file__).resolve().parents[2] / '.env'
MK_FILE     = Path("/Volumes/data/NAIF/pds/sln-l-spice-6-v1.0/slnsp_1000/extras/mk/SEL_V02.TM")
LRS_ARCHIVE = Path("/Volumes/data/SELENE/lrs/darts/sln-l-lrs-2-sndr-waveform-low-v1.0")
DATA_GLOB   = os.path.join("data", "LRS_??_??_*.tbl")
INST_ID     = 1   # LRS instrument_id in porpass_dev
BODY_ID     = 3   # Moon body_id in porpass_dev
METHOD      = "NEAR POINT/ELLIPSOID"

INSERT_QRY  = '''INSERT INTO observations
    (instrument_id, body_id, native_id, start_time, stop_time,
     duration, length_km, geometry)
    VALUES (%s, %s, %s, %s, %s, %s, %s, ST_GeomFromText(%s, 0))'''

INSERT_CHILD_QRY = '''INSERT INTO lrs_observations
    (observation_id, mode_id, observation_type, orbit_number,
     mean_altitude, start_sza, stop_sza, mean_sza)
    VALUES (%s, %s, %s, %s, %s, %s, %s, %s)'''


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
    fmt     = '%(asctime)s  %(levelname)-8s  %(message)s'
    datefmt = '%Y-%m-%d %H:%M:%S'
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
        fh.setLevel(logging.DEBUG)   # capture DEBUG-level detail in the file
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


# ── SPICE ─────────────────────────────────────────────────────────────────────

def setup_spice(mk_file):
    """Furnish SPICE kernels and return SELENE mission parameters.

    Loads the SELENE metakernel via GRaSP and retrieves the mission-specific
    SPICE parameters needed for state vector and geometry computations.

    Args:
        mk_file (Path): Absolute path to the SELENE SPICE metakernel (.TM) file.

    Returns:
        dict: A dictionary containing the following keys:

            - ``obsrvr_str`` (str): SPICE observer string for SELENE.
            - ``fix_ref_str`` (str): Body-fixed reference frame string.
            - ``ab_corr`` (str): Aberration correction flag (e.g. ``'LT+S'``).
            - ``target_str`` (str): SPICE target body string (e.g. ``'MOON'``).
            - ``utc`` (bool): Whether observation times are in UTC (always True).
    """
    grasp.furnish(str(mk_file))
    params = grasp.grab_spice_params('SELENE')
    return {
        'obsrvr_str':  params['OBSRVRSTR'],
        'fix_ref_str': params['FIXREFSTR'],
        'ab_corr':     params['ABCORR'],
        'target_str':  params['TARGETSTR'],
        'utc':         True,
    }


# ── Import ────────────────────────────────────────────────────────────────────

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
    # pyproj expects metres
    return Geod(a=float(a_km) * 1000, b=float(b_km) * 1000)


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


def decimate_utc(utc):
    """Decimate a UTC timestamp array to approximately 1-second intervals.

    Truncates each UTC string to whole seconds, selects the index of the first
    sample within each unique second, and always preserves the first and last
    samples to ensure the ground track endpoints are accurate.

    Args:
        utc (np.ndarray): Array of numpy datetime64 objects with sub-second
            precision.

    Returns:
        np.ndarray: Integer indices into ``utc`` of the decimated samples.
    """
    truncated = utc.astype('datetime64[s]')   # truncate to second precision
    _, idx    = np.unique(truncated, return_index=True)
    idx       = np.sort(np.union1d(idx, [len(utc) - 1]))
    return idx


def process_file(sci_file, cursor, spice, inst_id, body_id, geod):
    """Process a single LRS science file and insert one observation row.

    Reads an LRS waveform science file, decimates the observation time array
    to 1-second UTC intervals (preserving first and last samples), applies
    Ramer-Douglas-Peucker simplification (tolerance=0.001 degrees) to reduce point
    count while preserving track shape, converts longitudes from 0-360 to -180/180
    convention, then computes sub-spacecraft ground track geometry and
    illumination angles using SPICE via GRaSP, constructs a WKT LineString
    from the resulting longitude/latitude pairs, computes duration, geodesic
    ground track length, mean altitude, and solar zenith angles, and executes
    parameterised INSERTs into the observations and lrs_observations tables.
    The mode (SW or SA) is derived from the filename.

    Args:
        sci_file (Path): Path to the LRS .tbl science file to process.
        cursor (pymysql.cursors.Cursor): An open database cursor on which to
            execute the INSERT statements.
        spice (dict): SPICE parameter dictionary as returned by
            :func:`setup_spice`.
        inst_id (int): instrument_id for LRS in the PORPASS database.
        body_id (int): body_id for the Moon in the PORPASS database.
        geod (pyproj.Geod): Geod object for the target body's ellipsoid, as
            returned by :func:`get_body_radii`.

    Returns:
        bool: True if the INSERTs were executed successfully, False if the
            observation was skipped.

    Raises:
        Exception: Any exception raised by GRaSP or PyMySQL is propagated to
            the caller, which logs it and continues to the next file.
    """
    native_id = sci_file.stem  # basename without extension

    # Check for duplicates before reading the file
    cursor.execute(
        '''SELECT o.observation_id
           FROM observations o
           LEFT JOIN lrs_observations lo ON lo.observation_id = o.observation_id
           WHERE o.instrument_id = %s AND o.native_id = %s''',
        (inst_id, native_id),
    )
    existing = cursor.fetchone()
    if existing is not None:
        obs_id = existing[0]
        cursor.execute(
            'SELECT 1 FROM lrs_observations WHERE observation_id = %s',
            (obs_id,)
        )
        if cursor.fetchone() is not None:
            raise sql.IntegrityError(f"Duplicate native_id: {native_id}")
        insert_base = False
    else:
        insert_base = True

    sci = grasp.read(sci_file)
    et  = sci['OBSERVATION_TIME']

    # Coerce to array in case the file has only a single record (scalar)
    et = np.atleast_1d(et)
    if len(et) < 2:
        logging.warning(
            "SKIP %s: only %d data record(s) — need >= 2 for a valid LineString",
            sci_file.name, len(et),
        )
        return False

    et = et[decimate_utc(et)]

    vctrs = grasp.compute_state_vectors(
        et,
        spice['obsrvr_str'],
        spice['target_str'],
        spice['fix_ref_str'],
        ab_corr=spice['ab_corr'],
        intercept_method_str=METHOD,
        utc=spice['utc'],
    )
    geom = grasp.compute_geometry(vctrs.r_t, vctrs.r_s, vctrs.r_st)

    lon = np.atleast_1d(geom.longitude)
    lon = np.where(lon > 180, lon - 360, lon)   # convert 0-360 to -180/180
    lat = np.atleast_1d(geom.latitude)
    if len(lon) < 2:
        logging.warning(
            "SKIP %s: only %d data record(s) — need ≥ 2 for a valid LineString",
            sci_file.name, len(lon),
        )
        return False

    coords        = [(lo, la) for lo, la in zip(lon, lat)]
    line          = LineString(coords).simplify(0.001, preserve_topology=False)
    start_time    = pd.Timestamp(et[0])
    stop_time     = pd.Timestamp(et[-1])
    duration      = (stop_time - start_time).total_seconds()
    length_km     = geodesic_length_km(lon, lat, geod)
    mean_altitude = float(np.mean(geom.altitude))

    # Compute solar zenith angles via SPICE
    illum     = grasp.compute_sza(
        et,
        spice['obsrvr_str'],
        spice['target_str'],
        vctrs.r_t,
        ab_corr=spice['ab_corr'],
        utc=spice['utc'],
    )
    sza       = np.degrees(illum.solar)
    start_sza = float(sza[0])
    stop_sza  = float(sza[-1])
    mean_sza  = float(np.mean(sza))

    # Derive mode from filename: LRS_SW_... or LRS_SA_...
    mode_name = sci_file.stem.split('_')[1]   # 'SW' or 'SA'
    cursor.execute(
        'SELECT mode_id FROM lrs_modes WHERE mode_name = %s',
        (mode_name,)
    )
    mode_row = cursor.fetchone()
    if mode_row is None:
        raise ValueError(f"Unrecognised LRS mode '{mode_name}' in {sci_file.name}")
    mode_id = mode_row[0]

    if insert_base:
        cursor.execute(INSERT_QRY, (
            inst_id,
            body_id,
            native_id,
            start_time,
            stop_time,
            duration,
            length_km,
            line.wkt,
        ))
        obs_id = cursor.lastrowid

    cursor.execute(INSERT_CHILD_QRY, (
        obs_id,
        mode_id,
        'EDR',
        None,           # orbit_number -- NULL until derivation method is known
        mean_altitude,
        start_sza,
        stop_sza,
        mean_sza,
    ))
    return True


def load_lrs_edr(dry_run=False, limit=None, log_file=None, commit_interval=100):
    """Import LRS observations from the PDS archive into the PORPASS database.

    Iterates over date subdirectories in the LRS archive, finds all .tbl
    science files within each directory, and calls :func:`process_file` for
    each one. Failed files are logged and skipped; processing continues with
    the next file. At the end of the run, changes are either committed (live
    mode) or rolled back (dry run mode).

    Args:
        dry_run (bool): If True, execute all INSERT statements but roll back
            the transaction at the end so no data is written to the database.
            Defaults to False.
        limit (int or None): If provided, process only the first ``limit``
            date directories in the archive. Useful for testing. If None,
            all date directories are processed. Defaults to None.
        log_file (Path or None): If provided, log output is written to this
            file in addition to the console. Passed directly to
            :func:`setup_logging`. Defaults to None.
        commit_interval (int): Number of successful inserts between intermediate
            commits. Ignored in dry run mode. Defaults to 100.

    Returns:
        None

    Raises:
        SystemExit: If any of the required paths (SPICE metakernel, LRS
            archive, or .env file) do not exist, the function logs an error
            message and exits with status 1.
    """
    setup_logging(log_file)

    # Validate paths
    if not MK_FILE.exists():
        logging.error(f"SPICE metakernel not found: {MK_FILE}")
        sys.exit(1)
    if not LRS_ARCHIVE.exists():
        logging.error(f"LRS archive not found: {LRS_ARCHIVE}")
        sys.exit(1)
    if not ENV_PATH.exists():
        logging.error(f".env file not found: {ENV_PATH}")
        sys.exit(1)

    logging.info(f"Mode: {'DRY RUN' if dry_run else 'LIVE'}")

    # Set up SPICE
    logging.info("Furnishing SPICE kernels...")
    spice = setup_spice(MK_FILE)

    # Connect to database
    logging.info("Connecting to database...")
    cnx    = get_connection(ENV_PATH)
    cursor = cnx.cursor()

    # Build geodesic object from body radii stored in the bodies table
    logging.info("Loading body radii from database...")
    geod = get_body_radii(cursor, BODY_ID)

    # Find date directories
    date_dirs = sorted(LRS_ARCHIVE.glob('200?????'))
    if limit:
        date_dirs = date_dirs[:limit]
    logging.info(f"Processing {len(date_dirs)} date directories...")

    # Import loop
    n_success   = 0
    n_duplicate = 0
    n_skipped   = 0
    n_failed    = 0
    n_total     = 0

    for date_dir in date_dirs:
        sci_files = sorted(date_dir.glob(DATA_GLOB))
        for sci_file in sci_files:
            n_total += 1
            try:
                result = process_file(sci_file, cursor, spice, INST_ID, BODY_ID, geod)
                if result:
                    n_success += 1
                    logging.info(f"  OK        {sci_file.name}")
                    if not dry_run and n_success % commit_interval == 0:
                        cnx.commit()
                        logging.debug("Intermediate commit at %d successful inserts.", n_success)
                else:
                    n_skipped += 1
            except sql.IntegrityError:
                n_duplicate += 1
                logging.info(f"  DUPLICATE {sci_file.name}")
                continue
            except Exception as e:
                n_failed += 1
                logging.warning(
                    "  FAIL %s: %s\n%s",
                    sci_file.name,
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
    """Parse command-line arguments for the LRS import script.

    Returns:
        argparse.Namespace: Parsed arguments with the following attributes:

            - ``dry_run`` (bool): Whether to roll back instead of committing.
            - ``limit`` (int or None): Maximum number of date directories to
              process, or None to process all.
            - ``log_file`` (Path or None): Path to a log file, or None if not
              specified.
            - ``commit_interval`` (int): Number of successful inserts between
              intermediate commits.
    """
    parser = argparse.ArgumentParser(
        description="Import LRS EDR observations into the PORPASS database."
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
        help='Process only the first N date directories.'
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
    load_lrs_edr(
        dry_run=args.dry_run,
        limit=args.limit,
        log_file=args.log_file,
        commit_interval=args.commit_interval,
    )
