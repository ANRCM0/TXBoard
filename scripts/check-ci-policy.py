#!/usr/bin/env python3
"""Validate workflow ownership and development test selection without starting apps."""

from pathlib import Path
import re
import sys
import xml.etree.ElementTree as ET

import yaml


ROOT = Path(__file__).resolve().parent.parent
AUTOMATIC = {"ci.yml", "docker-txboard.yml"}
CI_GROUPS = {
    "api": "api",
    "mysql-regression": "mysql",
    "installation-bootstrap": "mysql",
    "native-admin-management": "mysql",
    "native-billing-domain": "mysql",
    "native-subscription-domain": "mysql",
    "web": "web",
    "browser-native-http": "browser",
    "native-c2-live": "native",
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
    require(path.name in AUTOMATIC, f"{path.name}: unrecognized workflow entry point")
    require("pull_request" in triggers, f"{path.name}: current PR coverage missing")
    require(not any(key in (triggers["pull_request"] or {}) for key in ("branches", "branches-ignore")),
            f"{path.name}: acceptance must also run for stacked pull requests")
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


def validate_ci(workflow):
    jobs = workflow["jobs"]
    require(set(jobs) == set(CI_GROUPS) | {"changes", "static", "acceptance"},
            "CI must retain component, integration, static and aggregate checks")
    triggers = workflow["on"]
    require(set(triggers) == {"push", "pull_request", "workflow_dispatch"},
            "CI must cover main pushes, all PRs and explicit manual acceptance")
    require(triggers["push"] == {"branches": ["main"]} and triggers["pull_request"] is None,
            "CI selection must inspect the complete diff without workflow path limits")
    require(workflow.get("concurrency") == {
        "group": "ci-${{ github.event_name == 'pull_request' && github.ref || github.sha }}",
        "cancel-in-progress": "${{ github.event_name == 'pull_request' }}",
    }, "Main acceptance must survive a later documentation-only push")
    for name, group in CI_GROUPS.items():
        job = jobs[name]
        require(job.get("needs") == "changes", f"CI/{name}: selection dependency missing")
        condition = ("github.event_name == 'workflow_dispatch' && inputs.legacy_mysql"
                     if name == "mysql-regression" else f"needs.changes.outputs.{group} == 'true'")
        require(job.get("if") == condition, f"CI/{name}: incorrect selection guard")
    gate = jobs["acceptance"]
    require(gate.get("if") == "${{ always() }}", "CI acceptance must run after failed or skipped jobs")
    require(set(gate.get("needs", [])) == set(jobs) - {"acceptance"},
            "CI acceptance must aggregate every job, including MySQL matrices")
    require("needs" not in jobs["static"] and "if" not in jobs["static"],
            "Static checks must run for every CI event")
    require("if" not in jobs["changes"], "CI selection must run for every event")
    native_commands = "\n".join(step.get("run", "") for step in jobs["native-c2-live"]["steps"])
    require("python3 scripts/verify-native-c2.py" in native_commands and "go test" not in native_commands,
            "Native integration must retain its real chain without repeating Node component tests")


def main():
    files = sorted((ROOT / ".github/workflows").glob("*.y*ml"))
    require({path.name for path in files} == AUTOMATIC,
            "Only CI and the Core image workflow may remain; historical entries must be removed")
    workflows = {path.name: validate_workflow(path) for path in files}
    validate_ci(workflows["ci.yml"])
    require((ROOT / ".gitmodules").is_file(), "The external Node source pin is missing")
    for owner in AUTOMATIC:
        for name, job in workflows[owner]["jobs"].items():
            if owner == "ci.yml" and name in {"changes", "static", "acceptance"}:
                continue
            core_checkouts = [step for step in job.get("steps", [])
                              if step.get("uses", "").startswith("actions/checkout@")
                              and "repository" not in step.get("with", {})]
            require(core_checkouts and all(step.get("with", {}).get("submodules") is True
                                          for step in core_checkouts),
                    f"{owner}/{name}: paired acceptance must initialize the fixed Node source")
    excluded, current = validate_suites()
    print(f"CI policy: {len(files)} workflow entry points; component and integration checks retained; "
          f"{excluded} historical suite exclusions, {current} current native test files retained.")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, yaml.YAMLError, ET.ParseError) as error:
        print(f"CI policy failed: {error}", file=sys.stderr)
        sys.exit(1)
