#!/usr/bin/env python3
"""Wipe observations from the PORPASS database for one or more instruments.

Truncates the child observation table(s) for the specified instrument(s),
then removes the corresponding rows from the parent observations table.
At least one instrument flag must be supplied.

Usage:
    python wipe_observations.py --sharad
    python wipe_observations.py --marsis
    python wipe_observations.py --lrs
    python wipe_observations.py --sharad --marsis --lrs

Options:
    --sharad    Wipe SHARAD observations (instrument_id=2)
    --marsis    Wipe MARSIS observations (instrument_id=3)
    --lrs       Wipe LRS observations (instrument_id=1)
"""

import argparse
import logging
import os
import sys
from pathlib import Path

import pymysql as sql
from dotenv import load_dotenv


ENV_PATH = Path(__file__).resolve().parents[2] / '.env'

INSTRUMENTS = {
    'lrs':    {'instrument_id': 1, 'child_table': 'lrs_observations'},
    'sharad': {'instrument_id': 2, 'child_table': 'sharad_observations'},
    'marsis': {'instrument_id': 3, 'child_table': 'marsis_observations'},
}


def get_connection(env_path):
    load_dotenv(env_path)
    return sql.connect(
        host=os.getenv('DB_HOST'),
        database=os.getenv('DB_DATABASE'),
        user=os.getenv('DB_USERNAME'),
        password=os.getenv('DB_PASSWORD'),
    )


def parse_args():
    parser = argparse.ArgumentParser(
        description="Wipe observations from the PORPASS database for one or more instruments."
    )
    parser.add_argument('--sharad', action='store_true', help='Wipe SHARAD observations')
    parser.add_argument('--marsis', action='store_true', help='Wipe MARSIS observations')
    parser.add_argument('--lrs',    action='store_true', help='Wipe LRS observations')
    return parser.parse_args()


def main():
    logging.basicConfig(
        format='%(asctime)s  %(levelname)-8s  %(message)s',
        datefmt='%Y-%m-%d %H:%M:%S',
        level=logging.INFO,
        stream=sys.stdout,
    )

    args = parse_args()

    selected = [k for k in INSTRUMENTS if getattr(args, k)]
    if not selected:
        logging.error(
            "No instrument specified. Use --sharad, --marsis, and/or --lrs."
        )
        sys.exit(1)

    if not ENV_PATH.exists():
        logging.error(f".env file not found: {ENV_PATH}")
        sys.exit(1)

    cnx    = get_connection(ENV_PATH)
    cursor = cnx.cursor()

    for key in selected:
        inst        = INSTRUMENTS[key]
        inst_id     = inst['instrument_id']
        child_table = inst['child_table']

        logging.info(f"Wiping {key.upper()} observations...")

        # Delete processing_jobs rows first (foreign key on observation_id)
        cursor.execute(
            '''DELETE pj FROM processing_jobs pj
               JOIN observations o ON o.observation_id = pj.observation_id
               WHERE o.instrument_id = %s''',
            (inst_id,)
        )
        pj_count = cursor.rowcount
        logging.info(f"  Deleted {pj_count} rows from processing_jobs")

        # Delete child rows
        cursor.execute(
            f'''DELETE c FROM `{child_table}` c
                JOIN observations o ON o.observation_id = c.observation_id
                WHERE o.instrument_id = %s''',
            (inst_id,)
        )
        child_count = cursor.rowcount
        logging.info(f"  Deleted {child_count} rows from {child_table}")

        # Delete parent rows
        cursor.execute(
            'DELETE FROM observations WHERE instrument_id = %s',
            (inst_id,)
        )
        parent_count = cursor.rowcount
        logging.info(f"  Deleted {parent_count} rows from observations")

    cnx.commit()

    # If all three instruments were wiped the observations table is empty —
    # reset the auto-increment counter so observation_id starts from 1 again.
    if set(selected) == set(INSTRUMENTS.keys()):
        cursor.execute('ALTER TABLE observations AUTO_INCREMENT = 1')
        for inst in INSTRUMENTS.values():
            cursor.execute(f"ALTER TABLE `{inst['child_table']}` AUTO_INCREMENT = 1")
        cnx.commit()
        logging.info("Auto-increment counters reset.")

    logging.info("Done.")
    cursor.close()
    cnx.close()


if __name__ == '__main__':
    main()
