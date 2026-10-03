#!/usr/bin/env bash
# End-to-end test of the extension inside a real Paymenter instance.
#
#   ./e2e/run.sh          start the environment, set it up and run all tests (keeps it running)
#   ./e2e/run.sh setup    start + set up only (for manual testing)
#   ./e2e/run.sh test     run the tests against an already running environment
#   ./e2e/run.sh down     remove containers and volumes
#
# Environment:
#   BACKEND_PATH     path to the domain-reseller-api repository (default: ../../domain-reseller-api)
#   PAYMENTER_PORT   host port for Paymenter (default 18080), API_PORT for the API (default 13000)
#   PAYMENTER_IMAGE  Paymenter image (default ghcr.io/paymenter/paymenter:latest)
set -euo pipefail

cd "$(dirname "$0")"
export PAYMENTER_PORT="${PAYMENTER_PORT:-18080}"
export API_PORT="${API_PORT:-13000}"
if [ -z "${BACKEND_PATH:-}" ]; then
  for candidate in ../../domain-reseller-api ../../../domain-reseller-api "$HOME/Projects/domain-reseller-api"; do
    if [ -f "$candidate/packages/backend/package.json" ]; then BACKEND_PATH="$(cd "$candidate" && pwd)"; break; fi
  done
fi
if [ "${1:-all}" != "down" ] && [ "${1:-all}" != "live" ] && [ ! -f "${BACKEND_PATH:-}/packages/backend/package.json" ]; then
  echo "Domain Reseller API backend not found. Set BACKEND_PATH=/path/to/domain-reseller-api" >&2
  exit 1
fi
export BACKEND_PATH
COMPOSE=(docker compose -f docker-compose.yml)
APP_URL="http://127.0.0.1:${PAYMENTER_PORT}"
failed=0

php() { "${COMPOSE[@]}" exec -T paymenter php "$@"; }

up() {
  echo "Starting environment (first start takes a few minutes)…"
  "${COMPOSE[@]}" up -d --quiet-pull db api paymenter
  echo -n "Waiting for Paymenter"
  for _ in $(seq 1 120); do
    if curl -fs -o /dev/null "${APP_URL}/login"; then echo " ready"; break; fi
    echo -n "."; sleep 3
  done
  curl -fs -o /dev/null "${APP_URL}/login" || { echo; echo "Paymenter did not come up:"; "${COMPOSE[@]}" logs --tail 50 paymenter api; exit 1; }
  reinstall
  php /e2e/scripts/setup.php "$APP_URL"
}

# Re-install the extension so code changes are picked up without a restart.
reinstall() {
  "${COMPOSE[@]}" exec -T paymenter sh -c 'rm -rf /app/extensions/Servers/DomainResellerApi && cp -r /e2e/extension /app/extensions/Servers/DomainResellerApi && chown -R nginx:nginx /app/extensions/Servers/DomainResellerApi && php /app/artisan view:clear >/dev/null && php /app/artisan cache:clear >/dev/null'
}

browser() {
  mkdir -p artifacts
  "${COMPOSE[@]}" run --rm -T -e TEST="$1" playwright || failed=1
}

tests() {
  php /e2e/scripts/install-zip.php || failed=1
  php /e2e/scripts/lifecycle.php || failed=1
  php /e2e/scripts/transfer.php || failed=1
  browser client-area
  browser checkout
  php /e2e/scripts/pay-latest-invoice.php || failed=1
  browser admin
  browser search
}

summary() {
  cat <<INFO

Paymenter:  ${APP_URL}
  customer  kunde@e2e.test / e2e-password
  admin     admin@e2e.test / e2e-password   (${APP_URL}/admin)
API:        http://127.0.0.1:${API_PORT}/swagger  (sandbox reseller: reseller@e2e.test)
Screenshots: e2e/artifacts/
Stop with:  ./e2e/run.sh down
INFO
}

case "${1:-all}" in
  down) "${COMPOSE[@]}" --profile tests down -v; rm -rf artifacts ;;
  live)
    docker run --rm -e LIVE_API_KEY -e LIVE_API_URL -e LIVE_SANDBOX -v "$(cd .. && pwd)":/repo:ro php:8.3-cli php /repo/e2e/live-check.php || failed=1 ;;
  setup) up; summary ;;
  test) reinstall; tests ;;
  all) up; tests; summary ;;
  *) echo "usage: $0 [all|setup|test|down|live]"; exit 2 ;;
esac

if [ "$failed" -ne 0 ]; then echo -e "\n\033[31mSome tests failed.\033[0m"; exit 1; fi
