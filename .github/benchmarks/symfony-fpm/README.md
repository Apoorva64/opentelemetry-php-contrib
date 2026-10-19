# Symfony FPM benchmark

Benchmarks the Symfony auto-instrumentation (`src/Instrumentation/Symfony`) in a real Symfony app
served by PHP-FPM + nginx, comparing three variants in the same run:

| Variant  | Description                                                     |
|----------|-----------------------------------------------------------------|
| baseline | Symfony without OpenTelemetry (extension unloaded)              |
| base     | Instrumentation exported from `base_ref` (SDK on, exporters `none`) |
| head     | Instrumentation exported from `head_ref`                        |

The app is a Symfony skeleton with generated attribute routes (10 per controller) running in `prod`
with opcache and a warmed cache. Each variant is measured with [k6](https://k6.io) in interleaved rounds; optional
[Excimer](https://github.com/wikimedia/mediawiki-php-excimer) profiles are rendered as flamegraphs.
The load test script is `bench.js`; `gen_bench.py` generates its route config from `ROUTES`
(`work/bench.json`, a copy is kept in `results/`).

## GitHub Actions

Run the **Symfony FPM benchmark** workflow (`workflow_dispatch`) and pick the refs to compare.
The report is written to the job summary; raw `k6` outputs and flamegraphs are uploaded as the
`symfony-fpm-benchmark` artifact. Absolute numbers vary between runners: compare variants within a run.

## Locally

Requires Docker, git, PHP 8.2+, Composer, Python 3 and Perl (`k6` is optional).
All the benchmark tooling is Python: `run.py` orchestrates everything and calls
`build_apps.py`, `gen_bench.py`, `generate_routes.py`, `merge_collapsed.py` and `report.py`.

```bash
python3 .github/benchmarks/symfony-fpm/run.py main my-branch

# Quicker run, keep the stack up afterwards (ports 18180-18182: baseline, base, head)
DURATION=5s ROUNDS=2 PROFILE=false KEEP_STACK=true python3 .github/benchmarks/symfony-fpm/run.py main HEAD
```

Results are written to `work/results/` (`summary.md`, `k6-*.json`, `flamegraphs/*.svg`).

| Variable           | Default | Description                               |
|--------------------|---------|-------------------------------------------|
| `ROUTES`           | `500`   | Number of generated routes                |
| `BUILD`            | `false` | Build the apps first even without ref arguments |
| `DURATION`         | `15s`   | `k6` duration per round                   |
| `ROUNDS`           | `3`     | Interleaved rounds per variant            |
| `CONNECTIONS`      | `1`     | Concurrent connections                    |
| `PROFILE`          | `true`  | Generate flamegraphs                      |
| `PROFILE_DURATION` | `15s`   | Load duration while profiling             |
| `KEEP_STACK`       | `false` | Leave containers running after the run    |

Flamegraphs: `base.svg` / `head.svg` (all frames), `*-otel.svg` (stacks with OpenTelemetry frames only),
`diff-base-head.svg` (head's shape) and `diff-head-base.svg` (base's shape, shows code removed in head);
red means more time in head, blue less. They are rendered at high resolution (3200px wide, tiny frames
included) so they stay readable when zoomed; tune with `FLAMEGRAPH_WIDTH` and `FLAMEGRAPH_MINWIDTH`.
