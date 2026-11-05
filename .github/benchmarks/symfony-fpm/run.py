#!/usr/bin/env python3
"""Runs the FPM benchmark: baseline (no OpenTelemetry) vs base vs head, optionally with
Excimer flamegraphs. Expects apps built by build_apps.py.

Usage: python3 run.py [base_ref] [head_ref]
   base_ref  git ref for the base variant (default: main, or BASE_REF)
   head_ref  git ref for the head variant (default: HEAD, or HEAD_REF)

Passing refs (or setting BUILD=true) builds the apps first; without them, the existing
`work/apps` are used — like the GitHub Actions workflow, which builds in a separate step.

Env: DURATION (default 15s), ROUNDS (default 3), CONNECTIONS (default 1), ROUTES (default 500),
     PROFILE (default true), PROFILE_DURATION (default 15s), FLAMEGRAPH_DIR, KEEP_STACK (default false),
     FLAMEGRAPH_WIDTH (default 3200), FLAMEGRAPH_MINWIDTH (default 0.01)
"""
import contextlib
import json
import os
import pathlib
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.request

import build_apps
import gen_bench
import merge_collapsed
import report

BENCH_DIR = pathlib.Path(__file__).resolve().parent
WORK_DIR = pathlib.Path(os.environ.get("WORK_DIR") or BENCH_DIR / "work")
RESULTS = WORK_DIR / "results"
DURATION = os.environ.get("DURATION", "15s")
ROUNDS = int(os.environ.get("ROUNDS", "3"))
CONNECTIONS = int(os.environ.get("CONNECTIONS", "1"))
ROUTES = int(os.environ.get("ROUTES", "500"))
PROFILE = os.environ.get("PROFILE", "true").lower() == "true"
PROFILE_DURATION = os.environ.get("PROFILE_DURATION", "15s")
PORT_PREFIX = os.environ.get("BENCH_PORT_PREFIX", "1818")
FLAMEGRAPH_DIR = pathlib.Path(os.environ.get("FLAMEGRAPH_DIR") or WORK_DIR / "FlameGraph")
FLAMEGRAPH_SHA = "41fee1f99f9276008b7cd112fca19dc3ea84ac32"
# Wide canvas + low minimum width => high-resolution SVGs that stay readable when zoomed in
FLAMEGRAPH_WIDTH = os.environ.get("FLAMEGRAPH_WIDTH", "3200")
FLAMEGRAPH_MINWIDTH = os.environ.get("FLAMEGRAPH_MINWIDTH", "0.01")
KEEP_STACK = os.environ.get("KEEP_STACK", "false").lower() == "true"

PORTS = {
    "baseline": f"{PORT_PREFIX}0",
    "base": f"{PORT_PREFIX}1",
    "head": f"{PORT_PREFIX}2",
    "prof-base": f"{PORT_PREFIX}3",
    "prof-head": f"{PORT_PREFIX}4",
}

COMPOSE = ["docker", "compose", "--project-directory", str(BENCH_DIR), "-f", str(BENCH_DIR / "compose.yaml")]

VARIANTS = ("baseline", "base", "head")


def check_tools(tools: tuple[str, ...]) -> None:
    missing = [tool for tool in tools if not shutil.which(tool)]
    if missing:
        sys.exit(f"Missing required tools: {' '.join(missing)}")


def build_apps_if_requested(args: list[str]) -> None:
    """Builds the apps first when refs are given as arguments (or BUILD=true)."""
    if args:
        os.environ["BASE_REF"] = args[0]
        if len(args) > 1:
            os.environ["HEAD_REF"] = args[1]
    if not (args or os.environ.get("BUILD", "false").lower() == "true"):
        return
    check_tools(("git", "php", "composer"))
    build_apps.build()


def run(cmd: list[str], cwd: pathlib.Path | None = None, stdout: int | None = None,
        env: dict[str, str] | None = None, stdin: int | None = None) -> subprocess.CompletedProcess:
    """Runs a command, printing its output (unless redirected); exits on failure."""
    result = subprocess.run([str(arg) for arg in cmd], cwd=cwd, stdout=stdout, env=env, stdin=stdin)
    if result.returncode != 0:
        sys.exit(f"command failed ({result.returncode}): {' '.join(str(arg) for arg in cmd)}")
    return result


