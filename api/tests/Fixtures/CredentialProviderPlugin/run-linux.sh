#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/../../.."

# Developer verification only. Never initializes a production database or enables an HTTP route.
mode="${1:---sqlite}"
export APP_ENV=testing CACHE_STORE=array REDIS_DB=20 REDIS_CACHE_DB=21
case "$mode" in
  --sqlite)
    export DB_CONNECTION=sqlite DB_DATABASE=:memory:
    unset CORE_PROVIDER_PLUGIN_MYSQL
    php artisan test --filter=FixturePluginBoundaryTest
    ;;
  --mariadb)
    [[ "${DB_DATABASE:-}" == txboard_provider_test ]] || { echo 'Dedicated txboard_provider_test required' >&2; exit 2; }
    [[ "${DB_USERNAME:-}" == txboard_provider_fixture ]] || { echo 'Dedicated Provider fixture user required' >&2; exit 2; }
    : "${DB_PASSWORD:?Pass the dedicated local fixture password through the environment}"
    export CORE_PROVIDER_PLUGIN_MYSQL=1
    php artisan test --filter=FixturePluginBoundaryTest
    ;;
  --full)
    export DB_CONNECTION=sqlite DB_DATABASE=:memory:
    unset CORE_PROVIDER_PLUGIN_MYSQL
    php artisan test
    ;;
  *) echo 'Use --sqlite, --mariadb or --full' >&2; exit 2 ;;
esac
