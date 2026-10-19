#!/usr/bin/env python3
"""Check the built-in renderer declaration and exact compiled source fingerprints."""
import hashlib
import json
from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
value = json.loads((root / 'contracts/renderers/builtin.v1.json').read_text(encoding='utf-8'))
sources = {
    'api/app/Domains/CredentialProvider/RenderGrantCodec.php',
    'api/app/Domains/Subscription/Services/NativeSubscriptionDelivery.php',
    'node/internal/credentialdelivery/render.go',
    'node/internal/credentialdelivery/render_formats.go',
    'node/internal/credentialprovider/render_clients.go',
    'node/internal/credentialprovider/render_surge.go',
    'node/internal/credentialprovider/render_http.go',
    'node/internal/credentialprovider/render_cors.go',
}
expected = {
    'clash/v1': {'min': '1.18.0', 'before': '2.0.0', 'content_type': 'application/yaml'},
    'clash-meta/v1': {'min': '1.18.0', 'before': '2.0.0', 'content_type': 'application/yaml'},
    'sing-box/v1': {'min': '1.10.0', 'before': '1.13.0', 'content_type': 'application/json'},
    'shadowrocket/v1': {'min': '2.2.0', 'before': '3.0.0', 'content_type': 'text/plain'},
    'surge-ios/v1': {'min': '5.20.0', 'before': '6.0.0', 'content_type': 'text/plain'},
    'surge-mac/v1': {'min': '6.7.0', 'before': '7.0.0', 'content_type': 'text/plain'},
}
if value.get('schema') != 'builtin-renderer-registry/v1' or value.get('execution') != 'static-provider' or value.get('input') != 'native-authorized-connections/v1':
    raise SystemExit('Renderer boundary declaration invalid')
if not re.fullmatch(r'(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)', value.get('version', '')):
    raise SystemExit('Renderer package version invalid')
if value.get('formats') != expected or value.get('profiles') != ['vless-uuid/v1', 'vmess-uuid/v1'] or value.get('transports') != ['tcp']:
    raise SystemExit('Renderer compatibility declaration invalid')
compatibility = {name: {'credential_profiles': ['vmess-uuid/v1'] if name in ('clash/v1', 'surge-ios/v1', 'surge-mac/v1') else ['vless-uuid/v1', 'vmess-uuid/v1'],
                      'transports': ['tcp'], 'explicit_alpn': name != 'clash/v1'} for name in expected}
assert value.get('compatibility') == compatibility, 'Renderer protocol compatibility drift'
actual = {name: hashlib.sha256((root / name).read_bytes()).hexdigest() for name in sorted(sources)}
if value.get('source_files') != actual:
    raise SystemExit('Renderer source fingerprints changed; review and version the built-in package')

# Admission is declared separately by Core, Provider and the recipient client.
# Compare source declarations without executing delivery or compatibility tests.
php = (root / 'api/app/Domains/CredentialProvider/RenderGrantCodec.php').read_text(encoding='utf-8')
php_rules = {name: {'min': lower, 'before': upper} for name, lower, upper in re.findall(
    r"'([^']+/v1)'\s*=>\s*\['min'\s*=>\s*'([^']+)',\s*'before'\s*=>\s*'([^']+)'\]", php)}
assert php_rules == {name: {key: rule[key] for key in ('min', 'before')} for name, rule in expected.items()}, 'Core renderer admission drift'
profile_body = php.split('public static function profiles(', 1)[1].split('public static function compatibleNode(', 1)[0]
php_profiles = {}
for names, profiles in re.findall(r"((?:self::FORMAT|'[^']+/v1')(?:,\s*(?:self::FORMAT|'[^']+/v1'))*)\s*=>\s*\[([^]]+)\]", profile_body):
    for name in re.findall(r"'([^']+/v1)'", names):
        php_profiles[name] = re.findall(r"'([^']+/v1)'", profiles)
assert php_profiles == {name: rule['credential_profiles'] for name, rule in compatibility.items()}, 'Core renderer profile drift'

