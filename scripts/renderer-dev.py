#!/usr/bin/env python3
"""Inspect and package explicit renderer sources. Never load or enable packages."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import sys
import zipfile

spec = importlib.util.spec_from_file_location('module_dev', Path(__file__).with_name('module-dev.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
FORMAT = re.compile(r'[a-z][a-z0-9-]{0,63}/v[1-9][0-9]{0,3}\Z')
SEMVER = re.compile(r'(?:0|[1-9][0-9]{0,3})\.(?:0|[1-9][0-9]{0,3})\.(?:0|[1-9][0-9]{0,3})\Z')
GO_KEYWORDS = set('break default func interface select case defer go map struct chan else goto package switch const fallthrough if range type continue for import return var'.split())


def version(value):
    if not isinstance(value, str) or not SEMVER.fullmatch(value):
        raise ValueError('RENDERER_CLIENT_VERSION_INVALID')
    return tuple(map(int, value.split('.')))


def validate(directory):
    inventory = module.files(directory, required=("renderer.json", "renderer.go"))
    manifest = module.read(directory / 'renderer.json')
    fields = {'schema', 'id', 'version', 'format_id', 'execution', 'input', 'client',
              'credential_profiles', 'transports', 'config_schema', 'output', 'entry'}
    if set(manifest) != fields or manifest['schema'] != 'renderer-package/v1':
        raise ValueError('RENDERER_MANIFEST_INVALID')
    if not isinstance(manifest['id'], str) or not module.CODE.fullmatch(manifest['id']) or manifest['id'] in GO_KEYWORDS:
        raise ValueError('RENDERER_ID_INVALID')
    if not isinstance(manifest['version'], str) or len(manifest['version']) > 80 or not module.VERSION.fullmatch(manifest['version']):
        raise ValueError('RENDERER_VERSION_INVALID')
    if not isinstance(manifest['format_id'], str) or not FORMAT.fullmatch(manifest['format_id']):
        raise ValueError('RENDERER_FORMAT_INVALID')
    if manifest['execution'] != 'static-provider' or manifest['input'] != 'native-authorized-connections/v1':
        raise ValueError('RENDERER_BOUNDARY_INVALID')
    client = manifest['client']
    if not isinstance(client, dict) or set(client) != {'min', 'before'} or version(client['min']) >= version(client['before']):
        raise ValueError('RENDERER_CLIENT_RANGE_INVALID')
    profiles = manifest['credential_profiles']
    if not module.sequence(profiles, 2) or not profiles or set(profiles) - {'vless-uuid/v1', 'vmess-uuid/v1'}:
        raise ValueError('RENDERER_PROFILE_INVALID')
    if manifest['transports'] != ['tcp']:
        raise ValueError('RENDERER_TRANSPORT_INVALID')
    module.schema(manifest['config_schema'])
    output = manifest['output']
    extensions = {'application/json': {'json'}, 'application/yaml': {'yaml'}, 'text/plain': {'txt', 'conf'}}
    if not isinstance(output, dict) or set(output) != {'content_type', 'extension'} or output['extension'] not in extensions.get(output['content_type'], set()):
        raise ValueError('RENDERER_OUTPUT_INVALID')
    if manifest['entry'] != 'renderer.go' or manifest['entry'] not in inventory:
        raise ValueError('RENDERER_ENTRY_INVALID')
    return manifest, inventory


def scaffold(directory, code, format_id):
    if not module.CODE.fullmatch(code) or code in GO_KEYWORDS or not FORMAT.fullmatch(format_id) or directory.exists():
        raise ValueError('RENDERER_SCAFFOLD_TARGET_INVALID')
    directory.mkdir(parents=True)
    module.write_json(directory / 'renderer.json', {'schema': 'renderer-package/v1', 'id': code,
        'version': '1.0.0', 'format_id': format_id, 'execution': 'static-provider',
        'input': 'native-authorized-connections/v1', 'client': {'min': '1.0.0', 'before': '2.0.0'},
        'credential_profiles': ['vless-uuid/v1', 'vmess-uuid/v1'], 'transports': ['tcp'],
        'config_schema': module.object_schema(), 'output': {'content_type': 'application/json', 'extension': 'json'},
        'entry': 'renderer.go'})
    source = '''package __CODE__

import (
    "errors"
    "github.com/ANRCM0/TX-Node/internal/credentialdelivery"
)

// Provider calls this only after signature, PoP, expiry and live-state checks.
// Integrate this source explicitly into the compiled registry; no runtime loader.
func Render(connections []credentialdelivery.RenderConnection) ([]byte, error) {
    return nil, errors.New("RENDERER_NOT_IMPLEMENTED")
}
'''
    (directory / 'renderer.go').write_text(source.replace('__CODE__', code), encoding='utf-8', newline='\n')
    template = '''package __CODE__

import (
    "testing"
    "github.com/ANRCM0/TX-Node/internal/credentialdelivery"
)

func TestUnsupportedProtocolReturnsNoMaterial(t *testing.T) {
    body, err := Render([]credentialdelivery.RenderConnection{{
        Node: credentialdelivery.ConnectionNode{Protocol: "unsupported"},
    }})
    if err == nil || len(body) != 0 { t.Fatal("unsupported protocol must return no credential material") }
}

func TestAuthorizedGoldenOutputAndClientImport(t *testing.T) {
    t.Fatal("pending: wire the real authorized fixture, deterministic golden output and target client parser")
}
'''
    (directory / 'renderer_test.go.dist').write_text(template.replace('__CODE__', code), encoding='utf-8', newline='\n')
    module.write_json(directory / 'acceptance-cases.json', {'schema': 'renderer-acceptance-cases/v1',
        'status': 'pending', 'cases': ['signed-format-binding', 'expired-or-revoked-grant',
        'recipient-proof', 'client-version-boundaries', 'unsupported-protocol-refusal',
        'ipv6-and-escaped-node-names', 'deterministic-output', 'client-parser-import', 'no-secret-logging']})


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='command', required=True)
    create = commands.add_parser('scaffold')
    create.add_argument('code')
    create.add_argument('format_id')
    create.add_argument('directory', type=Path)
    check = commands.add_parser('validate')
    check.add_argument('directory', type=Path)
    pack = commands.add_parser('package')
    pack.add_argument('directory', type=Path)
    pack.add_argument('output', type=Path)
    lock = commands.add_parser('lock')
    lock.add_argument('directory', type=Path)
    lock.add_argument('output', type=Path)
    verify = commands.add_parser('verify')
    verify.add_argument('directory', type=Path)
    verify.add_argument('lock', type=Path)
    args = parser.parse_args()
    try:
        if args.command == 'scaffold':
            scaffold(args.directory, args.code, args.format_id)
            validate(args.directory)
        else:
            manifest, inventory = validate(args.directory)
            pin = {'schema': 'renderer-source-lock/v1', 'id': manifest['id'],
                   'version': manifest['version'], 'format_id': manifest['format_id'], 'files': inventory}
            if args.command == 'lock':
                if args.output.resolve().is_relative_to(args.directory.resolve()):
                    raise ValueError('RENDERER_LOCK_MUST_BE_EXTERNAL')
                if args.output.exists():
                    old = module.read(args.output)
                    if old.get('id') != manifest['id']:
                        raise ValueError('RENDERER_LOCK_ID_CONFLICT')
                    if old.get('version') == manifest['version'] and old != pin:
                        raise ValueError('RENDERER_VERSION_REUSED')
                module.write_json(args.output, pin)
            elif args.command == 'verify':
                if args.lock.resolve().is_relative_to(args.directory.resolve()) or module.read(args.lock) != pin:
                    raise ValueError('RENDERER_SOURCE_LOCK_MISMATCH')
            if args.command == 'package':
                if any(name.lower() == 'source-lock.json' for name in inventory):
                    raise ValueError('RENDERER_RESERVED_LOCK_FILE')
                if args.output.exists() or args.output.resolve().is_relative_to(args.directory.resolve()):
                    raise ValueError('RENDERER_ARCHIVE_TARGET_INVALID')
                with zipfile.ZipFile(args.output, 'x', compression=zipfile.ZIP_DEFLATED) as archive:
                    for file in inventory:
                        archive.write(args.directory / file, manifest['id'] + '/' + file)
                    archive.writestr(manifest['id'] + '/source-lock.json', json.dumps(pin, sort_keys=True) + '\n')
        print('Renderer development: ' + args.command + ' passed; runtime admission requires explicit source integration')
    except (ValueError, OSError, KeyError, TypeError) as error:
        print(str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
