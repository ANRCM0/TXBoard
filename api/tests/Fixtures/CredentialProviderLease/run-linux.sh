#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/../../.."
export APP_ENV=testing CACHE_STORE=array REDIS_DB=20 REDIS_CACHE_DB=21
case "${1:---sqlite}" in
  --sqlite) export DB_CONNECTION=sqlite DB_DATABASE=:memory:; unset CORE_PROVIDER_LEASE_MYSQL; php artisan test --configuration phpunit-historical.xml --filter=FixtureLeaseCandidateTest ;;
  --mariadb)
    [[ "${DB_DATABASE:-}" == txboard_provider_test && "${DB_USERNAME:-}" == txboard_provider_fixture ]] || exit 2
    : "${DB_PASSWORD:?Dedicated local test password required}"
    export CORE_PROVIDER_LEASE_MYSQL=1; php artisan test --configuration phpunit-historical.xml --filter=FixtureLeaseCandidateTest ;;
  --full) export DB_CONNECTION=sqlite DB_DATABASE=:memory:; unset CORE_PROVIDER_LEASE_MYSQL; php artisan test --configuration phpunit-historical.xml ;;
  *) exit 2 ;;
esac