go = (root / 'node/internal/credentialdelivery/render_formats.go').read_text(encoding='utf-8')
constants = dict(re.findall(r'^\s*(Render\w+)\s*=\s*"([^"\n]+)"', go, re.M))
assert set(constants.values()) == set(expected), 'Provider renderer vocabulary drift'
profile_body = go.split('func ValidRenderProfile(', 1)[1].split('func CompatibleRenderNode(', 1)[0]
go_profiles = {}
for names, expression in re.findall(r'case\s+([^:]+):\s*return\s+([^\n]+)', profile_body):
    for constant in re.findall(r'Render\w+', names):
        if constant in constants:
            go_profiles[constants[constant]] = re.findall(r'profile\s*==\s*"([^"\n]+)"', expression)
assert go_profiles == {name: rule['credential_profiles'] for name, rule in compatibility.items()}, 'Provider renderer profile drift'
for constant, name in constants.items():
    rule = expected[name]
    lower, upper = [list(map(int, rule[key].split('.'))) for key in ('min', 'before')]
    assert lower[2] == upper[2] == 0, 'Patch-level admission requires a reviewed source guard'
    condition = f'major == {lower[0]} && minor >= {lower[1]}'
    if upper[0] == lower[0]:
        condition += f' && minor < {upper[1]}'
    else:
        assert upper[:2] == [lower[0] + 1, 0], 'Non-adjacent major admission requires review'
    body = re.search(r'case\s+(?:Render\w+,\s*)*' + re.escape(constant) + r'(?:,\s*Render\w+)*:\s*return\s+([^\n]+)', go)
    assert body and re.sub(r'\s+', '', body[1]) == re.sub(r'\s+', '', condition), 'Provider renderer admission drift: ' + name

client = (root / 'scripts/native-subscription-client.mjs').read_text(encoding='utf-8')
entries = re.findall(r"\['([^']+)',\s*\{\s*min:\s*\[([^]]+)\],\s*before:\s*\[([^]]+)\],\s*mime:\s*'([^']+)',\s*extension:\s*'([^']+)',\s*filename:\s*'([^']+)'\s*\}\]", client)
assert len(entries) == len(expected) and {entry[0] for entry in entries} == set(expected), 'Recipient renderer vocabulary drift'
provider = '\n'.join((root / name).read_text(encoding='utf-8') for name in (
    'node/internal/credentialprovider/render_clients.go', 'node/internal/credentialprovider/render_surge.go'))
documents = {filename: mime.split(';', 1)[0] for mime, filename in re.findall(
    r'return renderedDocument\{[^\n]*, "([^"\n]+)", "([^"\n]+)"\}', provider)}
assert len(documents) == len(expected), 'Provider document declarations missing'
for name, lower, upper, mime, extension, filename in entries:
    rule = expected[name]
    assert '.'.join(map(str, json.loads('[' + lower + ']'))) == rule['min'], name
    assert '.'.join(map(str, json.loads('[' + upper + ']'))) == rule['before'], name
    assert mime == rule['content_type'] == documents.get(filename) and Path(filename).suffix == extension, 'Recipient document declaration drift: ' + name
assert value.get('deferred_consumers') == ['browser'], 'Frontend stage must be reviewed explicitly'
assert not (root / 'web/shared/src/subscriptionDelivery.ts').exists(), 'Review browser admission before adding the frontend consumer'

owner = (root / 'api/app/Domains/Subscription/Services/NativeSubscriptionDelivery.php').read_text(encoding='utf-8')
owner_documents = {name: (mime, filename) for name, mime, filename in re.findall(
    r"'([^']+/v1)'\s*=>\s*\['([^']+)',\s*'([^']+)',\s*'[^']+'\]", owner)}
assert owner_documents == {name: (mime, filename) for name, _, _, mime, _, filename in entries}, 'Core/CLI document drift'
print('Renderer registry: source fingerprints and Core/Provider/CLI declarations match; browser frontend deferred; runtime acceptance remains separate')
