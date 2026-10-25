import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("ci_policy", ROOT / "scripts/check-ci-policy.py")
policy = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(policy)


class PolicyTests(unittest.TestCase):
    def setUp(self):
        self.workflow = policy.validate_workflow(ROOT / ".github/workflows/ci.yml")

    def test_current_consolidated_ci_preserves_coverage(self):
        policy.validate_ci(self.workflow)

    def test_removing_a_database_or_integration_job_is_rejected(self):
        for name in ("native-billing-domain", "browser-native-http", "native-c2-live", "native-node-connections"):
            with self.subTest(job=name):
                changed = copy.deepcopy(self.workflow)
                del changed["jobs"][name]
                with self.assertRaises(ValueError):
                    policy.validate_ci(changed)

    def test_aggregate_cannot_skip_after_failure_or_omit_matrix_jobs(self):
        for key, value in (("if", "success()"), ("needs", ["changes", "static", "api"])):
            with self.subTest(key=key):
                changed = copy.deepcopy(self.workflow)
                changed["jobs"]["acceptance"][key] = value
                with self.assertRaises(ValueError):
                    policy.validate_ci(changed)

    def test_documentation_cannot_be_gated_by_source_selection(self):
        self.workflow["jobs"]["static"]["if"] = "needs.changes.outputs.api == 'true'"
        with self.assertRaises(ValueError):
            policy.validate_ci(self.workflow)

    def test_workflow_path_filters_cannot_hide_required_ci_status(self):
        self.workflow["on"]["pull_request"] = {"paths": ["api/**"]}
        with self.assertRaises(ValueError):
            policy.validate_ci(self.workflow)

    def test_wrong_job_selection_group_is_rejected(self):
        self.workflow["jobs"]["native-billing-domain"]["if"] = "needs.changes.outputs.web == 'true'"
        with self.assertRaises(ValueError):
            policy.validate_ci(self.workflow)

    def test_documentation_push_cannot_cancel_previous_main_acceptance(self):
        self.workflow["concurrency"] = {"group": "ci-${{ github.ref }}", "cancel-in-progress": True}
        with self.assertRaises(ValueError):
            policy.validate_ci(self.workflow)

    def test_duplicate_yaml_keys_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "ci.yml"
            path.write_text("name: CI\nname: Other\n", encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "Duplicate YAML key"):
                policy.validate_workflow(path)

    def test_dependency_cycle_is_rejected(self):
        workflow = copy.deepcopy(self.workflow)
        workflow["jobs"]["changes"]["needs"] = "acceptance"
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "ci.yml"
            path.write_text(policy.yaml.safe_dump(workflow), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "dependency cycle"):
                policy.validate_workflow(path)

    def aggregate(self, replacements):
        results = {name: {"result": "skipped"} for name in self.workflow["jobs"] if name != "acceptance"}
        results["changes"]["result"] = "success"
        results["static"]["result"] = "success"
        for name, result in replacements.items():
            results[name]["result"] = result
        command = self.workflow["jobs"]["acceptance"]["steps"][0]["run"]
        source = "\n".join(command.splitlines()[1:-1])
        return subprocess.run([sys.executable, "-c", source],
                              env={**os.environ, "RESULTS": json.dumps(results)},
                              capture_output=True, text=True)

    def test_aggregate_accepts_intentionally_skipped_component_checks(self):
        self.assertEqual(self.aggregate({}).returncode, 0)

    def test_aggregate_rejects_failed_or_cancelled_component_checks(self):
        for result in ("failure", "cancelled"):
            with self.subTest(result=result):
                self.assertNotEqual(self.aggregate({"native-billing-domain": result}).returncode, 0)
                self.assertNotEqual(self.aggregate({"native-node-connections": result}).returncode, 0)

    def test_connection_gate_cannot_drop_the_explicit_core_candidate(self):
        changed = copy.deepcopy(self.workflow)
        for step in changed['jobs']['native-node-connections']['steps']:
            if 'run' in step:
                step['run'] = step['run'].replace('--candidate-core-sha "$CORE_CANDIDATE_SHA"', '')
        with self.assertRaises(ValueError):
            policy.validate_ci(changed)

    def test_aggregate_requires_successful_selection_and_static_checks(self):
        for name in ("changes", "static"):
            with self.subTest(job=name):
                self.assertNotEqual(self.aggregate({name: "skipped"}).returncode, 0)


if __name__ == "__main__":
    unittest.main()
