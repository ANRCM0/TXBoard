#!/usr/bin/env python3
"""Unified development entry point for explicit module, renderer and hook commands."""
import argparse
from pathlib import Path
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("tool", choices=["module", "renderer", "hooks"])
    values = list(sys.argv[1:] if argv is None else argv)
    args = parser.parse_args(values[:1])
    remaining = values[1:]
    if args.tool in ("module", "renderer"):
        return subprocess.run([sys.executable, str(ROOT / "scripts" / (args.tool + "-dev.py")), *remaining], check=False).returncode
    hooks = argparse.ArgumentParser(description="Inspect runtime composition and hook ownership through the existing Core command")
    hooks.add_argument("--application-root", type=Path, default=ROOT / "api")
    hooks.add_argument("--php", default="php")
    options = hooks.parse_args(remaining)
    executable = shutil.which(options.php)
    application = options.application_root.resolve()
    if executable is None or not (application / "artisan").is_file():
        print("HOOK_INSPECTION_RUNTIME_UNAVAILABLE", file=sys.stderr)
        return 1
    return subprocess.run([executable, str(application / "artisan"), "core:modules", "--hooks", "--no-interaction"],
        cwd=application, check=False).returncode


if __name__ == "__main__":
    raise SystemExit(main())
