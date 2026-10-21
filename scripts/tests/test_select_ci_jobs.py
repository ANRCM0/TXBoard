import importlib.util
import json
from pathlib import Path
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("ci_selection", ROOT / "scripts/select-ci-jobs.py")
selection = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(selection)


class SelectionTests(unittest.TestCase):
    def setUp(self):
        self.filters = json.loads((ROOT / ".github/ci-paths.json").read_text())

    def test_documentation_changes_skip_expensive_jobs(self):
        selected = selection.select(["README.md", "api/README.md", "web/admin/README.md"], self.filters)
        self.assertFalse(any(selected.values()))

    def test_web_changes_retain_actual_core_browser_acceptance(self):
        selected = selection.select(["web/admin/src/native/NodesPage.tsx"], self.filters)
        self.assertEqual({group for group, enabled in selected.items() if enabled}, {"web", "browser"})

    def test_billing_changes_require_mysql_and_native_integration(self):
        selected = selection.select(["api/app/Domains/Billing/Internal/Settlement.php"], self.filters)
        self.assertEqual({group for group, enabled in selected.items() if enabled},
                         {"api", "mysql", "browser", "native"})

    def test_node_pin_requires_both_cross_component_checks(self):
        selected = selection.select(["node"], self.filters)
        self.assertTrue(all(selected[group] for group in ("api", "browser", "native")))

    def test_workflow_and_selector_changes_require_full_acceptance(self):
        for path in (".github/workflows/ci.yml", ".github/ci-paths.json", "scripts/select-ci-jobs.py"):
            with self.subTest(path=path):
                self.assertTrue(all(selection.select([path], self.filters).values()))

    def test_complete_diff_has_no_300_file_cutoff(self):
        paths = [f"docs/reference-{number}.md" for number in range(1000)]
        paths.append("api/app/Domains/Billing/Internal/Settlement.php")
        self.assertTrue(selection.select(paths, self.filters)["mysql"])

    def test_ordered_negative_patterns_can_be_overridden(self):
        self.assertTrue(selection.included("docs/required.md", ["**", "!**/*.md", "docs/required.md"]))
        self.assertFalse(selection.included("docs/other.md", ["**", "!**/*.md", "docs/required.md"]))

    def test_globs_respect_components_and_zero_directory_globstar(self):
        self.assertTrue(selection.matches("api/README.md", "api/**/*.md"))
        self.assertTrue(selection.matches("api/deep/README.md", "api/**/*.md"))
        self.assertFalse(selection.matches("api/deep/read.php", "api/*.php"))

    def test_manual_unknown_and_missing_base_select_everything(self):
        for event_name, event in (("workflow_dispatch", {}), ("push", {"before": "0" * 40}),
                                  ("pull_request", {}), ("unknown", {})):
            with self.subTest(event_name=event_name):
                self.assertTrue(all(selection.select(selection.changed_paths(event_name, event), self.filters).values()))

    def test_pull_request_uses_merge_base_and_includes_deleted_paths(self):
        before, after = "a" * 40, "b" * 40
        event = {"pull_request": {"base": {"sha": before}, "head": {"sha": after}}}
        calls = []

        def run(arguments):
            calls.append(arguments)
            return b"api/deleted.php\0web/new.tsx\0"

        self.assertEqual(selection.changed_paths("pull_request", event, run), ["api/deleted.php", "web/new.tsx"])
        self.assertIn(before + "..." + after, calls[0])
        self.assertIn("--no-renames", calls[0])

    def test_force_push_with_unavailable_base_falls_back_to_all(self):
        def fail(arguments):
            raise subprocess.CalledProcessError(128, arguments)

        paths = selection.changed_paths("push", {"before": "a" * 40, "after": "b" * 40}, fail)
        self.assertTrue(all(selection.select(paths, self.filters).values()))

    def test_invalid_revisions_never_reach_git(self):
        def fail(arguments):
            self.fail("Unvalidated revision reached git")

        self.assertIsNone(selection.changed_paths("push", {"before": "--help", "after": "b" * 40}, fail))


if __name__ == "__main__":
    unittest.main()
