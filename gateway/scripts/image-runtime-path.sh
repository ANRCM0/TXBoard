#!/usr/bin/env bash
# Paths are repository-root relative, including deleted paths (--no-renames).
# Use the same set for main change detection and dev promotion arbitration.
gateway_image_runtime_path() {
  case "$1" in
    gateway/Dockerfile|gateway/.dockerignore|gateway/package.json|gateway/package-lock.json|gateway/tsconfig.base.json|gateway/apps/gateway/src/*|gateway/apps/gateway/package.json|gateway/apps/gateway/tsconfig.json|gateway/packages/theme-sdk/src/*|gateway/packages/theme-sdk/package.json|gateway/packages/theme-sdk/tsconfig.json|gateway/scripts/image-runtime-path.sh|scripts/resolve-image-tags.sh|scripts/test-image-tags.sh|.github/workflows/ci-gateway.yml)
      return 0 ;;
    *) return 1 ;;
  esac
}
