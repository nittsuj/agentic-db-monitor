#!/usr/bin/env python3
"""Convert the DBPA benchmark into files the Laravel app can read.

DBPA: Huang et al., "DBPA: A Benchmark for Transactional Database Performance
Anomalies", SIGMOD 2023 (MIT licence). https://github.com/hjhhsy120/DBPA_dataset

Outputs (relative to --out, default database/dbpa):
  csv/<engine>/<config>/<scenario>.csv   normalised samples, read by `php artisan dbpa:import`
  case_index.json                        deviation features per case (reserved for case lookup)

Security: the pickles are opened with a restricted unpickler that only allows
datetime.datetime, so a tampered file cannot run code.

Usage:
  python3 database/dbpa/convert_dbpa.py --dataset DBPA_dataset
"""

from __future__ import annotations

import argparse
import csv
import datetime
import json
import math
import pickle
import random
from pathlib import Path

ENGINES = {
    "pgsql": {"header": "metrics_pg.csv", "dir": lambda config: Path(config)},
    "mysql": {"header": "metrics_mysql.csv", "dir": lambda config: Path(f"mysql_{config}")},
}
CONFIGS = ["32_128", "32_256", "64_128", "64_256"]

# File-name tokens to scenario ids (ids are the --scenario values accepted by dbpa:import).
SCENARIO_TOKENS = {
    "fault1": "concurrent_inserts",
    "fault2": "missing_indexes",
    "fault3": "heavy_workload",
    "fault4": "vacuum",
    "fault5": "concurrent_commits",
    "lockwait": "lock_waits",
    "multiindex": "too_many_indexes",
    "setknob": "small_shared_buffer",
    "stress": "io_saturation",
    "IO": "io_saturation",
    "normal": "normal",
}

METRIC_KEYS = [
    "connection_pool_used",
    "active_connections",
    "locks",
    "long_running_queries",
    "lock_waiting_sessions",
    "cpu_user_pct",
    "load_1m",
    "commits",
    "rollback_ratio",
    "buffer_hit_ratio",
]

CASES_PER_SCENARIO_IN_INDEX = 60
SAMPLE_INTERVAL_SECONDS = 5
MAX_COMMITS_PER_SAMPLE = 1_000_000


class DatetimeOnlyUnpickler(pickle.Unpickler):
    def find_class(self, module: str, name: str):
        if (module, name) == ("datetime", "datetime"):
            return datetime.datetime
        raise pickle.UnpicklingError(f"Blocked class {module}.{name}")


def load_pickle(path: Path):
    with path.open("rb") as handle:
        return DatetimeOnlyUnpickler(handle).load()


def column_index(header_file: Path) -> dict[str, int]:
    with header_file.open(newline="") as handle:
        rows = list(csv.reader(handle))
    index: dict[str, int] = {}
    for position, name in enumerate(rows[1]):
        index.setdefault(name, position)
    return index


def extract(row: list, columns: dict[str, int], engine: str) -> dict[str, float | None]:
    """Map one raw DBPA row onto the app's metric keys (percentages as 0-100)."""

    def value(name: str) -> float | None:
        position = columns.get(name)
        if position is None or position >= len(row):
            return None
        raw = row[position]
        return float(raw) if isinstance(raw, (int, float)) else None

    commits = value("comm")
    rollbacks = value("roll")
    if commits is not None and commits > MAX_COMMITS_PER_SAMPLE:
        # Some rows carry the cumulative counter instead of a per-interval delta.
        commits, rollbacks = None, None
    pool = value("%Con")
    hit = value("ratio")
    percent_scale = 100.0 if engine == "pgsql" else 1.0
    long_q, long_x = value("LongQ"), value("LongX")

    return {
        "connection_pool_used": pool * percent_scale if pool is not None else None,
        "active_connections": value("Act"),
        "locks": value("Locks"),
        "long_running_queries": (long_q or 0.0) + (long_x or 0.0) if engine == "pgsql" else None,
        "lock_waiting_sessions": value("LWait") if engine == "pgsql" else None,
        "cpu_user_pct": value("usr"),
        "load_1m": value("1m"),
        "commits": commits,
        "rollback_ratio": (rollbacks / (commits + rollbacks) * 100.0)
        if commits is not None and rollbacks is not None and (commits + rollbacks) > 0
        else (0.0 if commits is not None and rollbacks is not None else None),
        "buffer_hit_ratio": hit * percent_scale if hit is not None else None,
    }


def anomaly_rows(case: list, length: int, scenario: str) -> set[int]:
    if scenario == "normal":
        return set()
    window = case[1] if len(case) == 3 and isinstance(case[1], list) else None
    if not window:
        return set(range(length))
    start = int(window[0])
    end = int(window[1]) if len(window) > 1 else length - 1
    return set(range(max(0, start), min(length - 1, end) + 1))


