#!/usr/bin/env python3
"""Trusted developer tooling. Package declarations never grant deployment authority.

scaffold creates an independent SDK module. validate/package inspect a single explicit
directory. lock consumes a maintainer-owned composition file and emits exact hashes.
No command installs packages, discovers application modules, or enables database rows.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile

CODE = re.compile(r"[a-z][a-z0-9_]{0,63}\Z")
VERSION = re.compile(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?\Z")
RELATIVE = re.compile(r"[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*\Z")
GRANT = re.compile(r"(?:capability|event|permission):[a-z][a-z0-9._-]*\Z")
MAX_FILES, MAX_BYTES = 2000, 50 * 1024 * 1024


def read(path):
    if path.is_symlink() or not path.is_file() or path.stat().st_size > 2097152:
        raise ValueError("MODULE_JSON_UNAVAILABLE")
    def pairs(items):
        result = {}
        for key, value in items:
            if key in result:
                raise ValueError("MODULE_JSON_DUPLICATE_KEY")
            result[key] = value
        return result
    def nonfinite(value):
        raise ValueError("MODULE_JSON_NONFINITE")
    value = json.loads(path.read_text(encoding="utf-8"), object_pairs_hook=pairs, parse_constant=nonfinite)
    if not isinstance(value, dict):
        raise ValueError("MODULE_JSON_OBJECT_REQUIRED")
    return value


def studly(code):
    return "".join(part[0].upper() + part[1:] for part in code.split("_") if part)


def object_schema(properties=None, required=None):
    return {"type": "object", "properties": properties or {}, "required": required or [], "additionalProperties": False}


def write_json(path, value):
    path.write_text(json.dumps(value, ensure_ascii=True, indent=2) + "\n", encoding="utf-8", newline="\n")


def files(path, required=("Plugin.php", "config.json", "module.json")):
    if not path.is_dir() or path.is_symlink():
        raise ValueError("MODULE_DIRECTORY_INVALID")
    for ancestor in (path, *path.parents):
        if ancestor.is_symlink():
            raise ValueError("MODULE_LINK_FORBIDDEN")
    found, total, folded = {}, 0, set()
    for item in sorted(path.rglob("*"), key=lambda item: item.relative_to(path).as_posix()):
        relative = item.relative_to(path).as_posix()
        if item.is_symlink() or len(relative) > 240 or not RELATIVE.fullmatch(relative) or any(p in (".", "..") for p in item.relative_to(path).parts):
            raise ValueError("MODULE_FILE_PATH_INVALID")
        if item.is_dir():
            continue
        if not item.is_file() or relative.lower() in folded:
            raise ValueError("MODULE_FILE_PATH_INVALID")
        folded.add(relative.lower())
        total += item.stat().st_size
        if len(found) >= MAX_FILES or total > MAX_BYTES:
            raise ValueError("MODULE_PACKAGE_LIMIT")
        found[relative] = hashlib.sha256(item.read_bytes()).hexdigest()
    if not set(required) <= found.keys():
        raise ValueError("MODULE_PACKAGE_INCOMPLETE")
    return found


def schema(value, depth=0):
    allowed = {"type", "const", "enum", "anyOf", "properties", "required", "additionalProperties", "items", "minItems", "maxItems", "minLength", "maxLength", "pattern", "minimum", "maximum", "x-decimal-maximum"}
    if not isinstance(value, dict) or depth > 20 or value.keys() - allowed:
        raise ValueError("MODULE_SCHEMA_INVALID")
    if "anyOf" in value:
        if set(value) != {"anyOf"} or not isinstance(value["anyOf"], list) or not 2 <= len(value["anyOf"]) <= 8:
            raise ValueError("MODULE_SCHEMA_INVALID")
        for variant in value["anyOf"]:
            schema(variant, depth + 1)
        return
    if "const" in value:
        if set(value) != {"const"} or not primitive(value["const"]):
            raise ValueError("MODULE_SCHEMA_INVALID")
        return
    kind = value.get("type")
    if kind not in ("object", "array", "integer", "string", "boolean", "null"):
        raise ValueError("MODULE_SCHEMA_INVALID")
    for key in ("minLength", "maxLength", "minItems", "maxItems", "minimum", "maximum"):
        if key in value and (type(value[key]) is not int or abs(value[key]) > 9007199254740991 or key not in ("minimum", "maximum") and value[key] < 0):
            raise ValueError("MODULE_SCHEMA_INVALID")
    for low, high in (("minLength", "maxLength"), ("minItems", "maxItems"), ("minimum", "maximum")):
        if low in value and high in value and value[low] > value[high]:
            raise ValueError("MODULE_SCHEMA_INVALID")
    if "enum" in value and (not isinstance(value["enum"], list) or not 1 <= len(value["enum"]) <= 64 or not all(primitive(item) for item in value["enum"])):
        raise ValueError("MODULE_SCHEMA_INVALID")
    if "x-decimal-maximum" in value and (kind != "string" or not isinstance(value["x-decimal-maximum"], str) or not re.fullmatch(r"(?:0|[1-9][0-9]{0,18})", value["x-decimal-maximum"])):
        raise ValueError("MODULE_SCHEMA_INVALID")
    if "pattern" in value:
        if not isinstance(value["pattern"], str) or len(value["pattern"]) > 512:
            raise ValueError("MODULE_SCHEMA_INVALID")
        try:
            re.compile(value["pattern"])
        except re.error:
            raise ValueError("MODULE_SCHEMA_INVALID") from None
    if kind == "object":
        properties = value.get("properties")
        required = value.get("required")
        if not isinstance(properties, dict) or len(properties) > 64 or not isinstance(required, list) or not all(isinstance(item, str) for item in required) or len(set(required)) != len(required) or set(required) - properties.keys() or value.get("additionalProperties") is not False:
            raise ValueError("MODULE_SCHEMA_INVALID")
        for field, definition in properties.items():
            if not CODE.fullmatch(field):
                raise ValueError("MODULE_SCHEMA_INVALID")
            schema(definition, depth + 1)
    if kind == "array":
        if type(value.get("maxItems")) is not int or not 0 <= value["maxItems"] <= 1000:
            raise ValueError("MODULE_SCHEMA_INVALID")
        schema(value.get("items"), depth + 1)


def primitive(value):
    return value is None or type(value) in (str, bool) or type(value) is int and abs(value) <= 9007199254740991


def sequence(value, maximum):
    return isinstance(value, list) and len(value) <= maximum and all(isinstance(item, str) for item in value) and len(set(value)) == len(value)


def validate(path):
    inventory = files(path)
    manifest, config = read(path / "module.json"), read(path / "config.json")
    allowed = {"schema", "id", "version", "kind", "execution", "kernel_api", "requires", "boot_after", "operations", "subscriptions", "config_schema", "schema_version", "presentation", "connector", "secrets", "lifecycle"}
    code = manifest.get("id", "")
    if manifest.keys() - allowed or not isinstance(code, str) or not CODE.fullmatch(code) or not isinstance(manifest.get("version"), str) or len(manifest["version"]) > 80 or not VERSION.fullmatch(manifest["version"]) or manifest.get("schema") != "core-module/v1" or manifest.get("execution") != "in-process" or manifest.get("kind") not in ("optional-business", "connector", "presentation"):
        raise ValueError("MODULE_MANIFEST_INVALID")
    if config.get("code") != code or config.get("version") != manifest["version"] or config.get("package", {}).get("schema") != 1 or config.get("type") not in ("feature", "payment"):
        raise ValueError("MODULE_IDENTITY_INVALID")
    if manifest.get("kernel_api") != {"min": 1, "max": 1} or type(manifest.get("schema_version")) is not int or not 0 <= manifest["schema_version"] <= 2147483647:
        raise ValueError("MODULE_VERSION_INVALID")
    operations, requirements = manifest.get("operations"), manifest.get("requires")
    if not isinstance(operations, dict) or len(operations) > 64 or not isinstance(requirements, list) or len(requirements) > 64:
        raise ValueError("MODULE_MANIFEST_INVALID")
    seen = set()
    for requirement in requirements:
        if not isinstance(requirement, dict) or set(requirement) != {"id", "min", "max"} or not isinstance(requirement["id"], str) or not re.fullmatch(r"[a-z][a-z0-9._-]{0,95}", requirement["id"]) or requirement["id"] in seen or type(requirement["min"]) is not int or type(requirement["max"]) is not int or not 1 <= requirement["min"] <= requirement["max"] <= 2147483647:
            raise ValueError("MODULE_REQUIREMENT_INVALID")
        seen.add(requirement["id"])
    if not sequence(manifest.get("boot_after"), 64) or any(not re.fullmatch(r"[a-z][a-z0-9._-]{0,95}", item) for item in manifest["boot_after"]):
        raise ValueError("MODULE_BOOT_ORDER_INVALID")
    if manifest.get("connector") not in (None, "payment") or manifest.get("lifecycle", "managed") not in ("managed", "payment-owner"):
        raise ValueError("MODULE_MANIFEST_INVALID")
    schema(manifest.get("config_schema"))
    for operation, spec in operations.items():
        if not re.fullmatch(r"[a-z][a-z0-9.-]{0,63}", operation) or not isinstance(spec, dict) or spec.keys() - {"actor", "permission", "idempotency", "input", "output", "recovery"} or spec.get("actor") not in ("user", "admin") or spec.get("idempotency") not in ("read", "domain") or "permission" not in spec or "recovery" in spec and type(spec["recovery"]) is not bool:
            raise ValueError("MODULE_OPERATION_INVALID")
        schema(spec.get("input"))
        schema(spec.get("output"))
        if not permission(spec["actor"], spec["permission"], code):
            raise ValueError("MODULE_PERMISSION_INVALID")
    if not sequence(manifest.get("subscriptions"), 32) or not sequence(manifest.get("secrets", []), 16):
        raise ValueError("MODULE_MANIFEST_INVALID")
    for event in manifest["subscriptions"]:
        if not re.fullmatch(r"[a-z][a-z0-9.-]*\.v[1-9][0-9]*", event):
            raise ValueError("MODULE_EVENT_INVALID")
    for field in manifest.get("secrets", []):
        if manifest["config_schema"].get("properties", {}).get(field, {}).get("type") != "string":
            raise ValueError("MODULE_SECRET_INVALID")
    if manifest.get("lifecycle") == "payment-owner" and (manifest.get("connector") != "payment" or operations or manifest["subscriptions"] or manifest["schema_version"] != 0):
        raise ValueError("MODULE_MANIFEST_INVALID")
    if "presentation" in manifest:
        view = manifest["presentation"]
        if not isinstance(view, dict) or view.keys() - {"actor", "permission", "configuration_permission", "title", "entry", "assets", "operations"} or view.get("actor") not in ("user", "admin") or "permission" not in view or not permission(view["actor"], view["permission"], code) or not isinstance(view.get("title"), str) or not 1 <= len(view["title"].encode()) <= 80 or not sequence(view.get("assets"), 32) or not sequence(view.get("operations"), 64) or not isinstance(view.get("entry"), str) or not view["entry"].endswith(".html") or view["entry"] not in view["assets"]:
            raise ValueError("MODULE_PRESENTATION_INVALID")
        if view.get("configuration_permission") is not None and not permission(view["actor"], view["configuration_permission"], code):
            raise ValueError("MODULE_PERMISSION_INVALID")
        for asset in view["assets"]:
            if not re.fullmatch(r"ui/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\.(?:html|js|css|svg|png|woff2)", asset) or asset not in inventory or (path / asset).stat().st_size > 2097152:
                raise ValueError("MODULE_PRESENTATION_INVALID")
        for operation in view["operations"]:
            if operation not in operations or operations[operation]["actor"] != view["actor"]:
                raise ValueError("MODULE_PRESENTATION_INVALID")
    return manifest, inventory


def permission(actor, value, code):
    return value is None if actor == "user" else isinstance(value, str) and value.startswith("extensions." + code + ".") and len(value) <= 117 and re.fullmatch(r"[a-z][a-z0-9._-]*", value) is not None


def scaffold(path, code):
    if not CODE.fullmatch(code) or path.exists():
        raise ValueError("MODULE_SCAFFOLD_TARGET_INVALID")
    path.mkdir(parents=True)
    version = "1.0.0"
    write_json(path / "module.json", {"schema": "core-module/v1", "id": code, "version": version, "kind": "optional-business", "execution": "in-process", "kernel_api": {"min": 1, "max": 1}, "requires": [], "boot_after": [], "operations": {"version.get": {"actor": "user", "permission": None, "idempotency": "read", "input": object_schema(), "output": object_schema({"version": {"type": "string", "maxLength": 80}}, ["version"])}}, "subscriptions": [], "config_schema": object_schema(), "schema_version": 1, "secrets": []})
    write_json(path / "config.json", {"package": {"schema": 1}, "name": studly(code), "code": code, "version": version, "type": "feature", "description": "Independent SDK module", "author": "Module author", "config": {}})
    source = """<?php

