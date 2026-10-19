"""Actions acceptance of the developer runner with real PHP/PHPUnit processes."""
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('module_dev', ROOT / 'scripts/module-dev.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ModuleRunnerIntegrationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if shutil.which('php') is None or not (ROOT / 'api/vendor/bin/phpunit').is_file():
            raise RuntimeError('Real PHP and locked API dependencies are required for runner acceptance')

    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='module-runner-acceptance-')
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.package = self.root / 'Example'
        module.scaffold(self.package, 'example')
        self.report = self.root / 'result.json'

    def run_module(self):
        return subprocess.run([sys.executable, str(ROOT / 'scripts/txboard-dev.py'), 'module', 'test',
            str(self.package), '--application-root', str(ROOT / 'api'), '--report', str(self.report)],
            capture_output=True, text=True, check=False, timeout=60)

    def test_real_module_contract_and_isolated_environment_produce_bound_pending_report(self):
        (self.package / 'tests/VersionTest.php').write_text(r'''<?php
require_once dirname(__DIR__).'/Plugin.php';
final class VersionTest extends \PHPUnit\Framework\TestCase
{
    public function test_real_scaffold_handles_only_the_declared_operation(): void
    {
        $plugin = new \Plugin\Example\Plugin('example');
        $context = new \App\Core\Kernel\Execution\InvocationContext(
            '11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', 'cli');
        $request = new \App\Extensions\Contracts\ExtensionRequest('example', 'version.get', 1, 'user', [], null, $context);
        $this->assertSame(['version' => '1.0.0'], $plugin->handle($request)->data);
        $this->assertSame('sqlite', getenv('DB_CONNECTION'));
        $this->assertSame(':memory:', getenv('DB_DATABASE'));
        $this->assertSame('0', getenv('CORE_INSTALLATION_TEST_MYSQL'));
        $this->assertSame('', getenv('DB_SOCKET'));
        $this->expectException(\App\Extensions\Contracts\ExtensionFailure::class);
        $plugin->handle(new \App\Extensions\Contracts\ExtensionRequest('example', 'missing.get', 1, 'user', [], null, $context));
    }
}
''', encoding='utf-8')
        run = self.run_module()
        self.assertEqual(0, run.returncode, run.stdout + run.stderr)
        result = json.loads(self.report.read_text(encoding='utf-8'))
        self.assertEqual('passed', result['status'])
        self.assertEqual('pending', result['acceptance'])
        self.assertTrue(result['executed'])
        self.assertEqual(module.test_plan(self.package, ROOT / 'api')['source_sha256'], result['source_sha256'])
        (self.package / 'tests/ZFailingTest.php').write_text(r'''<?php
final class ZFailingTest extends \PHPUnit\Framework\TestCase
{
    public function test_second_file_must_also_execute(): void { $this->fail('second file failure'); }
}
''', encoding='utf-8')
        self.report = self.root / 'failed-result.json'
        self.assertNotEqual(0, self.run_module().returncode)
        self.assertEqual('failed', json.loads(self.report.read_text(encoding='utf-8'))['status'])

    def test_real_unimplemented_acceptance_template_fails_instead_of_attesting_success(self):
        template = self.package / 'tests/OwnerBoundaryTest.php.dist'
        template.rename(template.with_suffix(''))
        run = self.run_module()
        self.assertNotEqual(0, run.returncode)
        result = json.loads(self.report.read_text(encoding='utf-8'))
        self.assertEqual('failed', result['status'])
        self.assertEqual('phpunit', result['stage'])
        self.assertEqual('pending', result['acceptance'])

    def test_real_syntax_failure_stops_before_phpunit(self):
        (self.package / 'tests/BrokenTest.php').write_text('<?php function {', encoding='utf-8')
        run = self.run_module()
        self.assertNotEqual(0, run.returncode)
        result = json.loads(self.report.read_text(encoding='utf-8'))
        self.assertEqual('failed', result['status'])
        self.assertEqual('lint', result['stage'])


if __name__ == '__main__':
    unittest.main()
