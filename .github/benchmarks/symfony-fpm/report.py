"""Builds a markdown report from k6 outputs.

Usage: python3 report.py <results-dir> <rounds> <connections> <routes>
"""
import json
import pathlib
import statistics
import sys

VARIANTS = ("baseline", "base", "head")


def parse(path: pathlib.Path) -> dict:
    # JSON summary emitted by handleSummary() in bench.js (durations in ms)
    data = json.loads(path.read_text())
    return {
        "rps": data["rps"],
        "p50": data["p50"],
        "p99": data["p99"],
        "errors": data["errors"],
    }


def report(results: pathlib.Path, rounds: int, connections: int, routes: int) -> str:
    refs = {}
    refs_file = results / "refs.env"
    if refs_file.exists():
        refs = dict(line.split("=", 1) for line in refs_file.read_text().split())

    stats = {}
    for variant in VARIANTS:
        runs = [parse(p) for p in sorted(results.glob(f"k6-{variant}-[0-9]*.json"))]
        stats[variant] = {
            "rps": statistics.median(r["rps"] for r in runs),
            "p50": statistics.median(r["p50"] for r in runs),
            "p99": statistics.median(r["p99"] for r in runs),
            "errors": sum(r["errors"] for r in runs),
            "spread": (max(r["rps"] for r in runs) - min(r["rps"] for r in runs)) / statistics.median(r["rps"] for r in runs) * 100,
        }
        # Time per request per connection, in ms
        stats[variant]["ms"] = 1000.0 * connections / stats[variant]["rps"]

    baseline_ms = stats["baseline"]["ms"]
    labels = {
        "baseline": "baseline (no OpenTelemetry)",
        "base": f"base `{refs.get('BASE_REF', 'base')}` ({refs.get('BASE_SHA', '')[:8]})",
        "head": f"head `{refs.get('HEAD_REF', 'head')}` ({refs.get('HEAD_SHA', '')[:8]})",
    }

    out = ["## Symfony FPM benchmark", ""]
    out.append(f"{routes} routes · PHP-FPM (prod, opcache) · k6 · {rounds} rounds · {connections} connection(s) · medians")
    out += ["", "| Variant | req/s | ms/request | p50 | p99 | overhead vs baseline | spread | non-2xx |", "|---|---:|---:|---:|---:|---:|---:|---:|"]
    for variant in VARIANTS:
        s = stats[variant]
        overhead = "–" if variant == "baseline" else f"+{s['ms'] - baseline_ms:.2f} ms"
        out.append(f"| {labels[variant]} | {s['rps']:.1f} | {s['ms']:.2f} | {s['p50']:.2f} ms | {s['p99']:.2f} ms | {overhead} | ±{s['spread'] / 2:.1f}% | {s['errors']} |")

    base, head = stats["base"], stats["head"]
    delta_ms = head["ms"] - base["ms"]
    delta_pct = (head["rps"] / base["rps"] - 1) * 100
    base_overhead = base["ms"] - baseline_ms
    head_overhead = head["ms"] - baseline_ms
    out += ["", f"**Head vs base:** {delta_pct:+.1f}% throughput, {delta_ms:+.2f} ms/request"]
    if base_overhead > 0:
        out.append(f"(instrumentation overhead {base_overhead:.2f} → {head_overhead:.2f} ms/request, {(head_overhead / base_overhead - 1) * 100:+.0f}%)")

    routes_file = results / "routes.tsv"
    if routes_file.exists():
        rows = [line.split("\t") for line in routes_file.read_text().splitlines() if line]
        by_path = {}
        for variant, path, header in rows:
            by_path.setdefault(path, {})[variant] = header or "–"
        out += ["", "<details><summary>Resolved route templates (<code>_route_path</code>)</summary>", "", "| Request | base | head |", "|---|---|---|"]
        for path, values in by_path.items():
            out.append(f"| `{path}` | `{values.get('base', '–')}` | `{values.get('head', '–')}` |")
        out += ["", "</details>"]

    if (results / "flamegraphs").is_dir():
        out += ["", "Flamegraphs are in the `symfony-fpm-benchmark` artifact: `base.svg`, `head.svg`, `*-otel.svg` (OpenTelemetry stacks only), "
                "`diff-base-head.svg` / `diff-head-base.svg` (red = slower in head, blue = faster)."]

    return "\n".join(out)


def main(argv: list[str]) -> None:
    if len(argv) != 4:
        sys.exit("usage: python3 report.py <results-dir> <rounds> <connections> <routes>")
    print(report(pathlib.Path(argv[0]), int(argv[1]), int(argv[2]), int(argv[3])))


if __name__ == "__main__":
    main(sys.argv[1:])
