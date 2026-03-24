#!/usr/bin/env python3
"""
patch_mk_paths.py

Recursively replaces PATH_VALUES = ( './data' ) with
PATH_VALUES = ( '../../data' ) in SPICE meta-kernel (.tm) files,
skipping any files under an older_versions/ directory.

Usage:
    python patch_mk_paths.py <directory> [--dry-run] [--log-file PATH]

Arguments:
    directory        Root directory to search (e.g. the 'extras/mk' folder)
    --dry-run        Preview changes without writing any files
    --log-file PATH  Write log output to this file in addition to the console.
                     The file receives DEBUG-level output (more verbose than the
                     console, which shows INFO and above).
"""

import argparse
import logging
import sys
from pathlib import Path

OLD = "PATH_VALUES     = ( './data' )"
NEW = "PATH_VALUES     = ( '/Volumes/data/NAIF/pds/mro-m-spice-6-v1.0/mrosp_1000/data')"

# Meta-kernel files can carry several extensions
MK_SUFFIXES = {".tm", ".mk", ".tf"}


# ── Logging ───────────────────────────────────────────────────────────────────

class _TeeStream:
    """Write to both a stream and a logging.Logger at a given level.

    Used to redirect sys.stderr into the log so that any output written
    directly to stderr is captured.
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

    def fileno(self):
        return self._stream.fileno()

    def isatty(self):
        return self._stream.isatty()


def setup_logging(log_file=None):
    """Configure logging to the console and optionally to a file.

    Attaches handlers directly to the root logger rather than using
    ``basicConfig``, so the configuration takes effect even if an imported
    library has already attached its own handler.

    Also redirects ``sys.stderr`` through a tee so that any output written
    directly to stderr is captured in the log at WARNING level.

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

    sys.stderr = _TeeStream(sys.__stderr__, root, level=logging.WARNING)


# ── Patch ─────────────────────────────────────────────────────────────────────

def patch_file(path: Path, dry_run: bool) -> bool:
    """Return True if the file was (or would be) modified."""
    try:
        text = path.read_text(encoding='utf-8', errors='replace')
    except OSError as e:
        logging.warning("Could not read %s: %s", path, e)
        return False

    if OLD not in text:
        logging.debug("No match:  %s", path)
        return False

    if dry_run:
        logging.info("  [dry-run] would patch: %s", path)
    else:
        updated = text.replace(OLD, NEW)
        try:
            path.write_text(updated, encoding='utf-8')
            logging.info("  patched:  %s", path)
        except OSError as e:
            logging.warning("Could not write %s: %s", path, e)
            return False

    return True


# ── Main ──────────────────────────────────────────────────────────────────────

def run(directory: Path, dry_run: bool, log_file=None):
    """Scan *directory* recursively and patch all matching meta-kernel files.

    Args:
        directory (Path): Root directory to search.
        dry_run (bool): If True, report what would change without writing files.
        log_file (Path or None): Optional path to a log file.
    """
    setup_logging(log_file)

    root = directory.resolve()
    if not root.is_dir():
        logging.error("'%s' is not a directory.", root)
        sys.exit(1)

    logging.info("Searching in: %s", root)
    logging.info("Mode:         %s", 'DRY RUN' if dry_run else 'LIVE')

    candidates = [
        p for p in root.rglob("*")
        if p.suffix.lower() in MK_SUFFIXES
        and "older_versions" not in p.parts
    ]
    logging.info("Found %d meta-kernel file(s) to inspect.", len(candidates))

    n_patched = 0
    n_skipped = 0

    for path in sorted(candidates):
        result = patch_file(path, dry_run)
        if result:
            n_patched += 1
        else:
            n_skipped += 1

    logging.info(
        "Done. %d/%d file(s) %s modified.",
        n_patched,
        len(candidates),
        'would be' if dry_run else 'were',
    )


def parse_args():
    parser = argparse.ArgumentParser(
        description="Patch PATH_VALUES in SPICE meta-kernel files."
    )
    parser.add_argument(
        'directory',
        type=Path,
        help='Root directory to search recursively',
    )
    parser.add_argument(
        '--dry-run',
        action='store_true',
        help='Show what would change without writing files',
    )
    parser.add_argument(
        '--log-file',
        type=Path,
        default=None,
        metavar='PATH',
        help='Path to a log file. If omitted, output goes to the console only.',
    )
    return parser.parse_args()


if __name__ == '__main__':
    args = parse_args()
    run(directory=args.directory, dry_run=args.dry_run, log_file=args.log_file)
