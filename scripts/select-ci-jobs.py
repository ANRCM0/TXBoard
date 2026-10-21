#!/usr/bin/env python3
"""Select CI jobs from the complete Git diff, without GitHub path-list limits."""

import json
import os
from pathlib import Path
import re
import subprocess


ROOT = Path(__file__).resolve().parent.parent
GROUPS = ("api", "mysql", "web", "browser", "native")


def matches(path, pattern):
    expression = ""
    index = 0
    while index < len(pattern):
        if pattern[index:index + 3] == "**/":
            expression += "(?:.*/)?"
            index += 3
        elif pattern[index:index + 2] == "**":
            expression += ".*"
            index += 2
        else:
            character = pattern[index]
            expression += "[^/]*" if character == "*" else "[^/]" if character == "?" else re.escape(character)
            index += 1
    return re.fullmatch(expression, path) is not None


def included(path, patterns):
    selected = False
    for pattern in patterns:
        negative = pattern.startswith("!")
        if matches(path, pattern[1:] if negative else pattern):
            selected = not negative
    return selected


def select(paths, filters):
    if paths is None or any(included(path, filters["shared"]) for path in paths):
        return dict.fromkeys(GROUPS, True)
    return {group: any(included(path, filters[group]) for path in paths) for group in GROUPS}


def changed_paths(event_name, event, run_git=None):
    if event_name == "pull_request":
        request = event.get("pull_request", {})
        before, after = request.get("base", {}).get("sha"), request.get("head", {}).get("sha")
        comparison = "..."
    elif event_name == "push":
        before, after = event.get("before"), event.get("after")
        comparison = ".."
    else:
        return None
    if not all(isinstance(sha, str) and re.fullmatch(r"[a-f0-9]{40}", sha) and sha != "0" * 40
               for sha in (before, after)):
        return None
    if run_git is None:
        def run_git(arguments):
            return subprocess.check_output(arguments, cwd=ROOT, stderr=subprocess.PIPE)
    try:
        output = run_git(["git", "diff", "--no-renames", "--name-only", "-z", before + comparison + after, "--"])
    except subprocess.CalledProcessError:
        # A deleted/force-pushed base must never silently skip required checks.
        return None
    return output.decode("utf-8", errors="surrogateescape").rstrip("\0").split("\0") if output else []


def main():
    event = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text(encoding="utf-8"))
    filters = json.loads((ROOT / ".github/ci-paths.json").read_text(encoding="utf-8"))
    paths = changed_paths(os.environ["GITHUB_EVENT_NAME"], event)
    selected = select(paths, filters)
    with Path(os.environ["GITHUB_OUTPUT"]).open("a", encoding="utf-8") as output:
        for group, enabled in selected.items():
            output.write(f"{group}={str(enabled).lower()}\n")
    print(json.dumps({"changed_files": len(paths) if paths is not None else "full selection",
                      "selected": selected}, indent=2))


if __name__ == "__main__":
    main()
