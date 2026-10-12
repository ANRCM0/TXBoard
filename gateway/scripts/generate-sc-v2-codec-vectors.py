"""Independent fixture encoder. Run manually only when reviewing byte changes.

Public byte fixtures only; this program implements no HPKE, key derivation or AEAD.
"""
import base64
import hashlib
import json
from pathlib import Path

root = Path(__file__).resolve().parent.parent
label = 'txboard.sc-v2-codec/1'
metadata = dict(version=2, mode=0, domain='user', kid='0123456789abcdef' * 4,
                operation='user.profile.get', operation_version='1',
                request_id='12345678-1234-4234-8234-123456789abc', issued_at=1791770400,
                expires_at=1791770460, nonce=base64.urlsafe_b64encode(bytes(range(32))).decode().rstrip('='),
                origin='https://gateway.example.test', path='/gateway/v2/channel/user')
def frame(parts):
    return b''.join(len(part).to_bytes(2, 'big') + part for part in parts)
def text(value):
    return value.encode('ascii')
def integer(value, size):
    return value.to_bytes(size, 'big')
fields = [integer(2, 2), integer(0, 2), integer(0x0010, 2), integer(0x0001, 2), integer(0x0002, 2),
          text(metadata['domain']), bytes.fromhex(metadata['kid']), text(metadata['operation']),
          text(metadata['operation_version']), bytes.fromhex(metadata['request_id'].replace('-', '')),
          integer(metadata['issued_at'], 8), integer(metadata['expires_at'], 8), bytes(range(32)),
          text(metadata['origin']), text(metadata['path'])]
aad = frame([text(label + '/request-aad'), *fields])
# Reuse an explicitly public test curve point; zero ciphertext is framing evidence only.
old = json.loads((root / 'packages/theme-sdk/test/vectors/hpke-kat.json').read_text())
encoded = old['vector']['enc']
enc = base64.urlsafe_b64decode(encoded + '=' * (-len(encoded) % 4))
ciphertext = bytes(16)
digest_input = frame([text(label + '/request-digest'), aad, enc, ciphertext])
digest = hashlib.sha256(digest_input).digest()
binding = dict(request_digest=base64.urlsafe_b64encode(digest).decode().rstrip('='), kind='result', status=200)
response_aad = frame([text(label + '/response-aad'), *fields, digest, text('result'), integer(200, 2)])
document = dict(description='Independent Python metadata byte vectors; zero ciphertext is not encrypted SC-v2 traffic.',
                metadata=metadata, binding=binding, enc_hex=enc.hex(), ciphertext_hex=ciphertext.hex(),
                request_aad_hex=aad.hex(), request_digest_input_hex=digest_input.hex(),
                response_aad_hex=response_aad.hex(),
                response_key_context_hex=frame([text(label + '/response/key'), digest]).hex(),
                response_nonce_context_hex=frame([text(label + '/response/nonce'), digest]).hex())
target = root / 'packages/theme-sdk/test/vectors/sc-v2-codec.json'
target.write_text(json.dumps(document, indent=2) + '\n', encoding='utf-8', newline='\n')
print(hashlib.sha256(target.read_bytes()).hexdigest())
