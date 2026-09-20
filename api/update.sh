#!/bin/bash
set -euo pipefail

script_dir="$(cd "$(dirname "$0")" && pwd)"
repo_root="$(git -C "$script_dir" rev-parse --show-toplevel 2>/dev/null || true)"

if [ -z "$repo_root" ]; then
  echo "Please deploy from a Git checkout."
  exit 1
fi

if ! command -v git >/dev/null 2>&1; then
  echo "Git is not installed! Please install git and try again."
  exit 1
fi

add_safe_directory() {
  local dir="$1"
  git config --global --get-all safe.directory | grep -Fx "$dir" >/dev/null || \
    git config --global --add safe.directory "$dir"
}

add_safe_directory "$repo_root"

git -C "$repo_root" fetch origin main
git -C "$repo_root" reset --hard origin/main

cd "$script_dir"
rm -f composer.phar
wget https://github.com/composer/composer/releases/latest/download/composer.phar -O composer.phar
php composer.phar install --no-dev --optimize-autoloader -vvv
php artisan xboard:update

if [ -f "/etc/init.d/bt" ] || [ -f "/.dockerenv" ]; then
  chown -R www:www "$script_dir"
fi

if [ -d ".docker/.data" ]; then
  chmod -R 777 .docker/.data
fi
