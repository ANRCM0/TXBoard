#!/usr/bin/env python3
"""Verify Core's exact external Node pin and optionally archive that source."""
import argparse
import json
from pathlib import Path
import re
import subprocess


ROOT = Path(__file__).resolve().parents[1]
REPOSITORY = 'https://github.com/ANRCM0/txnode.git'


def git(root, *arguments):
    return subprocess.check_output(['git', '-C', str(root), *arguments], text=True).strip()


def verify(root=ROOT):
    entry = git(root, 'ls-files', '--stage', '--', 'node')
    match = re.fullmatch(r'160000 ([0-9a-f]{40}) 0\tnode', entry)
    if not match:
        raise ValueError('Node must be an independently versioned Git submodule')
    revision = match[1]
    if git(root, 'config', '-f', '.gitmodules', '--get', 'submodule.node.url') != REPOSITORY:
        raise ValueError('Unexpected Node source repository')
    if git(root, 'config', '-f', '.gitmodules', '--get', 'submodule.node.path') != 'node':
        raise ValueError('Unexpected Node integration path')
    node = root / 'node'
    if not (node / '.git').exists():
        raise ValueError('Initialize pinned Node first: git submodule update --init node')
    if git(node, 'rev-parse', 'HEAD') != revision:
        raise ValueError('Node checkout differs from the recorded Core pin')
    if git(node, 'status', '--porcelain', '--untracked-files=no'):
        raise ValueError('Node source has uncommitted changes')
    return {'repository': 'ANRCM0/txnode', 'revision': revision,
            'tree': git(node, 'rev-parse', 'HEAD^{tree}')}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--archive', type=Path, help='Export exactly the verified Node commit as a tar file')
    args = parser.parse_args()
    proof = verify()
    if args.archive:
        subprocess.run(['git', '-C', str(ROOT / 'node'), 'archive', '--format=tar',
                        '--output', str(args.archive.resolve()), proof['revision']], check=True)
    print(json.dumps(proof))


if __name__ == '__main__':
    main()
