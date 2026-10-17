#!/usr/bin/env python3
"""Actual Deploy installer + three immutable Core images + MySQL + Go Provider.

Uses fresh CI-owned resources only. No production fixtures, restore or image
downgrade. The generated second upgrade adds a module-owned table, then loses
the executor before composition adoption and proves original-intent recovery.
"""
import argparse
import base64
import hashlib
import importlib.util
import json
import os
import re
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request
import urllib.error
import uuid

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True


def require(value, message):
    if not value:
        raise RuntimeError(message)


def run(arguments, cwd=None, env=None, timeout=600):
    result = subprocess.run([str(value) for value in arguments], cwd=cwd, env=env, stdin=subprocess.DEVNULL,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=timeout)
    codes = re.findall(r'"error_code"\s*:\s*"([A-Z][A-Z0-9_]{1,160})"', result.stdout + result.stderr)
    require(result.returncode == 0, "Native image fixture command failed: " + str(arguments[0]) + " (exit " + str(result.returncode) + ") codes=" + repr(codes) + " " + result.stderr[-1200:])
    return result.stdout


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


def port():
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        return listener.getsockname()[1]


def write(path, value):
    path.write_text(json.dumps(value, indent=2) + "\n", encoding="utf-8")
    path.chmod(0o600)


def purchase_diagnostic(installation, email, offer_id, key):
    """Only rerun the original CI-owned purchase key; emit no SQL, arguments or values."""
    payload = base64.b64encode(json.dumps({"email": email, "offer_id": offer_id, "key": key}).encode()).decode()
    source = r'''<?php
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(base64_decode('__PAYLOAD__'), true, 16, JSON_THROW_ON_ERROR);
try {
    $user = App\Models\User::query()->where('email', $input['email'])->firstOrFail();
    $result = app(App\Domains\Subscription\Contracts\Purchases::class)->purchase((int) $user->id, (int) $input['offer_id'], $input['key']);
    echo json_encode(['owner_status' => 'succeeded']);
} catch (Throwable $error) {
    $code = preg_match('/\A[A-Z][A-Z0-9_]{1,100}\z/D', $error->getMessage()) === 1 ? $error->getMessage() : null;
    $frames = [];
    foreach ([['file' => $error->getFile(), 'line' => $error->getLine()], ...$error->getTrace()] as $frame) {
        if (preg_match('#\A/www/(app/[A-Za-z0-9_./]+\.php)\z#D', $frame['file'] ?? '', $match) === 1) {
            $frames[] = [$match[1], (int) ($frame['line'] ?? 0)];
        }
    }
    $state = preg_match('/\A[A-Z0-9]{5}\z/D', (string) $error->getCode()) === 1 ? (string) $error->getCode() : null;
    $driver = $error instanceof Illuminate\Database\QueryException ? ($error->errorInfo[1] ?? null) : null;
    echo json_encode(['owner_status' => 'failed', 'class' => get_class($error), 'code' => $code,
        'sqlstate' => $state, 'driver_code' => is_int($driver) ? $driver : null, 'frames' => array_slice($frames, 0, 8)]);
}
'''.replace('__PAYLOAD__', payload)
    result = subprocess.run(["docker", "compose", "exec", "-T", "txboard", "php"], cwd=installation,
                            input=source, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=45)
    try:
        diagnostic = json.loads(result.stdout)
        if isinstance(diagnostic, dict) and diagnostic.get('owner_status') in ('succeeded', 'failed'):
            print(json.dumps({"purchase_diagnostic": diagnostic}), flush=True)
    except ValueError:
        print('{"purchase_diagnostic":{"owner_status":"unavailable"}}', flush=True)


def tls(root, gateway):
    def openssl(*arguments):
        return run(["openssl", *arguments], cwd=root)
    openssl("req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-keyout", "ca.key", "-out", "ca.pem", "-subj", "/CN=native-image-fixture-ca")
    fingerprints = {}
    for name, role in (("server", "serverAuth"), ("core", "clientAuth"), ("node", "clientAuth")):
        openssl("req", "-new", "-newkey", "rsa:2048", "-nodes", "-keyout", name + ".key", "-out", name + ".csr", "-subj", "/CN=" + name)
        extension = "extendedKeyUsage=" + role + "\n"
        if role == "serverAuth":
            extension += "subjectAltName=IP:127.0.0.1,IP:" + gateway + "\n"
        (root / (name + ".ext")).write_text(extension)
        openssl("x509", "-req", "-days", "1", "-in", name + ".csr", "-CA", "ca.pem", "-CAkey", "ca.key", "-CAcreateserial", "-out", name + ".pem", "-extfile", name + ".ext")
        run(["openssl", "x509", "-in", name + ".pem", "-outform", "DER", "-out", name + ".der"], cwd=root)
        fingerprints[name] = hashlib.sha256((root / (name + ".der")).read_bytes()).hexdigest()
        (root / (name + ".key")).chmod(0o600)
    return fingerprints


