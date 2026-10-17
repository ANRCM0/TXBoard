import importlib.util
import json
import shutil
import tempfile
import unittest
import zipfile
from pathlib import Path

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
        self.assertEqual({"module.json", "config.json", "Plugin.php"}, inventory.keys())
        for directory in ("plugins/DeliveryReceipt", "plugins/OfferReservation", "plugins/ModulePortal", "plugins-core/Epay"):
            module.validate(ROOT / "api" / directory)

    def test_duplicate_and_non_object_json_are_rejected(self):
        for raw in ('{"schema":1,"schema":1}', '[]', '{"value":NaN}'):
            with self.subTest(raw=raw):
                (self.package / "module.json").write_text(raw)
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


if __name__ == "__main__":
    unittest.main()