namespace Plugin\\__CLASS__;

use App\\Extensions\\Contracts\\DeploymentModule;
use App\\Extensions\\Contracts\\ExtensionModule;
use App\\Extensions\\Contracts\\ExtensionRequest;
use App\\Extensions\\Contracts\\ExtensionResponse;
use App\\Extensions\\Contracts\\ExtensionFailure;
use App\\Services\\Plugin\\AbstractPlugin;

final class Plugin extends AbstractPlugin implements DeploymentModule, ExtensionModule
{
    public function packageVersion(): string { return '1.0.0'; }

    public function prepareSchema(string $deploymentId, int $fromVersion, int $toVersion): void
    {
        if ($toVersion !== 1 || !in_array($fromVersion, [0, 1], true)) throw new ExtensionFailure('MODULE_SCHEMA_VERSION_CONFLICT');
    }

    public function handle(ExtensionRequest $request): ExtensionResponse
    {
        if ($request->operation !== 'version.get') throw new ExtensionFailure('MODULE_OPERATION_NOT_FOUND', 404);
        return new ExtensionResponse(['version' => $this->packageVersion()]);
    }
}
"""
    (path / "Plugin.php").write_text(source.replace("__CLASS__", studly(code)), encoding="utf-8", newline="\n")
    (path / "tests").mkdir()
    template = r'''<?php

use PHPUnit\Framework\TestCase;

// Rename this file after wiring the real owner fixture and recovery driver.
final class OwnerBoundaryTest extends TestCase
{
    public function test_actor_and_current_grant_are_rechecked(): void
    {
        $this->markTestIncomplete('Exercise another user, a revoked session and a disabled grant against the real owner operation.');
    }

    public function test_original_intent_recovers_after_a_lost_response(): void
    {
        $this->markTestIncomplete('Replay the same key and input after a committed-but-lost response; reject a changed input and preserve one owner effect.');
    }

    public function test_failure_rolls_back_owner_state(): void
    {
        $this->markTestIncomplete('Inject failure before commit and verify owner state, ledger and durable events remain consistent.');
    }
}
'''
    (path / "tests/OwnerBoundaryTest.php.dist").write_text(template, encoding="utf-8", newline="\n")
    write_json(path / "tests/acceptance.json", {"schema": "module-acceptance-cases/v1", "status": "pending",
        "cases": ["cross-user-isolation", "revoked-grants", "idempotency-conflict",
            "lost-response-recovery", "transaction-rollback", "disabled-owner-hooks", "package-fingerprint"]})


def build_lock(root, composition):
    source = read(composition)
    if set(source) != {"schema", "packages"} or source.get("schema") != 1 or not isinstance(source.get("packages"), list) or len(source["packages"]) > 64:
        raise ValueError("MODULE_COMPOSITION_INVALID")
    entries, seen = [], set()
    for entry in source["packages"]:
        if not isinstance(entry, dict):
            raise ValueError("MODULE_COMPOSITION_INVALID")
        code = entry.get("code", "")
        if not isinstance(code, str) or not CODE.fullmatch(code) or code in seen or type(entry.get("required")) is not bool or entry.get("root") not in ("plugins/" + studly(code), "plugins-core/" + studly(code)) or entry.keys() - {"code", "root", "required", "grants"}:
            raise ValueError("MODULE_COMPOSITION_INVALID")
        seen.add(code)
        grants = entry.get("grants")
        if not sequence(grants, 64) or any(len(g) > 128 or not GRANT.fullmatch(g) for g in grants):
            raise ValueError("MODULE_GRANT_INVALID")
        manifest, inventory = validate(root / entry["root"])
        expected = {"capability:" + r["id"] for r in manifest["requires"]} | {"event:" + e for e in manifest["subscriptions"]} | {"permission:" + s["permission"] for s in manifest["operations"].values() if s["actor"] == "admin"}
        view = manifest.get("presentation", {})
        expected |= {"permission:" + view[field] for field in ("permission", "configuration_permission") if isinstance(view.get(field), str)}
        if manifest["id"] != code or expected - set(grants):
            raise ValueError("MODULE_GRANT_REQUIRED")
        entries.append({**entry, "version": manifest["version"], "files": inventory})
    return {"schema": 1, "packages": sorted(entries, key=lambda item: item["code"])}


def test_plan(directory, application_root, php="php"):
    manifest, inventory = validate(directory)
    application = application_root.resolve()
    if application_root.is_symlink() or not (application / "artisan").is_file() or not (application / "phpunit.xml").is_file():
        raise ValueError("MODULE_TEST_APPLICATION_INVALID")
    tests = sorted(name for name in inventory if name.startswith("tests/") and name.endswith("Test.php"))
    return {"schema": "module-test-plan/v1", "module": manifest["id"], "version": manifest["version"],
        "source_sha256": hashlib.sha256(json.dumps(inventory, sort_keys=True, separators=(",", ":")).encode()).hexdigest(),
        "application_root": str(application), "php": php, "test_files": tests,
        "lint_files": sorted(name for name in inventory if name.endswith(".php")),
        "pending_templates": sorted(name for name in inventory if name.endswith(".php.dist")),
        "acceptance": "pending", "executed": False}


def test_module(directory, application_root, php="php", report=None, plan_only=False):
    plan = test_plan(directory, application_root, php)
    if plan_only:
        print(json.dumps(plan, sort_keys=True))
        return 0
    if not plan["test_files"]:
        raise ValueError("MODULE_TESTS_REQUIRED: implement tests/*Test.php; templates are not executable tests")
    if report is not None and (report.exists() or report.resolve().is_relative_to(directory.resolve())):
        raise ValueError("MODULE_TEST_REPORT_TARGET_INVALID")
    executable = shutil.which(php)
    if executable is None:
        raise ValueError("MODULE_TEST_PHP_UNAVAILABLE")
    application = Path(plan["application_root"])
    runner = application / "vendor/bin/phpunit"
    if not runner.is_file() or not (application / "vendor/autoload.php").is_file():
        raise ValueError("MODULE_TEST_DEPENDENCIES_REQUIRED")
    result = {**plan, "schema": "module-test-result/v1", "executed": True, "status": "failed", "stage": "lint"}
    code = 0
    # Ignore production DB settings and cached application configuration in this developer runner.
    with tempfile.TemporaryDirectory(prefix="txboard-module-tests-") as temporary:
        cache = Path(temporary)
        (cache / "views").mkdir()
        environment = {**os.environ, "APP_ENV": "testing", "APP_DEBUG": "true",
            "APP_KEY": "base64:ZdD07lazsdQQmdBAJ2tA5WjufeI2hP8xlP6zgmvCMZs=", "BCRYPT_ROUNDS": "4",
            "DB_CONNECTION": "sqlite", "DB_DATABASE": ":memory:", "DB_URL": "", "DATABASE_URL": "",
            "CORE_NATIVE_TEST_MYSQL": "0", "CORE_INSTALLATION_TEST_MYSQL": "0", "NATIVE_TRAFFIC_PERIOD_MYSQL": "0",
            "CORE_PROVIDER_INTEROP_ENDPOINT": "", "DB_HOST": "127.0.0.1", "DB_PORT": "1", "DB_SOCKET": "",
            "DB_USERNAME": "module-test", "DB_PASSWORD": "",
            "CACHE_DRIVER": "array", "CACHE_STORE": "array", "SETTING_CACHE_STORE": "array",
            "QUEUE_CONNECTION": "sync", "SESSION_DRIVER": "array", "MAIL_MAILER": "array", "TELESCOPE_ENABLED": "false",
            "APP_CONFIG_CACHE": str(cache / "config.php"), "APP_ROUTES_CACHE": str(cache / "routes.php"),
            "APP_SERVICES_CACHE": str(cache / "services.php"), "APP_PACKAGES_CACHE": str(cache / "packages.php"),
            "VIEW_COMPILED_PATH": str(cache / "views")}
        for name in plan["lint_files"]:
            code = subprocess.run([executable, "-l", str((directory / name).resolve())], cwd=application,
                env=environment, check=False, timeout=30).returncode
            if code != 0:
                break
        if code == 0:
            result["stage"] = "phpunit"
            command = [executable, str(runner), "--configuration", str(application / "phpunit.xml"),
                "--do-not-cache-result", "--fail-on-incomplete", "--fail-on-risky", "--fail-on-warning", "--fail-on-skipped",
                "--test-suffix", "Test.php", str((directory / "tests").resolve())]
            code = subprocess.run(command, cwd=application, env=environment, check=False, timeout=600).returncode
    if test_plan(directory, application_root, php)["source_sha256"] != plan["source_sha256"]:
        result["stage"], code = "source-changed", 1
    result["status"], result["exit_code"] = ("passed" if code == 0 else "failed"), code
    if report is not None:
        with report.open("x", encoding="utf-8", newline="\n") as stream:
            stream.write(json.dumps(result, indent=2, sort_keys=True) + "\n")
    print(json.dumps(result, sort_keys=True))
    return code if 0 <= code <= 255 else 1


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    create = commands.add_parser("scaffold")
    create.add_argument("code")
    create.add_argument("directory", type=Path)
    check = commands.add_parser("validate")
    check.add_argument("directory", type=Path)
    test = commands.add_parser("test", help="Lint and run explicit package tests against disposable SQLite configuration")
    test.add_argument("directory", type=Path)
    test.add_argument("--application-root", type=Path, default=Path(__file__).resolve().parents[1] / "api")
    test.add_argument("--php", default="php")
    test.add_argument("--report", type=Path)
    test.add_argument("--plan", action="store_true", help="Print the plan without starting PHP or running tests")
    package = commands.add_parser("package")
    package.add_argument("directory", type=Path)
    package.add_argument("output", type=Path)
    lock = commands.add_parser("lock")
    lock.add_argument("composition", type=Path)
    lock.add_argument("output", type=Path)
    lock.add_argument("--application-root", type=Path, default=Path(__file__).resolve().parents[1] / "api")
    lock.add_argument("--check", action="store_true")
    args = parser.parse_args()
    try:
        if args.command == "scaffold":
            scaffold(args.directory, args.code)
            validate(args.directory)
        elif args.command == "validate":
            validate(args.directory)
        elif args.command == "test":
            return test_module(args.directory, args.application_root, args.php, args.report, args.plan)
        elif args.command == "package":
            manifest, inventory = validate(args.directory)
            if args.output.exists() or args.output.resolve().is_relative_to(args.directory.resolve()):
                raise ValueError("MODULE_ARCHIVE_TARGET_INVALID")
            with zipfile.ZipFile(args.output, "x", compression=zipfile.ZIP_DEFLATED) as archive:
                for file in inventory:
                    archive.write(args.directory / file, studly(manifest["id"]) + "/" + file)
        else:
            result = build_lock(args.application_root, args.composition)
            if args.check:
                if read(args.output) != result:
                    raise ValueError("MODULE_LOCK_STALE")
            else:
                write_json(args.output, result)
        print("Module development: " + args.command + " passed")
    except (ValueError, OSError, KeyError, TypeError, subprocess.TimeoutExpired) as error:
        print(str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
