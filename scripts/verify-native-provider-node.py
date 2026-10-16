#!/usr/bin/env python3
"""Actual Core HTTP, Provider mTLS and Node Service/VLESS in a private fixture."""
import argparse
import base64
import hashlib
import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import socket
import ssl
import subprocess
import tempfile
import threading
import time


def free_port():
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        return listener.getsockname()[1]


def main():
    repo = Path(__file__).resolve().parent.parent
    parser = argparse.ArgumentParser()
    parser.add_argument("--api-root", type=Path, default=repo / "api")
    parser.add_argument("--provider-binary", type=Path)
    args = parser.parse_args()
    if os.name != "posix":
        raise RuntimeError("The private Provider fixture requires POSIX permissions")
    scripts = repo / "scripts/tests/native-provider-node"
    with tempfile.TemporaryDirectory(prefix="tx-native-provider-node-") as temporary:
        root = Path(temporary)
        os.chmod(root, 0o700)
        (root / "core.sqlite").touch(mode=0o600)
        ports = {}
        while len(set(ports.values())) < 6:
            ports = {key: free_port() for key in ("provider", "core", "https", "node", "secondary", "origin")}
        provider_origin = f"https://127.0.0.1:{ports['provider']}"
        core_origin = f"https://127.0.0.1:{ports['https']}"

        def openssl(*arguments):
            result = subprocess.run(["openssl", *arguments], cwd=root, capture_output=True)
            if result.returncode:
                raise RuntimeError("Temporary TLS fixture creation failed")
            return result.stdout

        openssl("req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                "-keyout", "ca.key", "-out", "ca.pem", "-subj", "/CN=provider-node-fixture-ca")
        for name, role in (("server", "serverAuth"), ("core", "clientAuth"), ("node", "clientAuth")):
            openssl("req", "-new", "-newkey", "rsa:2048", "-nodes", "-keyout", name + ".key",
                    "-out", name + ".csr", "-subj", "/CN=" + name)
            extension = "extendedKeyUsage=" + role + "\n"
            if role == "serverAuth":
                extension += "subjectAltName=IP:127.0.0.1,DNS:localhost\n"
            (root / (name + ".ext")).write_text(extension)
            openssl("x509", "-req", "-days", "1", "-in", name + ".csr", "-CA", "ca.pem",
                    "-CAkey", "ca.key", "-CAcreateserial", "-out", name + ".pem",
                    "-extfile", name + ".ext")
        for path in root.glob("*.key"):
            os.chmod(path, 0o600)
        fingerprint = {
            name: hashlib.sha256(openssl("x509", "-in", name + ".pem", "-outform", "DER")).hexdigest()
            for name in ("core", "node")
        }
        env = dict(os.environ, APP_ENV="testing", APP_DEBUG="false",
                   APP_KEY="base64:ZdD07lazsdQQmdBAJ2tA5WjufeI2hP8xlP6zgmvCMZs=",
                   APP_CONFIG_CACHE=str(root / "config.php"),
                   DB_CONNECTION="sqlite", DB_DATABASE=str(root / "core.sqlite"),
                   CACHE_DRIVER="array", SETTING_CACHE_STORE="array", QUEUE_CONNECTION="sync",
                   SESSION_DRIVER="array", CORE_INSTALLATION_MODE="legacy",
                   CORE_INSTALLATION_EPOCH="1", NATIVE_TRAFFIC_RECEIPTS_ENABLED="false",
                   NATIVE_PROVIDER_NODE_DIR=str(root), NATIVE_PROVIDER_NODE_API=str(args.api_root.resolve()),
                   NATIVE_PROVIDER_NODE_PROVIDER=provider_origin, NATIVE_PROVIDER_NODE_FINGERPRINT=fingerprint["node"],
                   NATIVE_PROVIDER_NODE_PORT=str(ports["node"]), NATIVE_PROVIDER_NODE_ORIGIN_PORT=str(ports["origin"]),
                   NATIVE_PROVIDER_NODE_SECONDARY_PORT=str(ports["secondary"]),
                   NATIVE_PROVIDER_NODE_FIXTURE=str(scripts / "fixture.php"),
                   NATIVE_NODE_AUTHORIZATION_PRIVATE_SEED=base64.urlsafe_b64encode(os.urandom(32)).decode().rstrip("="),
                   SSL_CERT_FILE=str(root / "ca.pem"), GOFLAGS="-buildvcs=false")

        def fixture(mode):
            result = subprocess.run(["php", str(scripts / "fixture.php"), mode],
                                    cwd=args.api_root, env=env, capture_output=True, text=True, timeout=90)
            if result.returncode:
                raise RuntimeError("Core Provider fixture failed at " + mode + ": " + result.stderr[-1000:])
            return result.stdout

        trust = json.loads(fixture("trust"))
        (root / "trust.json").write_text(json.dumps(trust))
        provider = args.provider_binary or root / "credential-provider"
        if args.provider_binary is None:
            subprocess.run(["go", "build", "-buildvcs=false", "-o", str(provider),
                            "./cmd/credential-provider"], cwd=repo / "node", env=env, check=True)
        private = root / "provider-private"
        private.mkdir(mode=0o700)
        provider_log = (root / "provider.log").open("w")
        core_log = (root / "core.log").open("w")
        processes = []
        proxy = None
        try:
            processes.append(subprocess.Popen([
                str(provider), "--listen", f"127.0.0.1:{ports['provider']}",
                "--store", str(private / "provider.db"), "--provider-id", "primary-provider", "--epoch", "1",
                "--tls-cert", str(root / "server.pem"), "--tls-key", str(root / "server.key"),
                "--client-ca", str(root / "ca.pem"), "--client-fingerprint", fingerprint["core"],
                "--delivery-trust", str(root / "trust.json"), "--node-client-fingerprint", fingerprint["node"],
            ], stdin=subprocess.DEVNULL, stdout=provider_log, stderr=subprocess.STDOUT))
            for _ in range(100):
                if processes[0].poll() is not None:
                    raise RuntimeError("Provider startup failed")
                try:
                    with socket.create_connection(("127.0.0.1", ports["provider"]), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.1)
            else:
                raise RuntimeError("Provider startup timed out")
            print(fixture("seed").strip(), flush=True)
            env["CORE_INSTALLATION_MODE"] = "native"
            processes.append(subprocess.Popen([
                "php", "-S", f"127.0.0.1:{ports['core']}", str(scripts / "router.php"),
            ], cwd=args.api_root, env=env, stdin=subprocess.DEVNULL,
                stdout=core_log, stderr=subprocess.STDOUT))
            for _ in range(50):
                if processes[-1].poll() is not None:
                    raise RuntimeError("Core HTTP process failed during startup")
                try:
                    with socket.create_connection(("127.0.0.1", ports["core"]), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.1)
            else:
                raise RuntimeError("Core HTTP startup timed out")

            class Handler(BaseHTTPRequestHandler):
                def log_message(self, *arguments):
                    pass

                def handle_core(self):
                    if (root / "offline").exists() and self.headers.get("X-TX-Node-ID") == "1":
                        self.send_error(503)
                        return
                    if not self.path.startswith("/txapi/node/v1/"):
                        self.send_error(404)
                        return
                    length = int(self.headers.get("Content-Length", "0"))
                    if length < 0 or length > 1048576:
                        self.send_error(413)
                        return
                    body = self.rfile.read(length) if length else None
                    headers = dict(self.headers)
                    headers["Connection"] = "close"
                    upstream = http.client.HTTPConnection("127.0.0.1", ports["core"], timeout=15)
                    try:
                        upstream.request(self.command, self.path, body, headers)
                        response = upstream.getresponse()
                        content = response.read(1048577)
                        if len(content) > 1048576:
                            raise RuntimeError("Core response too large")
                        self.send_response(response.status)
                        for key, value in response.getheaders():
                            if key.lower() not in ("connection", "transfer-encoding", "content-length"):
                                self.send_header(key, value)
                        self.send_header("Content-Length", str(len(content)))
                        self.end_headers()
                        self.wfile.write(content)
                    except (OSError, http.client.HTTPException):
                        self.send_error(503)
                    finally:
                        upstream.close()

                do_GET = handle_core
                do_POST = handle_core

            proxy = ThreadingHTTPServer(("127.0.0.1", ports["https"]), Handler)
            context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
            context.minimum_version = ssl.TLSVersion.TLSv1_3
            context.load_cert_chain(str(root / "server.pem"), str(root / "server.key"))
            proxy.socket = context.wrap_socket(proxy.socket, server_side=True)
            threading.Thread(target=proxy.serve_forever, daemon=True).start()
            fixture_state = json.loads((root / "fixture.json").read_text())
            auth = {
                "enabled": True, "state_dir": str(root / "node-state/authorization"),
                "core_instance_id": trust["core_instance_id"], "key_id": trust["key_id"],
                "key_epoch": trust["key_epoch"], "public_key": trust["public_key"],
                "policy_version": "native-provider-authorization/v1", "refresh_interval": 5, "max_clock_skew": 1,
                "provider": {
                    "endpoint": provider_origin, "provider_id": "primary-provider", "provider_instance_epoch": "1",
                    "ca_file": str(root / "ca.pem"), "client_cert": str(root / "node.pem"),
                    "client_key": str(root / "node.key"),
                },
            }
            node_config = {
                "panel": {"provider": "txboard", "url": core_origin, "node_id": fixture_state["node_id"],
                          "token": fixture_state["token"]},
                "kernel": {"type": "singbox", "config_dir": str(root / "node-state"), "log_level": "error"},
                "cert": {"cert_mode": "none"},
                "node": {"track_interval": 1, "push_interval": 5, "pull_interval": 60},
                "authorization": auth,
            }
            (root / "node.json").write_text(json.dumps(node_config))
            node_config["panel"]["node_id"] = fixture_state["secondary_node_id"]
            node_config["kernel"]["config_dir"] = str(root / "node-secondary-state")
            node_config["authorization"]["state_dir"] = str(root / "node-secondary-state/authorization")
            (root / "node-secondary.json").write_text(json.dumps(node_config))
            subprocess.run([
                "go", "test", "-race", "-count=1", "-timeout=180s",
                "-run", "^TestNativeProviderCoreMaterialAndRealVLESS$", "./internal/service",
            ], cwd=repo / "node", env=env, check=True, timeout=600)
            print(fixture("verify").strip(), flush=True)
            print("Real Provider Node consumption passed; private material and fixture removed on exit.", flush=True)
        finally:
            if proxy is not None:
                proxy.shutdown()
                proxy.server_close()
            for process in reversed(processes):
                process.terminate()
                try:
                    process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)
            provider_log.close()
            core_log.close()


if __name__ == "__main__":
    main()
