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
    'api/app/Domains/Subscription/Services/NativeSubscriptionTemplates.php',
    'node/internal/credentialdelivery/render.go',
    'node/internal/credentialdelivery/render_formats.go',
    'node/internal/credentialdelivery/client_template.go',
    'node/internal/credentialprovider/render_clients.go',
    'node/internal/credentialprovider/render_surge.go',
    'node/internal/credentialprovider/render_http.go',
    'node/internal/credentialprovider/render_cors.go',
    'node/internal/credentialprovider/render_surfboard.go',
    'node/internal/credentialprovider/render_template.go',
    'node/internal/credentialprovider/template_validation.go',
    'scripts/native-subscription-client.mjs',
}
expected = {
    'clash/v1': {'min': '1.18.0', 'before': '2.0.0', 'content_type': 'application/yaml'},
    'clash-meta/v1': {'min': '1.18.0', 'before': '2.0.0', 'content_type': 'application/yaml'},
    'sing-box/v1': {'min': '1.10.0', 'before': '1.13.0', 'content_type': 'application/json'},
    'shadowrocket/v1': {'min': '2.2.0', 'before': '3.0.0', 'content_type': 'text/plain'},
    'surge-ios/v1': {'min': '5.20.0', 'before': '6.0.0', 'content_type': 'text/plain'},
    'surge-mac/v1': {'min': '6.7.0', 'before': '7.0.0', 'content_type': 'text/plain'},
    'stash/v1': {'min': '3.4.0', 'before': '3.4.1', 'content_type': 'application/yaml'},
    'surfboard/v1': {'min': '2.35.2', 'before': '2.35.3', 'content_type': 'text/plain'},
}
if value.get('schema') != 'builtin-renderer-registry/v1' or value.get('execution') != 'static-provider' or value.get('input') != 'native-authorized-connections/v1':
    raise SystemExit('Renderer boundary declaration invalid')
if not re.fullmatch(r'(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)', value.get('version', '')):
    raise SystemExit('Renderer package version invalid')
if value.get('formats') != expected or value.get('profiles') != ['vless-uuid/v1', 'vmess-uuid/v1'] or value.get('transports') != ['tcp']:
    raise SystemExit('Renderer compatibility declaration invalid')
vmess_only = {'clash/v1', 'surge-ios/v1', 'surge-mac/v1', 'surfboard/v1'}
omitted_alpn = {'clash/v1', 'stash/v1', 'surfboard/v1'}
compatibility = {name: {'credential_profiles': ['vmess-uuid/v1'] if name in vmess_only else ['vless-uuid/v1', 'vmess-uuid/v1'],
                      'transports': ['tcp'], 'explicit_alpn': name not in omitted_alpn} for name in expected}
assert value.get('compatibility') == compatibility, 'Renderer protocol compatibility drift'
actual = {name: hashlib.sha256((root / name).read_bytes()).hexdigest() for name in sorted(sources)}
if value.get('source_files') != actual:
    raise SystemExit('Renderer source fingerprints changed; review and version the built-in package')

node_package = json.loads((root / 'node/compatibility/renderers.v1.json').read_text(encoding='utf-8'))
assert node_package.get('schema') == 'txnode-builtin-renderers/v1', 'Node renderer declaration missing'
assert node_package.get('source_files') == {name.removeprefix('node/'): digest for name, digest in actual.items() if name.startswith('node/')}, 'Node renderer source fingerprints drift'
for key in ('execution', 'input', 'formats', 'profiles', 'transports', 'compatibility'):
    assert node_package.get(key) == value.get(key), 'Node renderer surface drift: ' + key

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
php_node = php.split('public static function compatibleNode(', 1)[1].split('public static function format(', 1)[0]
php_alpn = re.search(r"!in_array\(\$format,\s*\[([^]]+)\],\s*true\).*\(\$node\['tls'\]\['alpn'\]\s*\?\?\s*\[\]\)\s*===\s*\[\]", php_node)
assert php_alpn and set(re.findall(r"'([^']+/v1)'", php_alpn[1])) == omitted_alpn, 'Core renderer ALPN drift'

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
go_node = go.split('func CompatibleRenderNode(', 1)[1]
assert {constants[name] for name in re.findall(r'format\s*!=\s*(Render\w+)', go_node)} == omitted_alpn, 'Provider renderer ALPN drift'
assert 'node.TLS == nil || len(node.TLS.ALPN) == 0' in go_node, 'Provider renderer ALPN guard missing'
for constant, name in constants.items():
    rule = expected[name]
    lower, upper = [list(map(int, rule[key].split('.'))) for key in ('min', 'before')]
    if lower[:2] == upper[:2] and upper[2] == lower[2] + 1:
        # A single patch is admitted by an exact version guard, never a minor range.
        condition = f'version == "{rule["min"]}"'
    elif lower[2] == upper[2] == 0:
        condition = f'major == {lower[0]} && minor >= {lower[1]}'
        if upper[0] == lower[0]:
            condition += f' && minor < {upper[1]}'
        else:
            assert upper[:2] == [lower[0] + 1, 0], 'Non-adjacent major admission requires review'
    else:
        raise AssertionError('Patch-level admission requires a reviewed source guard: ' + name)
    body = re.search(r'case\s+(?:Render\w+,\s*)*' + re.escape(constant) + r'(?:,\s*Render\w+)*:\s*return\s+([^\n]+)', go)
    assert body and re.sub(r'\s+', '', body[1]) == re.sub(r'\s+', '', condition), 'Provider renderer admission drift: ' + name

client = (root / 'scripts/native-subscription-client.mjs').read_text(encoding='utf-8')
entries = re.findall(r"\['([^']+)',\s*\{\s*min:\s*\[([^]]+)\],\s*before:\s*\[([^]]+)\],\s*mime:\s*'([^']+)',\s*extension:\s*'([^']+)',\s*filename:\s*'([^']+)'\s*\}\]", client)
assert len(entries) == len(expected) and {entry[0] for entry in entries} == set(expected), 'Recipient renderer vocabulary drift'
for declaration, formats in [('vmessOnlyFormats', vmess_only), ('omittedALPNFormats', omitted_alpn)]:
    body = re.search(r'const ' + declaration + r' = \[([^]]+)\];', client)
    assert body and set(re.findall(r"'([^']+/v1)'", body[1])) == formats, 'Recipient renderer compatibility drift: ' + declaration
assert 'compare(rule.min) >= 0 && compare(rule.before) < 0' in client, 'Recipient renderer version guard missing'
assert 'profiles.includes(grant.subject.credential_profile_id)' in client and '`${node.protocol}-uuid/v1` === grant.subject.credential_profile_id' in client, 'Recipient renderer profile guard missing'
assert "!omittedALPNFormats.includes(format) || node.tls === null || Array.isArray(node.tls?.alpn) && node.tls.alpn.length === 0" in client, 'Recipient renderer ALPN guard missing'
provider = '\n'.join((root / name).read_text(encoding='utf-8') for name in (
    'node/internal/credentialprovider/render_clients.go', 'node/internal/credentialprovider/render_surge.go',
    'node/internal/credentialprovider/render_surfboard.go'))
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
