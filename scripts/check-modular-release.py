#!/usr/bin/env python3
"""Static consistency of the fixed cross-repository composition, not runtime acceptance."""
import json
from pathlib import Path
import re
import subprocess

root = Path(__file__).resolve().parents[1]
release = json.loads((root / "contracts/releases/modular-core.v1.json").read_text(encoding="utf-8"))
assert release["schema"] == "modular-core-release/v1"
assert re.fullmatch(r"[0-9a-f]{40}", release["deploy"]["revision"])
assert release["node"]["repository"] == "ANRCM0/txnode"
assert release["node"]["path"] == "node"
assert re.fullmatch(r"160000 [0-9a-f]{40} 0\tnode", subprocess.check_output(
    ["git", "-C", str(root), "ls-files", "--stage", "--", "node"], text=True).strip())
for key in ("kernel", "module_manifest", "version_policy", "operations", "default_composition", "proof_composition", "native_preparation"):
    path = root / release[key]
    assert path.is_file() and path.resolve().is_relative_to(root.resolve()), key
workflow = (root / release["image_proof"]["workflow"]).read_text(encoding="utf-8")
assert "repository: " + release["deploy"]["repository"] in workflow
assert "ref: " + release["deploy"]["revision"] in workflow
assert (root / release["image_proof"]["runner"]).is_file()
policy = json.loads((root / release["version_policy"]).read_text(encoding="utf-8"))
kernel = json.loads((root / release["kernel"]).read_text(encoding="utf-8"))
assert policy["kernel_api"]["frozen_files"] == release["kernel"]
assert kernel["stability"] in ("candidate", "stable")
assert policy["status"] == release["kernel_stability"] == kernel["stability"]
print("Modular release: fixed Deploy revision, Kernel stability and artifacts match")
