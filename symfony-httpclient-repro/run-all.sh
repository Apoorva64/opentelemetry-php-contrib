#!/usr/bin/env bash
# Runs every reproduction script and writes the output to results/.
#
#   ./run-all.sh            # starts the test server on 127.0.0.1:8765 for the duration of the run
#
# Requirements: PHP >= 8.1 with ext-opentelemetry and ext-curl, and `composer install`
# done in src/Instrumentation/Symfony.
set -euo pipefail

cd "$(dirname "$0")"
HOST_PORT="${REPRO_HOST_PORT:-127.0.0.1:8765}"
export REPRO_BASE_URL="http://${HOST_PORT}"

PHP_CLI_SERVER_WORKERS=8 php -S "$HOST_PORT" server.php >/dev/null 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT

for _ in $(seq 1 50); do
    curl -fs "${REPRO_BASE_URL}/fast" >/dev/null 2>&1 && break
    sleep 0.1
done

mkdir -p results

run() {
    local impl="$1" script="$2"
    local out="results/${script%.php}.${impl}.txt"
    echo ">> IMPL=${impl} php ${script} > ${out}"
    IMPL="$impl" php "$script" >"$out" 2>&1
}

# Raw Symfony behaviour, no instrumentation.
for script in 01-lazy-execution.php 02-on-progress-and-errors.php 03-timestamps.php 04-destruct-hook.php; do
    run none "$script"
done

# Instrumentation on `main` vs the checked-out branch.
for script in 05-scenarios.php 06-redirect-retry.php 07-span-timing.php; do
    run main "$script"
    run branch "$script"
done

echo "Done. Outputs are in $(pwd)/results"