def run_k6(port: str, duration: str, vus: int, out: pathlib.Path | None) -> None:
    config_json = (WORK_DIR / "bench.json").read_text()
    env = {
        **os.environ,
        "CONFIG_JSON": config_json,
        "TARGET": f"http://127.0.0.1:{port}",
        "VUS": str(vus),
        "DURATION": duration,
    }
    args = ["run", "--quiet", "--no-usage-report", "--log-output=stderr"]
    if shutil.which("k6"):
        cmd = ["k6", *args, str(BENCH_DIR / "bench.js")]
        via_stdin = False
    else:
        print("k6 not found: using the grafana/k6 Docker image", file=sys.stderr)
        # No bind mounts (may be unavailable): script via stdin, config via env
        cmd = ["docker", "run", "--rm", "-i", "--network", "host"]
        for key in ("CONFIG_JSON", "TARGET", "VUS", "DURATION"):
            cmd += ["-e", f"{key}={env[key]}"]
        cmd += ["grafana/k6", *args, "-"]
        via_stdin = True
    script = open(BENCH_DIR / "bench.js", "rb") if via_stdin else contextlib.nullcontext()
    with script, open(out or os.devnull, "wb") as out_file:
        run(cmd, env=env, stdout=out_file, stdin=script if via_stdin else None)


def http_status(url: str) -> int:
    try:
        with urllib.request.urlopen(url, timeout=5) as response:
            return response.status
    except urllib.error.HTTPError as error:
        return error.code
    except Exception:
        return 0


def wait_ready(ports: list[str]) -> None:
    for port in ports:
        for _ in range(90):
            if http_status(f"http://127.0.0.1:{port}/section0/list") == 200:
                break
            time.sleep(2)
        else:
            print(f"Port {port} did not become ready", file=sys.stderr)
            run([*COMPOSE, "--profile", "prof", "logs", "--tail", "50"], stdout=sys.stderr.fileno())
            sys.exit(1)


def route_check(config: dict) -> None:
    print("::group::Route template check")
    lines = []
    for variant in ("base", "head"):
        for path in config["checkPaths"]:
            try:
                response = urllib.request.urlopen(f"http://127.0.0.1:{PORTS[variant]}{path}", timeout=30)
                header = response.headers.get("X-Route-Path") or ""
            except Exception as error:
                sys.exit(f"route check failed for {variant} {path}: {error}")
            lines.append(f"{variant}\t{path}\t{header}")
            print(lines[-1])
    (RESULTS / "routes.tsv").write_text("\n".join(lines) + "\n")
    print("::endgroup::")


def flamegraph(args: list[str], svg: pathlib.Path, stdin: bytes | None = None) -> None:
    with open(svg, "wb") as svg_file:
        subprocess.run([str(FLAMEGRAPH_DIR / "flamegraph.pl"), *args],
                       input=stdin, stdout=svg_file, check=True)


def flamegraph_args(title: str) -> list[str]:
    return ["--title", title, "--countname", "samples",
            "--width", FLAMEGRAPH_WIDTH, "--minwidth", FLAMEGRAPH_MINWIDTH]


