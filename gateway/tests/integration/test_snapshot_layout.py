"""Exercise snapshot provenance with real temporary legacy and monorepo trees."""
import ast
import json
import pathlib
import shutil
import subprocess
import sys
import tempfile
import unittest


SCRIPT = pathlib.Path(__file__).with_name('prepare-internal-user-snapshot.py').resolve()


class SnapshotLayoutTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='gateway-snapshot-layout-')
        self.addCleanup(self.temporary.cleanup)
        self.root = pathlib.Path(self.temporary.name).resolve()
        self.repo = self.root/'checkout'
        self.repo.mkdir()
        self.git('init', '--quiet')
        self.git('config', 'user.email', 'fixture@example.test')
        self.git('config', 'user.name', 'Snapshot fixture')
        self.git('config', 'core.autocrlf', 'false')
        tree = ast.parse(SCRIPT.read_text(encoding='utf-8'))
        overlays = next(ast.literal_eval(node.value) for node in tree.body
                        if isinstance(node, ast.Assign)
                        and any(isinstance(target, ast.Name) and target.id == 'overlays'
                                for target in node.targets))
        for item in [*overlays, 'package.json', 'packages/theme-sdk/package.json', 'legacy.txt']:
            path = self.repo/item
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('fixture\n', encoding='utf-8')
        self.git('add', '.')
        self.git('commit', '--quiet', '-m', 'Legacy Gateway tree')
        self.legacy = self.git('rev-parse', 'HEAD').strip()
        self.gateway = self.repo/'gateway'
        self.gateway.mkdir()
        for path in list(self.repo.iterdir()):
            if path.name not in ('.git', 'gateway'):
                shutil.move(str(path), self.gateway/path.name)
        (self.repo/'api').mkdir()
        (self.repo/'api/core-only.txt').write_text('must not enter Gateway\n', encoding='utf-8')
        self.git('add', '.')
        self.git('commit', '--quiet', '-m', 'Import Gateway below monorepo')
        self.head = self.git('rev-parse', 'HEAD').strip()
        self.dependencies = self.root/'dependencies'
        (self.dependencies/'node_modules').mkdir(parents=True)
        (self.dependencies/'node_modules/fixture.txt').write_text('dependency\n', encoding='utf-8')

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.repo), *args], text=True,
                                       stderr=subprocess.STDOUT)

    def snapshot(self, name, *args, succeeds=True):
        target = self.root/name
        result = subprocess.run([sys.executable, str(SCRIPT), str(self.gateway),
                                 str(target), str(self.dependencies), *args],
                                text=True, capture_output=True)
        self.assertEqual(result.returncode == 0, succeeds, result.stderr)
        return target

    def test_monorepo_default_archives_gateway_only_and_records_tree_identity(self):
        target = self.snapshot('mono')
        self.assertTrue((target/'packages/theme-sdk/package.json').is_file())
        self.assertFalse((target/'gateway').exists())
        self.assertFalse((target/'api').exists())
        self.assertEqual((target/'node_modules/fixture.txt').read_text(), 'dependency\n')
        metadata = json.loads((target/'internal-user-snapshot.json').read_text())
        self.assertEqual(metadata['committed_gateway_head'], self.head)
        self.assertEqual(metadata['gateway_source_prefix'], 'gateway')
        self.assertEqual(metadata['committed_gateway_tree'], self.git('rev-parse', 'HEAD:gateway').strip())

    def test_explicit_legacy_prefix_preserves_original_commit(self):
        target = self.snapshot('legacy', self.legacy, '')
        metadata = json.loads((target/'internal-user-snapshot.json').read_text())
        self.assertEqual(metadata['committed_gateway_head'], self.legacy)
        self.assertEqual(metadata['gateway_source_prefix'], '')
        self.assertEqual(metadata['committed_gateway_tree'], self.git('rev-parse', f'{self.legacy}^{{tree}}').strip())
        self.assertTrue((target/'legacy.txt').is_file())
        self.assertFalse((target/'api').exists())

    def test_wrong_baseline_prefix_fails_before_creating_snapshot(self):
        target = self.snapshot('wrong-prefix', self.legacy, succeeds=False)
        self.assertFalse(target.exists())

    def test_unsafe_or_unknown_prefix_is_rejected_before_snapshot(self):
        target = self.snapshot('unsafe', self.head, '../api', succeeds=False)
        self.assertFalse(target.exists())


if __name__ == '__main__':
    unittest.main()
