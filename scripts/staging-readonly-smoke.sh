#!/usr/bin/env bash
# Read-only HTTP smoke against a disposable or staging TXBoard deployment.
# No bearer tokens or production credentials are accepted.
set -euo pipefail

base="${1:?Usage: bash scripts/staging-readonly-smoke.sh https://staging.example.com}"
base="${base%/}"
if [[ "$base" != https://* && "$base" != http://127.0.0.1:* && "$base" != http://localhost:* ]]; then
  echo "Require HTTPS for remote staging, HTTP allowed only on local loopback" >&2
  exit 2
fi
if [[ "$base" == *'@'* || "$base" == *'?'* || "$base" == *'#'* ]]; then
  echo "Base URL must not contain embedded credentials or query parameters" >&2
  exit 2
fi
command -v curl >/dev/null
command -v python3 >/dev/null

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
check() {
  local expected="$1" path="$2" name="$3"
  local status
  status="$(curl --silent --show-error --max-time 15 --max-redirs 0 \
    --output "$work/response" --write-out '%{http_code}' \
    --header 'Accept: application/json' "$base$path")"
  if [[ "$status" != "$expected" ]]; then
    echo "FAIL $name: HTTP $status (expected $expected)" >&2
    exit 1
  fi
  if [[ "$expected" == "200" ]]; then
    python3 - "$work/response" "$name" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as file:
    value = json.load(file)
name = sys.argv[2]
if name == "TXAPI liveness":
    assert value.get("data", {}).get("status") == "ok"
    assert isinstance(value.get("request_id"), str)
elif name == "public site config":
    assert isinstance(value.get("data"), dict)
    assert value["data"].get("is_captcha") in (0, 1, False, True)
    assert isinstance(value.get("request_id"), str)
elif name == "legacy liveness":
    assert value.get("status") == "ok"
PY
  fi
  echo "PASS $name (HTTP $status)"
}

check 200 /api/health "legacy liveness"
check 200 /txapi/health "TXAPI liveness"
check 200 /txapi/public/site-config "public site config"
# An invalid secure path must not expose management configuration.
check 404 "/txapi/admin/__invalid_release_smoke_$(date +%s)__/settings" "admin path guard"
# The V2 management router was retired; no old endpoint may respond successfully.
status="$(curl --silent --show-error --max-time 15 --max-redirs 0 \
    --output /dev/null --write-out '%{http_code}' \
    "$base/api/v2/retired_release_smoke/system/getSystemStatus")"
if [[ "$status" -lt 400 ]]; then
  echo "FAIL: retired V2 administrator route responded with HTTP $status" >&2
  exit 1
fi
echo "PASS retired V2 administrator route (HTTP $status)"
echo "Read-only staging HTTP smoke passed; authentication, payments and Node/Agent still require manual E2E."
