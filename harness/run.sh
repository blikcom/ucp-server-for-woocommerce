#!/usr/bin/env bash
# The conformance harness: a throwaway shop, seeded, checked, destroyed.
#
# Everything is pinned and nothing is shared, so two runs give the same
# answer. Teardown happens even on failure, so a red run leaves nothing
# behind to confuse the next one.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
PORT="${CONFORMANCE_PORT:-8099}"
COMPOSE=(docker compose -f "$HERE/docker-compose.yml")
KEEP="${KEEP_STACK:-0}"

teardown() {
    if [ "$KEEP" = "1" ]; then
        echo "==> leaving the stack up (KEEP_STACK=1); tear down with:"
        echo "    docker compose -f $HERE/docker-compose.yml down -v"
        return
    fi
    echo "==> teardown"
    "${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
}
trap teardown EXIT

# A clean checkout has no vendor/, and the plugin cannot autoload its own
# classes without it. Install once here so the harness works from a fresh
# clone and in CI, not only on a machine that happened to run composer.
if [ ! -f "$HERE/../vendor/autoload.php" ]; then
    echo "==> composer install (vendor/ missing)"
    docker run --rm -v "$HERE/..":/app -w /app composer:2.9.8 \
        composer install --no-interaction --no-progress --quiet
fi

echo "==> starting a throwaway WordPress + WooCommerce (port $PORT)"
"${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
"${COMPOSE[@]}" up -d --wait

CONFORMANCE_PORT="$PORT" bash "$HERE/seed.sh"

echo "==> scripted mock-handler checkout"
docker run --rm --network host \
    -e BASE_URL="http://localhost:$PORT" \
    -v "$HERE/checkout.py:/checkout.py:ro" \
    python:3.13.15-slim python /checkout.py

if [ -n "${CONFORMANCE_SUITE_DIR:-}" ]; then
    echo "==> official UCP conformance suite ($CONFORMANCE_SUITE_DIR)"
    ( cd "$CONFORMANCE_SUITE_DIR" && uv run protocol_test.py \
        --server_url="http://localhost:$PORT/wp-json/ucp/v1" \
        --simulation_secret="${UCPWS_SIMULATION_SECRET:-conformance-secret}" \
        ${CONFORMANCE_INPUT:+--conformance_input="$CONFORMANCE_INPUT"} )
else
    echo "==> official suite skipped (CONFORMANCE_SUITE_DIR unset - see docs/conformance.md)"
fi

echo
echo "conformance harness: PASS"
