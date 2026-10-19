import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('renderer_dev', ROOT / 'scripts/renderer-dev.py')
renderer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(renderer)


class RendererDevelopmentTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.package = self.root / 'example'
        renderer.scaffold(self.package, 'example', 'example/v1')

    def run_tool(self, *args):
        return subprocess.run([sys.executable, str(ROOT / 'scripts/txboard-dev.py'), 'renderer', *map(str, args)],
            capture_output=True, text=True, check=False, timeout=30)

    def test_scaffold_is_explicit_source_with_unexecuted_acceptance(self):
        manifest, inventory = renderer.validate(self.package)
        self.assertEqual('static-provider', manifest['execution'])
        self.assertIn('renderer_test.go.dist', inventory)
        self.assertEqual('pending', renderer.module.read(self.package / 'acceptance-cases.json')['status'])
        self.assertNotIn('go.mod', inventory)

    def test_external_source_lock_refuses_same_version_changes_and_archive_keeps_exact_pin(self):
        lock = self.root / 'source-lock.json'
        self.assertEqual(0, self.run_tool('lock', self.package, lock).returncode)
        original = renderer.module.read(lock)
        self.assertEqual(0, self.run_tool('verify', self.package, lock).returncode)
        archive = self.root / 'example.zip'
        self.assertEqual(0, self.run_tool('package', self.package, archive).returncode)
        with zipfile.ZipFile(archive) as package:
            pin = json.loads(package.read('example/source-lock.json'))
            self.assertEqual(original, pin)
            for name, digest in pin['files'].items():
                self.assertEqual(digest, renderer.hashlib.sha256(package.read('example/' + name)).hexdigest())
        with (self.package / 'renderer.go').open('a', encoding='utf-8') as stream:
            stream.write('\n// Changed source\n')
        self.assertNotEqual(0, self.run_tool('verify', self.package, lock).returncode)
        changed = self.run_tool('lock', self.package, lock)
        self.assertNotEqual(0, changed.returncode)
        self.assertIn('RENDERER_VERSION_REUSED', changed.stderr)
        self.assertEqual(original, renderer.module.read(lock))

    def test_closed_manifest_and_output_identity_reject_mismatched_documents(self):
        manifest = renderer.module.read(self.package / 'renderer.json')
        manifest['output'] = {'content_type': 'application/json', 'extension': 'txt'}
        renderer.module.write_json(self.package / 'renderer.json', manifest)
        with self.assertRaisesRegex(ValueError, 'RENDERER_OUTPUT_INVALID'):
            renderer.validate(self.package)
        manifest['output'] = {'content_type': 'text/plain', 'extension': 'conf'}
        renderer.module.write_json(self.package / 'renderer.json', manifest)
        renderer.validate(self.package)
        manifest['arbitrary_runtime_url'] = 'https://unreviewed.invalid'
        renderer.module.write_json(self.package / 'renderer.json', manifest)
        with self.assertRaisesRegex(ValueError, 'RENDERER_MANIFEST_INVALID'):
            renderer.validate(self.package)

    def test_internal_lock_and_archive_overwrite_are_refused(self):
        archive = self.root / 'existing.zip'
        archive.write_bytes(b'retained')
        self.assertNotEqual(0, self.run_tool('package', self.package, archive).returncode)
        self.assertEqual(b'retained', archive.read_bytes())
        self.assertNotEqual(0, self.run_tool('lock', self.package, self.package / 'lock.json').returncode)
        self.assertFalse((self.package / 'lock.json').exists())


if __name__ == '__main__':
    unittest.main()
