#!/usr/bin/env python3
"""Run Core against the real Provider using disposable, mutually authenticated TLS."""
import argparse
import hashlib
import os
from pathlib import Path
import socket
import ssl
import subprocess
import tempfile
import time


def main():
    repo = Path(__file__).resolve().parent.parent
    parser = argparse.ArgumentParser()
    parser.add_argument("--api-root", type=Path, default=repo / "api")
    parser.add_argument("--provider-binary", type=Path)
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix="tx-native-provider-interop-") as temporary:
        root = Path(temporary)
        os.chmod(root, 0o700)
        provider = args.provider_binary or root / "credential-provider"
        if args.provider_binary is None:
            subprocess.run(["go", "build", "-buildvcs=false", "-o", str(provider),
                            "./cmd/credential-provider"], cwd=repo / "node", check=True)

        def openssl(*arguments):
            result = subprocess.run(["openssl", *arguments], cwd=root, capture_output=True)
            if result.returncode:
                raise RuntimeError("Temporary TLS fixture generation failed")
            return result.stdout

        for name in ("ca", "untrusted-ca"):
            openssl("req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                    "-keyout", name + ".key", "-out", name + ".pem", "-subj", "/CN=" + name)
        for name, role in (("server", "serverAuth"), ("core", "clientAuth"), ("other-core", "clientAuth")):
            openssl("req", "-new", "-newkey", "rsa:2048", "-nodes", "-keyout", name + ".key",
                    "-out", name + ".csr", "-subj", "/CN=" + name)
            extensions = "extendedKeyUsage=" + role + "\n"
            if role == "serverAuth":
                extensions += "subjectAltName=IP:127.0.0.1,DNS:localhost\n"
            (root / (name + ".ext")).write_text(extensions)
            openssl("x509", "-req", "-days", "1", "-in", name + ".csr", "-CA", "ca.pem",
                    "-CAkey", "ca.key", "-CAcreateserial", "-out", name + ".pem",
                    "-extfile", name + ".ext")
        for key in root.glob("*.key"):
            os.chmod(key, 0o600)
        fingerprint = hashlib.sha256(openssl("x509", "-in", "core.pem", "-outform", "DER")).hexdigest()
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0))
            port = listener.getsockname()[1]
        private = root / "private"
        private.mkdir(mode=0o700)
        server_log = (root / "provider.log").open("w")
        server = subprocess.Popen([
            str(provider), "--listen", f"127.0.0.1:{port}", "--store", str(private / "provider.db"),
            "--provider-id", "primary-provider", "--epoch", "1",
            "--tls-cert", str(root / "server.pem"), "--tls-key", str(root / "server.key"),
            "--client-ca", str(root / "ca.pem"), "--client-fingerprint", fingerprint,
        ], stdin=subprocess.DEVNULL, stdout=server_log, stderr=subprocess.STDOUT)
        try:
            context = ssl.create_default_context(cafile=str(root / "ca.pem"))
            context.minimum_version = ssl.TLSVersion.TLSv1_3
            context.load_cert_chain(str(root / "core.pem"), str(root / "core.key"))
            for _ in range(100):
                if server.poll() is not None:
                    raise RuntimeError("Provider process failed during startup")
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=0.2) as raw:
                        with context.wrap_socket(raw, server_hostname="127.0.0.1"):
                            break
                except (OSError, ssl.SSLError):
                    time.sleep(0.1)
            else:
                raise RuntimeError("Provider TLS readiness timed out")
            env = dict(os.environ, APP_ENV="testing", DB_CONNECTION="sqlite", DB_DATABASE=":memory:",
                       APP_KEY="base64:ZdD07lazsdQQmdBAJ2tA5WjufeI2hP8xlP6zgmvCMZs=",
                       APP_CONFIG_CACHE=str(root / "config.php"), CACHE_DRIVER="array",
                       SETTING_CACHE_STORE="array", QUEUE_CONNECTION="sync", SESSION_DRIVER="array",
                       CORE_INSTALLATION_MODE="legacy", CORE_INSTALLATION_EPOCH="1",
                       CORE_PROVIDER_INTEROP_ENDPOINT=f"https://127.0.0.1:{port}/operations",
                       CORE_PROVIDER_INTEROP_CA=str(root / "ca.pem"),
                       CORE_PROVIDER_INTEROP_CERT=str(root / "core.pem"),
                       CORE_PROVIDER_INTEROP_KEY=str(root / "core.key"),
                       CORE_PROVIDER_INTEROP_ALT_CERT=str(root / "other-core.pem"),
                       CORE_PROVIDER_INTEROP_ALT_KEY=str(root / "other-core.key"),
                       CORE_PROVIDER_INTEROP_BAD_CA=str(root / "untrusted-ca.pem"))
            subprocess.run([
                "php", "vendor/phpunit/phpunit/phpunit", "--colors=never",
                "tests/Feature/Subscription/NativeCredentialProviderInteropTest.php",
                "tests/Feature/Subscription/NativeProviderTransportInteropTest.php",
            ], cwd=args.api_root.resolve(), env=env, check=True, timeout=120)
            print("Real Provider mTLS interop passed; disposable private credential store removed on exit.")
        finally:
            server.terminate()
            try:
                server.wait(timeout=10)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
            server_log.close()


if __name__ == "__main__":
    main()