def build_module_image(base, version, schema_version, temporary, tools):
    context = temporary / ("image-" + version)
    app = context / "api"
    for directory in ("plugins/ModulePortal", "plugins/DeliveryReceipt", "plugins/OfferReservation", "plugins-core/Epay"):
        shutil.copytree(ROOT / "api" / directory, app / directory)
    package = app / "plugins/ModulePortal"
    for name in ("module.json", "config.json"):
        value = tools.read(package / name)
        value["version"] = version
        if name == "module.json":
            value["schema_version"] = schema_version
        tools.write_json(package / name, value)
    source = (package / "Plugin.php").read_text().replace("return '1.0.0';", "return '" + version + "';")
    if schema_version == 2:
        original = "if ($toVersion !== 1 || !in_array($fromVersion, [0, 1], true)) throw new ExtensionFailure('MODULE_SCHEMA_VERSION_CONFLICT');"
        require(original in source, "Module schema fixture source changed")
        source = source.replace(original, """if ($toVersion !== 2 || !in_array($fromVersion, [1, 2], true)) throw new ExtensionFailure('MODULE_SCHEMA_VERSION_CONFLICT');
        if (!\\Illuminate\\Support\\Facades\\Schema::hasTable('tx_ext_module_portal_note')) {
            \\Illuminate\\Support\\Facades\\Schema::create('tx_ext_module_portal_note', static function (\\Illuminate\\Database\\Schema\\Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('body', 80);
            });
        }""")
    (package / "Plugin.php").write_text(source, encoding="utf-8", newline="\n")
    lock = tools.build_lock(app, ROOT / "api/bootstrap/module-composition.proof.json")
    target = "txboard-native-proof:" + version
    (context / "Dockerfile").write_text("FROM " + base + "\nCOPY --chown=www:www api/plugins/ModulePortal/ /www/plugins/ModulePortal/\nENV CORE_RELEASE_ID=native-image-proof-" + version + "\n")
    run(["docker", "build", "--quiet", "--tag", target, context])
    image = json.loads(run(["docker", "image", "inspect", target]))[0]["Id"]
    return image, lock


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--deploy-root", required=True, type=Path)
    parser.add_argument("--provider-binary", required=True, type=Path)
    parser.add_argument("--image", default="txboard-ci:pr")
    parser.add_argument("--output", type=Path, default=ROOT / "artifacts/modular-native-image.json")
    parser.add_argument("--fault-child", action="store_true", help=argparse.SUPPRESS)
    parser.add_argument("--dir", type=Path, help=argparse.SUPPRESS)
    parser.add_argument("--configuration", type=Path, help=argparse.SUPPRESS)
    args = parser.parse_args()
    require(os.name == "posix" and os.geteuid() == 0, "Native image proof requires the isolated Linux CI root context")
    if args.fault_child:
        native = load("native_runtime_proof", args.dir / "scripts/native-runtime.py")
        def hook(stage):
            if stage == "native_prepared":
                print("module-schema-committed-before-composition-adoption", flush=True)
                time.sleep(45)
                raise RuntimeError("fault controller did not kill the executor")
        with native.installation_lock(args.dir):
            native.Runner(args.dir, native.Docker(args.dir), hook=hook).execute("update", args.image, configuration=args.configuration)
        raise RuntimeError("Fault boundary was not reached")
    native = load("native_runtime", args.deploy_root / "scripts/native-runtime.py")
    tools = load("module_dev", ROOT / "scripts/module-dev.py")
    baseline = json.loads(run(["docker", "image", "inspect", args.image]))[0]["Id"]
    gateway = json.loads(run(["docker", "network", "inspect", "bridge"]))[0]["IPAM"]["Config"][0]["Gateway"]
    with tempfile.TemporaryDirectory(prefix="tx-native-image-") as folder:
        temporary = Path(folder)
        temporary.chmod(0o700)
        private, source, installation = temporary / "provider-private", temporary / "source", temporary / "installation"
        private.mkdir(mode=0o700)
        source.mkdir(mode=0o700)
        fingerprints = tls(private, gateway)
        seed = secrets.token_bytes(32)
        (private / "signer.der").write_bytes(bytes.fromhex("302e020100300506032b657004220420") + seed)
        run(["openssl", "pkey", "-inform", "DER", "-in", private / "signer.der", "-pubout", "-outform", "DER", "-out", private / "signer-public.der"])
        trust = {"protocol_version": 1, "core_instance_id": "33333333-3333-4333-8333-333333333333", "key_id": "native-image-key", "key_epoch": "1", "algorithm": "Ed25519",
                 "public_key": base64.urlsafe_b64encode((private / "signer-public.der").read_bytes()[-32:]).decode().rstrip("=")}
        write(private / "trust.json", trust)
        password = secrets.token_urlsafe(32)
        preparation = json.loads((ROOT / "api/native-preparation.example.json").read_text())
        preparation["identity"].update(email="native-image@example.test", password=password,
                                       permissions=["extensions.module_portal.view", "extensions.module_portal.configure"])
        preparation["network"]["node_token"] = secrets.token_urlsafe(32)
        preparation["network"]["nodes"][0].update(host="127.0.0.1", port=port())
        write(source / "prepare.json", preparation)
        write(source / "node-recipients.json", {"1": {"machine_id": None, "recipient_sha256": fingerprints["node"]}})
        write(source / "module-packages.lock.json", tools.read(ROOT / "api/bootstrap/module-packages.proof.lock.json"))
        write(source / "module-secrets.json", {})
        write(source / "outbound-policy.json", {})
        write(source / "modules.json", {"schema": "native-module-preparation/v1", "operations": [
            {"code": "module_portal", "action": "install"}, {"code": "module_portal", "action": "configure", "configuration": "module-config.module_portal.json"},
            {"code": "module_portal", "action": "enable"}]})
        write(source / "module-config.module_portal.json", {"label": "Native image portal"})
        (source / "signer.seed").write_text(base64.urlsafe_b64encode(seed).decode().rstrip("=") + "\n")
        for original, target in (("ca.pem", "provider-ca.pem"), ("core.pem", "provider-client.pem"), ("core.key", "provider-client.key")):
            shutil.copyfile(private / original, source / target)
        control_port, render_port, http_port = port(), port(), port()
        (source / "runtime.env").write_text("\n".join([
            "CORE_CREDENTIAL_PROVIDER_ID=primary-provider", "CORE_CREDENTIAL_PROVIDER_EPOCH=1",
            f"CORE_CREDENTIAL_PROVIDER_ENDPOINT=https://{gateway}:{control_port}/operations", "CORE_CREDENTIAL_PROVIDER_PROFILES=vless-uuid/v1,vmess-uuid/v1",
            f"NATIVE_SUBSCRIPTION_RENDER_ENDPOINT=https://{gateway}:{render_port}/subscription-render", "NATIVE_NODE_AUTHORIZATION_KEY_ID=native-image-key",
            "NATIVE_NODE_AUTHORIZATION_KEY_EPOCH=1", "NATIVE_NODE_AUTHORIZATION_POLICY_VERSION=native-provider-authorization/v2",
            "CORS_ALLOWED_ORIGINS=http://127.0.0.1:4317"]) + "\n")
        for path in source.iterdir():
            path.chmod(0o600)
        provider_log = (private / "provider.log").open("w")
        provider = subprocess.Popen([str(args.provider_binary), "--listen", f"0.0.0.0:{control_port}", "--store", str(private / "provider.db"),
            "--provider-id", "primary-provider", "--epoch", "1", "--tls-cert", str(private / "server.pem"), "--tls-key", str(private / "server.key"),
            "--client-ca", str(private / "ca.pem"), "--client-fingerprint", fingerprints["core"], "--delivery-trust", str(private / "trust.json"),
            "--node-client-fingerprint", fingerprints["node"], "--subscription-listen", f"0.0.0.0:{render_port}",
            "--subscription-tls-cert", str(private / "server.pem"), "--subscription-tls-key", str(private / "server.key")],
            stdin=subprocess.DEVNULL, stdout=provider_log, stderr=subprocess.STDOUT)
        try:
            for attempt in range(100):
                require(provider.poll() is None, "Actual Provider exited before listening")
                try:
                    with socket.create_connection(("127.0.0.1", control_port), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.1)
            else:
                raise RuntimeError("Actual Provider did not listen")
            env = dict(os.environ, TXBOARD_INSTALL_DIR=str(installation), TXBOARD_NATIVE_CONFIG_DIR=str(source), TXBOARD_NATIVE_IMAGE=baseline,
                       TXBOARD_ADMIN_EMAIL=preparation["identity"]["email"], TXBOARD_MODE="http", TXBOARD_TEST_MODE="true", TXBOARD_PUBLIC_HOST="127.0.0.1",
                       TXBOARD_HTTP_PORT=str(http_port), TXBOARD_DB_MODE="local", TXBOARD_DB_DATABASE="txboard_c2_native_test_image",
                       TXBOARD_DB_PASSWORD=secrets.token_hex(24), TXBOARD_DB_ROOT_PASSWORD=secrets.token_hex(24), TXBOARD_BACKUP_RETENTION="0")
            run(["bash", args.deploy_root / "install.sh", "--yes"], env=env, timeout=900)
            backend = native.Docker(installation)
            first = backend.report("core:installation", "runtime", running=True)
            require(first["ready"] is True and first["authority_epoch"] == "2", "Fresh native installation was not activated")
            require(backend.container(healthy=True)["image"] == baseline, "Installed image differs from immutable input")
            origin = f"http://127.0.0.1:{http_port}"
            def http(path, body=None, token=None, key=None):
                headers = {"Content-Type": "application/json"}
                if token:
                    headers["Authorization"] = token
                if key:
                    headers["Idempotency-Key"] = key
                request = urllib.request.Request(origin + path, data=None if body is None else json.dumps(body).encode(), headers=headers)
                try:
                    with urllib.request.urlopen(request, timeout=30) as response:
                        return json.load(response)["data"]
                except urllib.error.HTTPError as error:
                    try:
                        code = json.loads(error.read(32768)).get("error", {}).get("code", "HTTP_ERROR")
                    except (ValueError, AttributeError):
                        code = "HTTP_ERROR"
                    if not isinstance(code, str) or not re.fullmatch(r"[A-Z][A-Z0-9_]{1,100}", code):
                        code = "HTTP_ERROR"
                    raise RuntimeError(f"Native HTTP request rejected: {error.code} {code}") from None
            auth = http("/txapi/auth/admin/login", {"email": preparation["identity"]["email"], "password": password})
            presentation = f"/txapi/admin/{auth['secure_path']}/extensions/module_portal/"
            require(http(presentation + "presentation", token=auth["auth_data"])["version"] == "1.0.0", "Real HTTP module presentation was unavailable")
            offers = http("/txapi/plans")
            purchase_key = str(uuid.uuid4())
            try:
                http("/txapi/orders", {"offer_id": offers[0]["offer_id"]}, auth["auth_data"], purchase_key)
            except RuntimeError:
                purchase_diagnostic(installation, preparation["identity"]["email"], offers[0]["offer_id"], purchase_key)
                raise
            money_before = backend.snapshot()
            kernel = run(["docker", "run", "--rm", "--entrypoint", "sha256sum", baseline, "/www/resources/contracts/kernel.v1.files.json"]).split()[0]
            images = [baseline]
            reports = [first]
            recovered_operation = None
            for version, schema_version in (("1.1.0", 1), ("1.2.0", 2)):
                image, package_lock = build_module_image(args.image, version, schema_version, temporary, tools)
                images.append(image)
                require(run(["docker", "run", "--rm", "--entrypoint", "sha256sum", image, "/www/resources/contracts/kernel.v1.files.json"]).split()[0] == kernel, "Proof extension changed frozen Kernel")
                target = temporary / ("configuration-" + version)
                shutil.copytree(installation / "native", target)
                write(target / "module-packages.lock.json", package_lock)
                write(target / "modules.json", {"schema": "native-module-preparation/v1", "operations": [
                    {"code": "module_portal", "action": "update"}, {"code": "module_portal", "action": "enable"}]})
                # The periodic first backup can share a wall-clock second with an immediate manual one.
                time.sleep(1.1)
                if schema_version == 1:
                    run(["bash", installation / "update.sh", "--dir", installation, "--native-image", image, "--native-config", target, "--yes"], timeout=600)
                else:
                    child = subprocess.Popen([sys.executable, "-B", __file__, "--deploy-root", str(args.deploy_root), "--provider-binary", str(args.provider_binary),
                        "--fault-child", "--dir", str(installation), "--configuration", str(target), "--image", image],
                        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                    try:
                        require(child.stdout.readline().strip() == "module-schema-committed-before-composition-adoption", "Native image interruption boundary was not reached")
                        child.kill()
                        child.wait(timeout=10)
                        require(child.returncode != 0, "Deployment executor was not killed")
                    finally:
                        if child.poll() is None:
                            child.kill()
                            child.wait(timeout=10)
                        child.stdout.close()
                        child.stderr.close()
                    intent = native.Journal(installation).load()
                    recovered_operation = intent["operation_id"]
                    require(intent["stage"] == "native_prepared" and not backend.container()["running"], "Interrupted deployment did not preserve stopped writers")
                    require(backend.sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tx_ext_module_portal_note'") == "1", "Module-owned DDL was not durably applied")
                    recovered = json.loads(run(["bash", installation / "txboard.sh", "--dir", installation, "native", "recover"], timeout=600))
                    require(recovered["operation_id"] == recovered_operation and recovered["stage"] == "completed", "Recovery replaced the original intent")
                report = backend.report("core:installation", "runtime", running=True)
                reports.append(report)
                require(backend.container(healthy=True)["image"] == image and report["authority_epoch"] == "2", "New image/epoch readiness mismatch")
                auth = http("/txapi/auth/admin/login", {"email": preparation["identity"]["email"], "password": password})
                require(http(presentation + "presentation", token=auth["auth_data"])["version"] == version, "Worker retained an earlier package version")
                require(http(presentation + "operations/status.get", {"input": {}}, auth["auth_data"])["version"] == version, "Real extension SDK operation did not use target code")
                require(backend.snapshot() == money_before, "Image/module update changed financial aggregates")
            require(backend.sql("SELECT COUNT(*) FROM tx_native_runtime_activation") == "1", "Update recreated installation activation")
            require(backend.sql("SELECT COUNT(*) FROM tx_module_release") == "3", "Missing or duplicate composition approval")
            require(backend.sql("SELECT COUNT(*) FROM tx_order") == "1", "Update duplicated or removed original order")
            require(not native.Journal(installation).guard.exists(), "Completed native recovery left a guard")
            session_file = temporary / "browser-session.json"
            write(session_file, {"origin": origin, "token": auth["auth_data"], "adminPath": auth["secure_path"], "version": "1.2.0"})
            run(["node", ROOT / "gateway/tests/browser/module-host-live.mjs", session_file], cwd=ROOT / "gateway", timeout=90)
            args.output.parent.mkdir(parents=True, exist_ok=True)
            write(args.output, {"schema": "modular-native-image-proof/v1", "images": images, "kernel_manifest_sha256": kernel,
                "compositions": [value["composition_fingerprint"] for value in reports], "authority_epoch": "2", "activation_count": 1,
                "composition_approvals": 3, "original_orders": 1, "module_owned_schema": 2, "recovered_operation": recovered_operation,
                "provider": "actual Go mTLS capability peer; no Node traffic in this image proof", "consumer": "actual admin login, catalog, order, extension HTTP and Chromium SDK host"})
            args.output.chmod(0o644)
            print("Native immutable image install, update and killed-executor recovery passed", flush=True)
        except BaseException:
            codes, stage = [], "before_intent"
            log = installation / "backups/installer/install.log"
            if log.is_file():
                codes = re.findall(r'"error_code"\s*:\s*"([A-Z][A-Z0-9_]{1,160})"', log.read_text(errors="replace")[-1048576:])
            try:
                intent = native.Journal(installation).load()
                if intent is not None:
                    stage = intent["stage"]
            except Exception:
                stage = "journal_unavailable"
            frames = []
            log_directory = installation / 'data/storage/logs'
            for application_log in sorted(log_directory.glob('laravel*.log'))[-3:]:
                if application_log.is_file() and not application_log.is_symlink():
                    frames.extend([list(frame) for frame in re.findall(
                        r'#\d+ /www/(app/[A-Za-z0-9_./]+\.php)\((\d+)\): ([A-Za-z0-9_\\]+)(?:->|::)([A-Za-z0-9_]+)\(',
                        application_log.read_text(errors='replace')[-1048576:])[-12:]])
            diagnostic = {"schema": "modular-native-image-proof/v1", "status": "failed", "stage": stage,
                          "error_codes": codes, "application_frames": frames[-12:]}
            args.output.parent.mkdir(parents=True, exist_ok=True)
            write(args.output, diagnostic)
            args.output.chmod(0o644)
            print(json.dumps(diagnostic), flush=True)
            raise
        finally:
            if (installation / "compose.yaml").exists():
                subprocess.run(["docker", "compose", "down", "--volumes", "--remove-orphans"], cwd=installation,
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=90)
            provider.terminate()
            try:
                provider.wait(timeout=10)
            except subprocess.TimeoutExpired:
                provider.kill()
                provider.wait(timeout=10)
            provider_log.close()


if __name__ == "__main__":
    main()
