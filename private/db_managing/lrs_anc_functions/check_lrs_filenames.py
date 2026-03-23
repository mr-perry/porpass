#!/usr/bin/env python3
"""Identify LRS science files whose names do not follow the expected convention.

Expected filename pattern:

    LRS_SW_WF_<2-digit latitude><N|S>_<6-digit longitude><E|W>.tbl

Examples of conforming names:
    LRS_SW_WF_80S_239197E.tbl
    LRS_SW_WF_05N_007342W.tbl

Examples of non-conforming names:
    LRS_SW_WF_85N_0881642.tbl   ← 7 longitude digits, missing E/W
    LRS_SW_WF_5S_239197E.tbl    ← 1 latitude digit instead of 2

Usage:
    python check_lrs_filenames.py [--archive PATH] [--output PATH]

Options:
    --archive PATH   Root directory of the LRS archive to scan
                     (default: /Volumes/data/SELENE/lrs/darts/
                      sln-l-lrs-2-sndr-waveform-high-v1.0/)
    --output PATH    Write the list of non-conforming filenames to this file
                     in addition to printing to the console. Optional.
"""

import argparse
import re
import sys
from pathlib import Path

# ── Configuration ─────────────────────────────────────────────────────────────

LRS_ARCHIVE = Path(
    "/Volumes/data/SELENE/lrs/darts/"
    "sln-l-lrs-2-sndr-waveform-high-v1.0/"
)

# Expected: LRS_SW_WF_<2 digits><N|S>_<6 digits><E|W>.tbl
VALID_PATTERN = re.compile(
    r"^LRS_SW_WF_"
    r"(?P<lat>\d{2})(?P<lat_hem>[NS])"
    r"_"
    r"(?P<lon>\d{6})(?P<lon_hem>[EW])"
    r"\.tbl$",
    re.IGNORECASE,
)


# ── Validation ────────────────────────────────────────────────────────────────

def describe_violations(name: str) -> list[str]:
    """Return a list of human-readable violation strings for a filename.

    Checks each component of the filename independently so that multiple
    violations in a single name are all reported.

    Args:
        name: The filename (stem + suffix) to inspect.

    Returns:
        A list of violation descriptions. Empty if the name is fully conforming.
    """
    violations = []

    if not name.upper().startswith("LRS_SW_WF_"):
        violations.append("missing or incorrect 'LRS_SW_WF_' prefix")
        return violations  # can't parse further without the prefix

    if not name.lower().endswith(".tbl"):
        violations.append("wrong file extension (expected .tbl)")

    # Extract the part after LRS_SW_WF_
    body = name[len("LRS_SW_WF_"):]
    if body.lower().endswith(".tbl"):
        body = body[:-4]

    parts = body.split("_")
    if len(parts) != 2:
        violations.append(
            f"expected 2 underscore-separated components after prefix, got {len(parts)}"
        )
        return violations

    lat_part, lon_part = parts

    # Latitude component: exactly 2 digits + N or S
    lat_match = re.fullmatch(r"(\d+)([NS]?)", lat_part, re.IGNORECASE)
    if not lat_match:
        violations.append(f"latitude component '{lat_part}' is not digits followed by N/S")
    else:
        digits, hem = lat_match.groups()
        if len(digits) != 2:
            violations.append(
                f"latitude has {len(digits)} digit(s), expected exactly 2 (got '{lat_part}')"
            )
        if not hem:
            violations.append(f"latitude component '{lat_part}' is missing N/S hemisphere")

    # Longitude component: exactly 6 digits + E or W
    lon_match = re.fullmatch(r"(\d+)([EW]?)", lon_part, re.IGNORECASE)
    if not lon_match:
        violations.append(f"longitude component '{lon_part}' is not digits followed by E/W")
    else:
        digits, hem = lon_match.groups()
        if len(digits) != 6:
            violations.append(
                f"longitude has {len(digits)} digit(s), expected exactly 6 (got '{lon_part}')"
            )
        if not hem:
            violations.append(f"longitude component '{lon_part}' is missing E/W hemisphere")

    return violations


# ── Main ──────────────────────────────────────────────────────────────────────

def check_lrs_filenames(archive: Path, output: Path | None) -> None:
    tbl_files = sorted(archive.rglob("*.tbl"))
    if not tbl_files:
        print(f"No .tbl files found under {archive}", file=sys.stderr)
        sys.exit(1)

    bad = []
    for f in tbl_files:
        violations = describe_violations(f.name)
        if violations:
            bad.append((f, violations))

    # ── Report ────────────────────────────────────────────────────────────────
    total    = len(tbl_files)
    n_bad    = len(bad)
    n_good   = total - n_bad

    summary = (
        f"\nScanned {total} .tbl files: "
        f"{n_good} conforming, {n_bad} non-conforming.\n"
    )

    lines = []
    for f, violations in bad:
        lines.append(f"{f}")
        for v in violations:
            lines.append(f"    • {v}")

    report = "\n".join(lines) + summary if lines else summary

    print(report)

    if output:
        output.parent.mkdir(parents=True, exist_ok=True)
        with open(output, "w", encoding="utf-8") as fh:
            fh.write(report)
        print(f"Report written to {output}")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Identify LRS science files with non-conforming filenames."
    )
    parser.add_argument(
        "--archive",
        type=Path,
        default=LRS_ARCHIVE,
        metavar="PATH",
        help="Root directory of the LRS archive to scan.",
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=None,
        metavar="PATH",
        help="Write the report to this file in addition to printing to the console.",
    )
    return parser.parse_args()


if __name__ == "__main__":
    args = parse_args()
    if not args.archive.exists():
        print(f"Archive not found: {args.archive}", file=sys.stderr)
        sys.exit(1)
    check_lrs_filenames(args.archive, args.output)
