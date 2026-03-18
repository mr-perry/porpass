#!/usr/bin/env python3
"""Import LRS radar sounder observations into the PORPASS database.

This script reads LRS waveform science files from the SELENE/Kaguya PDS archive,
computes sub-spacecraft ground track geometry using SPICE kernels via GRaSP, and
inserts one observation row per science file into the PORPASS observations table.

Usage:
    python load_lrs.py [--dry-run] [--limit N] [--log-file PATH]

Options:
    --dry-run          Execute queries but roll back at the end (default: False)
    --limit N          Process only the first N date directories (useful for testing)
    --log-file PATH    Write log output to this file in addition to the console

Examples:
    Dry run — validates without writing to the database::

        python load_lrs.py --dry-run

    Import first 5 date directories only::

        python load_lrs.py --limit 5

    Full import with log file::

        python load_lrs.py --log-file /tmp/lrs_import.log

Note:
    science_file_id is set to NULL for all inserted rows until the PORPASS
    files table is designed and implemented.
"""

import logging
import os
import sys
import argparse
from pathlib import Path

import pandas as pd
from shapely.geometry import LineString
import pymysql as sql
from dotenv import load_dotenv

# Add GRaSP to path
sys.path.append("/Users/mrperry/python/grasp/")
import grasp


# ── Configuration ────────────────────────────────────────────────────────────

ENV_PATH    = Path(__file__).resolve().parents[2] / '.env'
MK_FILE     = Path("/Volumes/data/NAIF/pds/sln-l-spice-6-v1.0/slnsp_1000/extras/mk/SEL_V02.TM")
LRS_ARCHIVE = Path("/Volumes/data/SELENE/lrs/darts/sln-l-lrs-2-sndr-waveform-high-v1.0/")
DATA_GLOB   = os.path.join("data", "LRS_??_??_*.tbl")
INST_ID     = 1   # LRS instrument_id in porpass_dev
BODY_ID     = 3   # Moon body_id in porpass_dev
METHOD      = "NEAR POINT/ELLIPSOID"

INSERT_QRY  = '''INSERT INTO observations
    (instrument_id, body_id, native_id, start_time, end_time,
     start_lat, start_lon, end_lat, end_lon,
     observation_geometry, science_file_id)
    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, ST_GeomFromText(%s, 0), %s)'''


# ── Logging ───────────────────────────────────────────────────────────────────

def setup_logging(log_file=None):
    """Configure logging to the console and optionally to a file.

    Sets up the root logger with a timestamped format. If a log file path is
    provided, output is written to both the console and the file simultaneously.

    Args:
        log_file (Path or None): If provided, log output is also written to
            this file. The file is created or appended to if it already exists.
            If None, output goes to the console only. Defaults to None.
    """
    fmt     = '%(asctime)s  %(levelname)-8s  %(message)s'
    datefmt = '%Y-%m-%d %H:%M:%S'
    handlers = [logging.StreamHandler()]
    if log_file:
        handlers.append(logging.FileHandler(log_file))
    logging.basicConfig(level=logging.INFO, format=fmt, datefmt=datefmt, handlers=handlers)


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

def process_file(sci_file, cursor, spice, inst_id, body_id):
    """Process a single LRS science file and insert one observation row.

    Reads an LRS waveform science file, computes sub-spacecraft ground track
    geometry using SPICE via GRaSP, constructs a WKT LineString from the
    resulting longitude/latitude pairs, and executes a parameterised INSERT
    into the observations table.

    Args:
        sci_file (Path): Path to the LRS .tbl science file to process.
        cursor (pymysql.cursors.Cursor): An open database cursor on which to
            execute the INSERT statement.
        spice (dict): SPICE parameter dictionary as returned by
            :func:`setup_spice`.
        inst_id (int): instrument_id for LRS in the PORPASS database.
        body_id (int): body_id for the Moon in the PORPASS database.

    Returns:
        bool: True if the INSERT was executed successfully.

    Raises:
        Exception: Any exception raised by GRaSP or PyMySQL is propagated to
            the caller, which logs it and continues to the next file.
    """
    sci = grasp.read(sci_file)
    et  = sci['OBSERVATION_TIME']

    vctrs = grasp.compute_state_vectors(
        et,
        spice['obsrvr_str'],
        spice['target_str'],
        spice['fix_ref_str'],
        ab_corr=spice['ab_corr'],
        intercept_method_str=METHOD,
        utc=spice['utc'],
    )
    geom   = grasp.compute_geometry(vctrs.r_t, vctrs.r_s, vctrs.r_st)
    coords = [(lon, lat) for lon, lat in zip(geom.longitude, geom.latitude)]
    line   = LineString(coords)

    native_id = sci_file.stem  # basename without extension

    cursor.execute(INSERT_QRY, (
        inst_id,
        body_id,
        native_id,
        pd.Timestamp(et[0]),
        pd.Timestamp(et[-1]),
        float(geom.latitude[0]),
        float(geom.longitude[0]),
        float(geom.latitude[-1]),
        float(geom.longitude[-1]),
        line.wkt,
        None,  # science_file_id — deferred until files table is implemented
    ))
    return True


def load_lrs(dry_run=False, limit=None, log_file=None):
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

    # Find date directories
    date_dirs = sorted(LRS_ARCHIVE.glob('200?????'))
    if limit:
        date_dirs = date_dirs[:limit]
    logging.info(f"Processing {len(date_dirs)} date directories...")

    # Import loop
    n_success = 0
    n_failed  = 0
    n_total   = 0

    for date_dir in date_dirs:
        sci_files = sorted(date_dir.glob(DATA_GLOB))
        for sci_file in sci_files:
            n_total += 1
            try:
                process_file(sci_file, cursor, spice, INST_ID, BODY_ID)
                n_success += 1
                logging.info(f"  OK  {sci_file.name}")
            except Exception as e:
                n_failed += 1
                logging.warning(f"  FAIL {sci_file.name}: {e}")
                continue

    # Commit or rollback
    logging.info(f"Results: {n_success} succeeded, {n_failed} failed out of {n_total} total.")
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
    """
    parser = argparse.ArgumentParser(
        description="Import LRS observations into the PORPASS database."
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
    return parser.parse_args()


if __name__ == '__main__':
    args = parse_args()
    load_lrs(dry_run=args.dry_run, limit=args.limit, log_file=args.log_file)