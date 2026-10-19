import importlib.util
import json
import re
import shutil
import tempfile
import unittest
import zipfile
from contextlib import redirect_stdout
import io
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("module_dev", ROOT / "scripts/module-dev.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ModuleDevelopmentTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.package = self.root / "plugins/Example"
        module.scaffold(self.package, "example")

    def change(self, callback):
        manifest = module.read(self.package / "module.json")
        callback(manifest)
        module.write_json(self.package / "module.json", manifest)

    def test_generated_module_and_real_proof_packages_validate(self):
        manifest, inventory = module.validate(self.package)
        self.assertEqual("example", manifest["id"])
        self.assertEqual({"module.json", "config.json", "Plugin.php", "tests/OwnerBoundaryTest.php.dist", "tests/acceptance.json"}, inventory.keys())
        for directory in ("plugins/DeliveryReceipt", "plugins/OfferReservation", "plugins/ModulePortal", "plugins-core/Epay"):
            module.validate(ROOT / "api" / directory)

    def test_duplicate_and_non_object_json_are_rejected(self):
        for raw in ('{"schema":1,"schema":1}', '[]', '{"value":NaN}'):
            with self.subTest(raw=raw):
                (self.package / "module.json").write_text(raw)
                with self.assertRaises(ValueError):
                    module.validate(self.package)

    def test_package_versions_match_the_frozen_kernel_grammar(self):
        definition = module.read(ROOT / "contracts/modules/manifest.v1.schema.json")["$defs"]["version"]
        cases = {"1.0.0": True, "1.0.0-alpha.1": True, "1.0.0-alpha-1": True,
                 "1.0.0-..": False, "1.0.0-a..b": False, "1.0.0-a--b": False,
                 "1.0.0-a.": False, "1.0.0\n": False, "01.0.0": False, "1.0.0+build": False}
        for version, valid in cases.items():
            with self.subTest(version=version):
                self.assertEqual(valid, re.search(definition["pattern"], version) is not None)
                self.change(lambda manifest: manifest.update(version=version))
                config = module.read(self.package / "config.json")
                config["version"] = version
                module.write_json(self.package / "config.json", config)
                if valid:
                    self.assertEqual(version, module.validate(self.package)[0]["version"])
                else:
                    with self.assertRaises(ValueError):
                        module.validate(self.package)

    def test_closed_metadata_and_schema_bounds_are_checked(self):
        for callback in (
            lambda m: m.update(operations=[]),
            lambda m: m.update(requires=[{"id": "sql.connection", "min": True, "max": 1}]),
            lambda m: m.update(boot_after="identity"),
            lambda m: m.update(subscriptions=["billing.receipt.v1", "billing.receipt.v1"]),
            lambda m: m["operations"]["version.get"].update(arbitrary_url="https://private"),
            lambda m: m.update(config_schema={"type": "string", "minLength": 3, "maxLength": 1}),
            lambda m: m.update(config_schema={"const": "x", "type": "string"}),
            lambda m: m.update(config_schema={"type": "string", "pattern": "["}),
            lambda m: m.update(config_schema={"type": "string", "x-decimal-maximum": "01"}),
        ):
            with self.subTest(callback=callback):
                shutil.rmtree(self.package)
                module.scaffold(self.package, "example")
                self.change(callback)
                with self.assertRaises(ValueError):
                    module.validate(self.package)

    def test_presentation_permission_cannot_self_authorize_in_composition(self):
        self.package = self.root / "plugins/ModulePortal"
        shutil.copytree(ROOT / "api/plugins/ModulePortal", self.package)
        composition = self.root / "composition.json"
        entry = {"code": "module_portal", "root": "plugins/ModulePortal", "required": False,
                 "grants": ["permission:extensions.module_portal.view"]}
        module.write_json(composition, {"schema": 1, "packages": [entry]})
        with self.assertRaisesRegex(ValueError, "MODULE_GRANT_REQUIRED"):
            module.build_lock(self.root, composition)
        entry["grants"].append("permission:extensions.module_portal.configure")
        module.write_json(composition, {"schema": 1, "packages": [entry]})
        self.assertEqual("module_portal", module.build_lock(self.root, composition)["packages"][0]["code"])
        self.change(lambda m: m["presentation"].update(assets=["Plugin.php"], entry="Plugin.php"))
        with self.assertRaisesRegex(ValueError, "MODULE_PRESENTATION_INVALID"):
            module.validate(self.package)

    def test_symlink_and_case_collisions_are_rejected(self):
        (self.package / "plugin.php").write_text("collision")
        with self.assertRaisesRegex(ValueError, "MODULE_FILE_PATH_INVALID"):
            module.validate(self.package)
        (self.package / "plugin.php").unlink()
        try:
            (self.package / "linked.php").symlink_to(self.package / "Plugin.php")
        except OSError:
            self.skipTest("platform does not permit symlink creation")
        with self.assertRaisesRegex(ValueError, "MODULE_FILE_PATH_INVALID"):
            module.validate(self.package)

    def application(self):
        application = self.root / "application"
        (application / "vendor/bin").mkdir(parents=True)
        for name in ("artisan", "phpunit.xml", "vendor/autoload.php", "vendor/bin/phpunit"):
            (application / name).write_text("fixture")
        return application

    def test_test_plan_never_starts_php_and_templates_cannot_silently_pass(self):
        application = self.application()
        with patch.object(module.subprocess, "run") as run, redirect_stdout(io.StringIO()) as output:
            self.assertEqual(0, module.test_module(self.package, application, plan_only=True))
            run.assert_not_called()
        plan = json.loads(output.getvalue())
        self.assertFalse(plan["executed"])
        self.assertEqual([], plan["test_files"])
        self.assertEqual(["tests/OwnerBoundaryTest.php.dist"], plan["pending_templates"])
        with self.assertRaisesRegex(ValueError, "MODULE_TESTS_REQUIRED"):
            module.test_module(self.package, application)

    def test_test_runner_ignores_production_database_and_cached_configuration(self):
        application = self.application()
        (self.package / "tests/RealOwnerTest.php").write_text("<?php // Explicit owner fixture")
        report = self.root / "result.json"
        def command(argv, **options):
            environment = options["env"]
            self.assertEqual("testing", environment["APP_ENV"])
            self.assertEqual("sqlite", environment["DB_CONNECTION"])
            self.assertEqual(":memory:", environment["DB_DATABASE"])
            self.assertEqual("0", environment["CORE_NATIVE_TEST_MYSQL"])
            self.assertEqual("", environment["DB_URL"])
            self.assertEqual("", environment["DB_PASSWORD"])
            self.assertNotEqual("production-cache.php", environment["APP_CONFIG_CACHE"])
            self.assertEqual(application, options["cwd"])
            if "--configuration" in argv:
                for flag in ("--fail-on-incomplete", "--fail-on-skipped", "--fail-on-risky", "--fail-on-warning"):
                    self.assertIn(flag, argv)
                self.assertIn(str((self.package / "tests").resolve()), argv)
                self.assertIn("--test-suffix", argv)
            return SimpleNamespace(returncode=0)
        with patch.dict(module.os.environ, {"APP_ENV": "production", "DB_CONNECTION": "mysql", "CORE_NATIVE_TEST_MYSQL": "1",
                "DB_URL": "mysql://production.invalid", "DB_PASSWORD": "production-password", "APP_CONFIG_CACHE": "production-cache.php"}), \
                patch.object(module.shutil, "which", return_value="php-fixture"), \
                patch.object(module.subprocess, "run", side_effect=command), redirect_stdout(io.StringIO()):
            self.assertEqual(0, module.test_module(self.package, application, report=report))
        result = module.read(report)
        self.assertEqual("passed", result["status"])
        self.assertEqual("pending", result["acceptance"])
        self.assertEqual(module.test_plan(self.package, application)["source_sha256"], result["source_sha256"])

    def test_lint_failure_never_starts_phpunit_or_overwrites_an_existing_report(self):
        application = self.application()
        (self.package / "tests/RealOwnerTest.php").write_text("<?php")
        report = self.root / "result.json"
        with patch.object(module.shutil, "which", return_value="php-fixture"), \
                patch.object(module.subprocess, "run", return_value=SimpleNamespace(returncode=9)) as run, \
                redirect_stdout(io.StringIO()):
            self.assertEqual(9, module.test_module(self.package, application, report=report))
            self.assertEqual(1, run.call_count)
            self.assertIn("-l", run.call_args.args[0])
            with self.assertRaisesRegex(ValueError, "MODULE_TEST_REPORT_TARGET_INVALID"):
                module.test_module(self.package, application, report=report)
        self.assertEqual("lint", module.read(report)["stage"])

    def test_passing_suite_cannot_attest_sources_changed_during_execution(self):
        application = self.application()
        (self.package / "tests/RealOwnerTest.php").write_text("<?php")
        def command(argv, **options):
            if "--configuration" in argv:
                with (self.package / "Plugin.php").open("a") as stream:
                    stream.write("\n// Changed after source plan\n")
            return SimpleNamespace(returncode=0)
        with patch.object(module.shutil, "which", return_value="php-fixture"), \
                patch.object(module.subprocess, "run", side_effect=command), redirect_stdout(io.StringIO()) as output:
            self.assertEqual(1, module.test_module(self.package, application))
        result = json.loads(output.getvalue())
        self.assertEqual("source-changed", result["stage"])
        self.assertEqual("failed", result["status"])

    def test_unified_tool_entry_forwards_literal_arguments_and_preserves_failure_status(self):
        entry_spec = importlib.util.spec_from_file_location("txboard_dev", ROOT / "scripts/txboard-dev.py")
        entry = importlib.util.module_from_spec(entry_spec)
        entry_spec.loader.exec_module(entry)
        with patch.object(entry.subprocess, "run", return_value=SimpleNamespace(returncode=23)) as run:
            self.assertEqual(23, entry.main(["module", "validate", "a;literal-directory"]))
            command = run.call_args.args[0]
            self.assertEqual("a;literal-directory", command[-1])
            self.assertEqual(str(ROOT / "scripts/module-dev.py"), command[1])
            self.assertFalse(run.call_args.kwargs.get("shell", False))
            self.assertEqual(23, entry.main(["renderer", "--help"]))
            self.assertEqual("--help", run.call_args.args[0][-1])


if __name__ == "__main__":
    unittest.main()
