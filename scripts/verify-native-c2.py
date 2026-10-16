#!/usr/bin/env python3
"""Remote-only real native activation, Provider, client, Node and settlement."""
import importlib.util
from pathlib import Path


if __name__ == "__main__":
    path = Path(__file__).with_name("verify-native-provider-node.py")
    specification = importlib.util.spec_from_file_location("native_provider_node_runner", path)
    runner = importlib.util.module_from_spec(specification)
    specification.loader.exec_module(runner)
    runner.main(closeout=True)
