#!/usr/bin/env python3
"""Validate workflow ownership and development test selection without starting apps."""

from pathlib import Path
import re
import sys
import xml.etree.ElementTree as ET

import yaml


ROOT = Path(__file__).resolve().parent.parent
AUTOMATIC = {
    "ci-api.yml",
    "ci-gateway.yml",
    "ci-mysql.yml",
    "ci-native-c2.yml",
    "ci-node.yml",
    "ci-web.yml",
    "docker-txboard.yml",
    "docs.yml",
}
RETIRED_WORKFLOWS = {
    f"ci-authority-{name}.yml"
    for name in ("writer-fence", "inventory-expand", "finance-fence",
                 "native-writers", "identity-writers", "ticket-writers")
}
RETIRED_WORKFLOWS |= {
    f"ci-internal-{name}.yml"
    for name in ("admin", "admin-consumer", "admin-dashboard", "admin-login",
                 "admin-network-groups", "admin-order-list", "admin-plan-list",
                 "admin-sessions", "admin-users", "order-cancel", "sessions")
}


class WorkflowLoader(yaml.SafeLoader):
    pass


# GitHub uses YAML 1.2: the trigger key "on" is a string, not a YAML 1.1 bool.
WorkflowLoader.yaml_implicit_resolvers = {
    key: [(tag, pattern) for tag, pattern in values
          if tag != "tag:yaml.org,2002:bool"]
    for key, values in yaml.SafeLoader.yaml_implicit_resolvers.items()
}
WorkflowLoader.add_implicit_resolver(
    "tag:yaml.org,2002:bool", re.compile(r"^(?:true|false)$"), list("tf")
)


def mapping(loader, node, deep=False):
    result = {}
    for key_node, value_node in node.value:
        key = loader.construct_object(key_node, deep=deep)
        if key in result:
            raise ValueError(f"Duplicate YAML key: {key}")
        result[key] = loader.construct_object(value_node, deep=deep)
    return result


WorkflowLoader.add_constructor(
    yaml.resolver.BaseResolver.DEFAULT_MAPPING_TAG, mapping
)


def require(condition, message):
    if not condition:
        raise ValueError(message)


def validate_workflow(path):
    workflow = yaml.load(path.read_text(encoding="utf-8"), Loader=WorkflowLoader)
    require(isinstance(workflow, dict), f"{path.name}: missing workflow mapping")
    triggers = workflow.get("on")
    require(isinstance(triggers, dict), f"{path.name}: explicit trigger mapping required")
    if path.name in AUTOMATIC:
        require("pull_request" in triggers, f"{path.name}: current PR coverage missing")
    else:
        require(set(triggers) == {"workflow_dispatch"},
                f"{path.name}: historical workflow must remain manual-only")
    require("pull_request_target" not in triggers,
            f"{path.name}: untrusted PRs must not run with target credentials")
    jobs = workflow.get("jobs")
    require(isinstance(jobs, dict) and jobs, f"{path.name}: no jobs")
    dependencies = {}
    for name, job in jobs.items():
        require(isinstance(job, dict), f"{path.name}/{name}: invalid job")
        needs = job.get("needs", [])
        if isinstance(needs, str):
            needs = [needs]
        require(isinstance(needs, list) and all(need in jobs for need in needs),
                f"{path.name}/{name}: unknown job dependency")
        dependencies[name] = needs
        if path.name not in AUTOMATIC:
            for step in job.get("steps", []):
                command = step.get("run", "")
                if re.search(r"(?:artisan\s+test|vendor/bin/phpunit)\b", command):
                    require("--filter" in command or re.search(r"tests/(?:Feature|Unit)/", command),
                            f"{path.name}/{name}: manual suite must select explicit tests")
    visiting, visited = set(), set()

    def visit(name):
        require(name not in visiting, f"{path.name}: job dependency cycle")
        if name in visited:
            return
        visiting.add(name)
        for dependency in dependencies[name]:
            visit(dependency)
        visiting.remove(name)
        visited.add(name)

    for name in jobs:
        visit(name)
    return workflow


def validate_suites():
    configuration = ET.parse(ROOT / "api/phpunit.xml").getroot()
    excluded = {
        item.text.strip()
        for item in configuration.findall("./testsuites/testsuite/exclude")
    }
    require(not excluded, "Current PHP suite must not hide tests through historical exclusions")
    current = (
        list((ROOT / "api/tests/Feature/Installation").glob("*Test.php"))
        + list((ROOT / "api/tests/Feature/Subscription").glob("Native*Test.php"))
        + list((ROOT / "api/tests/Feature/Subscription").glob("Published*Test.php"))
        + list((ROOT / "api/tests/Feature/UsageV2").glob("*Test.php"))
        + list((ROOT / "api/tests/Unit/Domains/CredentialProvider").glob("*Test.php"))
    )
    included = [
        (ROOT / "api" / item.text.strip()).resolve()
        for item in configuration.findall("./testsuites/testsuite/directory")
    ]
    for path in current:
        relative = path.relative_to(ROOT / "api").as_posix()
        require(any(path.is_relative_to(directory) for directory in included),
                f"Current native test is not discovered: {relative}")
        require(not any(relative == old or relative.startswith(old + "/") for old in excluded),
                f"Current native test excluded: {relative}")
    require(not (ROOT / "api/phpunit-historical.xml").exists(),
            "Duplicate historical PHP configuration was restored")
    return len(excluded), len(current)


def main():
    files = sorted((ROOT / ".github/workflows").glob("*.yml"))
    require(AUTOMATIC <= {path.name for path in files}, "An automatic workflow is missing")
    require(not RETIRED_WORKFLOWS & {path.name for path in files},
            "A retired prototype workflow was restored")
    workflows = {path.name: validate_workflow(path) for path in files}
    for owner in ("ci-api.yml", "ci-mysql.yml", "docker-txboard.yml"):
        trigger = workflows[owner]["on"]["pull_request"]
        require(not any(key in trigger for key in ("branches", "branches-ignore")),
                f"{owner}: Core acceptance must also run for stacked pull requests")
    require(set(workflows["ci-native-c2.yml"]["jobs"]) == {"native-c2-live"},
            "Native integration must not repeat component unit suites")
    makefile = (ROOT / "node/Makefile").read_text(encoding="utf-8")
    require("./cmd/..." in makefile or "./cmd/credential-provider" in makefile,
            "The single Node test owner must cover the Provider CLI")
    excluded, current = validate_suites()
    print(f"CI policy: {len(files)} workflows parsed; {len(AUTOMATIC)} automatic, "
          f"{len(files) - len(AUTOMATIC)} manual; {excluded} historical suite exclusions, "
          f"{current} current native test files retained.")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, yaml.YAMLError, ET.ParseError) as error:
        print(f"CI policy failed: {error}", file=sys.stderr)
        sys.exit(1)
