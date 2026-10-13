"""Independent public-fixture HKDF and framing check; no HPKE implementation or production keys."""
import base64
import hashlib
import hmac
import json
import pathlib
import struct

root = pathlib.Path(__file__).resolve().parent.parent
vector = json.loads((root/'packages/theme-sdk/test/vectors/sc-v2-response-salt.json').read_text())
metadata = vector['envelope']['metadata']
binding = vector['response']
derivation = vector['public_fixture_derivation']
decode = lambda text: base64.urlsafe_b64decode(text+'='*((-len(text))%4))
encode = lambda text: text.encode('ascii')
tuple_bytes = lambda fields: b''.join(struct.pack('>H',len(field))+field for field in fields)
integer = lambda number: struct.pack('>H',number)
fields = [integer(2),integer(0),integer(0x10),integer(1),integer(2),encode(metadata['domain']),
    bytes.fromhex(metadata['kid']),encode(metadata['operation']),encode(metadata['operation_version']),
    bytes.fromhex(metadata['request_id'].replace('-','')),struct.pack('>Q',metadata['issued_at']),
    struct.pack('>Q',metadata['expires_at']),decode(metadata['nonce']),encode(metadata['origin']),encode(metadata['path'])]
request_aad = tuple_bytes([b'txboard.sc-v2-codec/1/request-aad',*fields])
enc = decode(vector['envelope']['enc']);nonce = decode(binding['response_nonce'])
digest_input = tuple_bytes([b'txboard.sc-v2-codec/1/request-digest',request_aad,enc,decode(vector['envelope']['ct'])])
assert hashlib.sha256(digest_input).digest() == decode(binding['request_digest'])
legacy_aad = tuple_bytes([b'txboard.sc-v2-codec/1/response-aad',*fields,decode(binding['request_digest']),
    encode(binding['kind']),integer(binding['status'])])
aad = tuple_bytes([b'txboard.sc-v2-response-salt/1/aad',legacy_aad,nonce])
assert len(enc)==65 and len(nonce)==32
salt = enc+nonce
assert salt.hex()==derivation['salt_hex']
prk = hmac.digest(salt,bytes.fromhex(derivation['exported_hex']),'sha256')
key = hmac.digest(prk,b'key\x01','sha256')
aead_nonce = hmac.digest(prk,b'nonce\x01','sha256')[:12]
assert key.hex()==derivation['key_hex']
assert aead_nonce.hex()==derivation['nonce_hex']
assert aad.hex()==derivation['aad_hex']
print(json.dumps({'result':'passed','independent_checks':['request digest','response salt','HKDF key','HKDF nonce','AAD framing'],
    'scope':'public fixture and standard-library HMAC only; not independent HPKE or a protocol audit'}))
