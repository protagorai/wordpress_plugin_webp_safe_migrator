#!/usr/bin/env python3
"""Summarise PHP (Clover) and JS (lcov) coverage as line% + branch% per language,
with the gap to a target (default 95%). Runs with only the Python stdlib."""
import argparse
import os
import sys
import xml.etree.ElementTree as ET


def pct(covered, total):
    return (100.0 * covered / total) if total else None


def fmt(p):
    return "  n/a" if p is None else f"{p:5.1f}%"


def parse_clover(path):
    """Return {group_label: {'lines':(cov,tot), 'branches':(cov,tot)}} for PHP."""
    if not path or not os.path.exists(path):
        return None
    tree = ET.parse(path)
    root = tree.getroot()
    groups = {}
    for f in root.iter("file"):
        name = (f.get("name") or "").replace("\\", "/")
        # Group by top-level plugin dir.
        if "/src/" in name or name.endswith("/src") or "/src" in name:
            g = "PHP (src)"
        elif "/includes/" in name or "/includes" in name:
            g = "PHP (includes)"
        else:
            g = "PHP (other)"
        m = f.find("metrics")
        if m is None:
            continue
        lt = int(m.get("statements", 0))
        lc = int(m.get("coveredstatements", 0))
        bt = int(m.get("conditionals", 0))
        bc = int(m.get("coveredconditionals", 0))
        d = groups.setdefault(g, [0, 0, 0, 0])
        d[0] += lc; d[1] += lt; d[2] += bc; d[3] += bt
    out = {}
    for g, (lc, lt, bc, bt) in groups.items():
        out[g] = {"lines": (lc, lt), "branches": (bc, bt)}
    return out


def parse_lcov(path):
    """Return {'JS': {'lines':(cov,tot),'branches':(cov,tot)}} from lcov.info."""
    if not path or not os.path.exists(path):
        return None
    lc = lt = bc = bt = 0
    with open(path, encoding="utf-8", errors="replace") as fh:
        for line in fh:
            line = line.strip()
            if line.startswith("LF:"):
                lt += int(line[3:])
            elif line.startswith("LH:"):
                lc += int(line[3:])
            elif line.startswith("BRF:"):
                bt += int(line[4:])
            elif line.startswith("BRH:"):
                bc += int(line[4:])
    if lt == 0 and bt == 0:
        return None
    return {"JS": {"lines": (lc, lt), "branches": (bc, bt)}}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--clover")
    ap.add_argument("--lcov")
    ap.add_argument("--target", type=float, default=95.0)
    args = ap.parse_args()

    groups = {}
    php = parse_clover(args.clover)
    js = parse_lcov(args.lcov)
    if php:
        groups.update(php)
    if js:
        groups.update(js)

    if not groups:
        print("No coverage data found.")
        print(f"  Clover: {args.clover} (exists={bool(args.clover and os.path.exists(args.clover))})")
        print(f"  lcov:   {args.lcov} (exists={bool(args.lcov and os.path.exists(args.lcov))})")
        print("Run bin/coverage.sh (Docker/Podman required for PHP) to generate it.")
        return 0

    print(f"{'Scope':<18}{'Lines':>10}{'Line %':>9}{'Branches':>12}{'Branch %':>10}")
    print("-" * 59)
    tot = [0, 0, 0, 0]
    for g in sorted(groups):
        lc, lt = groups[g]["lines"]
        bc, bt = groups[g]["branches"]
        tot[0] += lc; tot[1] += lt; tot[2] += bc; tot[3] += bt
        print(f"{g:<18}{f'{lc}/{lt}':>10}{fmt(pct(lc, lt)):>9}"
              f"{f'{bc}/{bt}':>12}{fmt(pct(bc, bt)):>10}")
    print("-" * 59)
    lp, brp = pct(tot[0], tot[1]), pct(tot[2], tot[3])
    print(f"{'TOTAL':<18}{f'{tot[0]}/{tot[1]}':>10}{fmt(lp):>9}"
          f"{f'{tot[2]}/{tot[3]}':>12}{fmt(brp):>10}")
    print()
    for label, p, cov, total in (("Line", lp, tot[0], tot[1]), ("Branch", brp, tot[2], tot[3])):
        if p is None:
            continue
        if p >= args.target:
            print(f"{label} coverage {p:.1f}% — meets the {args.target:.0f}% target.")
        else:
            need = int((args.target / 100.0) * total) - cov
            print(f"{label} coverage {p:.1f}% — {args.target - p:.1f} pts below {args.target:.0f}% "
                  f"(~{max(need,0)} more {label.lower()} units to cover).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