def scenario_name(path: Path, engine: str) -> str:
    stem = path.name.replace("_data.pickle", "")
    if engine == "mysql":
        return stem
    return "+".join(SCENARIO_TOKENS.get(token, token) for token in stem.split("+"))


def deviation(window_mean: float, baseline_mean: float) -> float:
    """Deviation formula used for the case_index features."""
    window, base = abs(window_mean), abs(baseline_mean)
    epsilon = max(0.05 * max(window, base), 1.0)
    return round(math.log2((window + epsilon) / (base + epsilon)), 4)


def means(rows: list[dict[str, float | None]]) -> dict[str, float]:
    result: dict[str, float] = {}
    for key in METRIC_KEYS:
        values = [row[key] for row in rows if row.get(key) is not None]
        if values:
            result[key] = sum(values) / len(values)
    return result


def features(window: dict[str, float], baseline: dict[str, float]) -> dict[str, float]:
    return {key: deviation(window[key], baseline[key]) for key in window if key in baseline}


def norm(vector: dict[str, float]) -> float:
    return math.sqrt(sum(value * value for value in vector.values()))


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--dataset", default="DBPA_dataset", type=Path)
    parser.add_argument("--out", default=Path(__file__).resolve().parent, type=Path)
    parser.add_argument("--configs", nargs="*", default=CONFIGS)
    parser.add_argument("--seed", default=7, type=int)
    args = parser.parse_args()

    rng = random.Random(args.seed)
    index = {
        "version": 1,
        "generated_at": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
        "source": "DBPA (Huang et al., SIGMOD 2023), MIT licence",
        "feature": "log2((|window| + eps) / (|baseline| + eps)), eps = max(0.05 * max(|window|, |baseline|), 1.0)",
        "baseline": "mean of all Normal rows for the same engine and hardware config",
        "metric_keys": METRIC_KEYS,
        "engines": {},
    }

    for engine, spec in ENGINES.items():
        columns = column_index(args.dataset / spec["header"])
        engine_cases: list[dict] = []
        normal_norms: list[float] = []

        for config in args.configs:
            source_dir = args.dataset / spec["dir"](config)
            pickles = sorted(source_dir.rglob("*_data.pickle"))
            if not pickles:
                print(f"skip {engine} {config}: no pickles in {source_dir}")
                continue

            converted: dict[str, list[tuple[list[dict], set[int]]]] = {}
            for path in pickles:
                scenario = scenario_name(path, engine)
                cases = []
                for case in load_pickle(path):
                    rows = [extract(row, columns, engine) for row in case[0]]
                    cases.append((rows, anomaly_rows(case, len(rows), scenario)))
                converted[scenario] = cases

                out_dir = args.out / "csv" / engine / config
                out_dir.mkdir(parents=True, exist_ok=True)
                with (out_dir / f"{scenario}.csv").open("w", newline="") as handle:
                    writer = csv.writer(handle)
                    writer.writerow(["case", "row", "anomaly", *METRIC_KEYS])
                    for case_number, (rows, anomalous) in enumerate(cases):
                        for row_number, row in enumerate(rows):
                            writer.writerow([
                                case_number,
                                row_number,
                                1 if row_number in anomalous else 0,
                                *("" if row[key] is None else round(row[key], 4) for key in METRIC_KEYS),
                            ])

            normal_rows = [row for rows, _ in converted.get("normal", []) for row in rows]
            if not normal_rows:
                print(f"skip index for {engine} {config}: no normal data")
                continue
            baseline = means(normal_rows)

            for rows, _ in converted["normal"]:
                normal_norms.append(norm(features(means(rows), baseline)))

            for scenario, cases in converted.items():
                if scenario == "normal":
                    continue
                sample = cases if len(cases) <= CASES_PER_SCENARIO_IN_INDEX else rng.sample(cases, CASES_PER_SCENARIO_IN_INDEX)
                for rows, anomalous in sample:
                    window_rows = [rows[i] for i in sorted(anomalous)] or rows
                    engine_cases.append({
                        "scenario": scenario,
                        "config": config,
                        "features": features(means(window_rows), baseline),
                    })

            print(f"{engine} {config}: {len(converted)} scenarios, {sum(len(c) for c in converted.values())} cases")

        normal_norms.sort()
        # Median deviation magnitude of Normal cases. Normal and anomalous magnitudes
        # overlap (especially on MySQL), so this is only a weak hint for "looks normal".
        threshold = normal_norms[len(normal_norms) // 2] if normal_norms else 0.5
        index["engines"][engine] = {
            "normal_norm_threshold": round(max(threshold, 0.3), 4),
            "cases": engine_cases,
        }
        print(f"{engine}: {len(engine_cases)} indexed cases, normal threshold {threshold:.3f}")

    target = args.out / "case_index.json"
    target.write_text(json.dumps(index, separators=(",", ":")))
    print(f"wrote {target} ({target.stat().st_size / 1024:.0f} KB)")


if __name__ == "__main__":
    main()