def profile() -> None:
    print("::group::Profiling (Excimer)")
    if not os.access(FLAMEGRAPH_DIR / "flamegraph.pl", os.X_OK):
        run(["git", "clone", "-q", "https://github.com/brendangregg/FlameGraph", str(FLAMEGRAPH_DIR)])
        run(["git", "-C", str(FLAMEGRAPH_DIR), "checkout", "-q", FLAMEGRAPH_SHA])

    prof = WORK_DIR / "prof"
    shutil.rmtree(prof, ignore_errors=True)
    (prof / "base").mkdir(parents=True)
    (prof / "head").mkdir(parents=True)
    for subdir in prof.iterdir():
        subdir.chmod(0o777)
    run([*COMPOSE, "--profile", "prof", "up", "-d", "--build", "prof-base", "prof-head"])
    wait_ready([PORTS["prof-base"], PORTS["prof-head"]])

    for variant in ("base", "head"):
        run_k6(PORTS[f"prof-{variant}"], PROFILE_DURATION, 1, RESULTS / f"k6-prof-{variant}.json")
    run([*COMPOSE, "--profile", "prof", "stop", "prof-base", "prof-head"], stdout=subprocess.DEVNULL)

    (RESULTS / "flamegraphs").mkdir(parents=True, exist_ok=True)
    folded = {}
    for variant in ("base", "head"):
        merged = merge_collapsed.merge(prof / variant).encode()
        folded[variant] = merged
        (prof / f"{variant}.folded").write_bytes(merged)
        otel = [line for line in merged.decode().splitlines()
                if "OpenTelemetry" in line or "open-telemetry" in line]
        (prof / f"{variant}-otel.folded").write_text("\n".join(otel) + "\n" if otel else "")
        flamegraph(flamegraph_args(f"{variant}: all frames") + [str(prof / f"{variant}.folded")],
                   RESULTS / "flamegraphs" / f"{variant}.svg")
        flamegraph(flamegraph_args(f"{variant}: stacks containing OpenTelemetry frames")
                   + [str(prof / f"{variant}-otel.folded")],
                   RESULTS / "flamegraphs" / f"{variant}-otel.svg")

    # Differential flamegraphs (counts normalized). Red = more time in head, blue = less.
    # diff-base-head.svg has head's shape; diff-head-base.svg has base's shape, so code removed in head shows up there.
    def diff(first: str, second: str) -> bytes:
        return subprocess.run([str(FLAMEGRAPH_DIR / "difffolded.pl"), "-n",
                               str(prof / f"{first}.folded"), str(prof / f"{second}.folded")],
                              stdout=subprocess.PIPE, check=True).stdout

    flamegraph(flamegraph_args("Differential base vs head (head shape)"),
               RESULTS / "flamegraphs" / "diff-base-head.svg", stdin=diff("base", "head"))
    flamegraph(flamegraph_args("Differential base vs head (base shape)") + ["--negate"],
               RESULTS / "flamegraphs" / "diff-head-base.svg", stdin=diff("head", "base"))
    for variant in ("base", "head"):
        shutil.copy(prof / f"{variant}.folded", RESULTS / "flamegraphs")
    print("::endgroup::")


def write_report() -> None:
    summary = report.report(RESULTS, ROUNDS, CONNECTIONS, ROUTES)
    sys.stdout.write(summary + "\n")
    (RESULTS / "summary.md").write_text(summary)
    if step_summary := os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(step_summary, "a") as f:
            f.write(summary)


def main(args: list[str]) -> None:
    if "-h" in args or "--help" in args:
        print(__doc__)
        return
    if any(arg.startswith("-") for arg in args):
        sys.exit(f"unknown option: {next(arg for arg in args if arg.startswith('-'))} (see --help)")
    check_tools(("docker", "git"))
    if PROFILE:
        check_tools(("perl",))

    build_apps_if_requested(args)

    # Generate the k6 config: request paths spread across the route collection
    # (first, middle and last controller), plus the paths for the route template check
    config = gen_bench.generate(WORK_DIR / "bench.json", ROUTES)
    RESULTS.mkdir(parents=True, exist_ok=True)
    shutil.copy(WORK_DIR / "bench.json", RESULTS / "bench.json")

    print("::group::Build and start stack")
    run([*COMPOSE, "build"])
    run([*COMPOSE, "up", "-d"])
    wait_ready([PORTS["baseline"], PORTS["base"], PORTS["head"]])
    print("::endgroup::")

    route_check(config)

    print("::group::Warm-up")
    for variant in VARIANTS:
        run_k6(PORTS[variant], "5s", CONNECTIONS, None)
    print("::endgroup::")

    print(f"::group::Benchmark ({ROUNDS} rounds x {DURATION}, {CONNECTIONS} connection(s))")
    for round_number in range(1, ROUNDS + 1):
        for variant in VARIANTS:
            out = RESULTS / f"k6-{variant}-{round_number}.json"
            run_k6(PORTS[variant], DURATION, CONNECTIONS, out)
            rps = json.loads(out.read_text())["rps"]
            print(f"round {round_number} {variant}: {rps} req/s")
    print("::endgroup::")

    if PROFILE:
        profile()

    write_report()

    print(f"\nResults:     {RESULTS / 'summary.md'}")
    if (RESULTS / "flamegraphs").is_dir():
        print(f"Flamegraphs: {RESULTS / 'flamegraphs'}/")


if __name__ == "__main__":
    try:
        main(sys.argv[1:])
    finally:
        if not KEEP_STACK:
            subprocess.run([*COMPOSE, "--profile", "prof", "down", "-v", "--remove-orphans"],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
