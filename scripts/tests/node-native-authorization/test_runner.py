"""Small transport-observer and preflight tests; no database or TLS listener."""
import importlib.util
import json
import hashlib
import os
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

DIRECTORY = Path(__file__).resolve().parent
sys.path.insert(0, str(DIRECTORY))
from tls_proxy import NativeAuthorizationTLSProxy


def text_frame(document):
    body = json.dumps(document).encode()
    if len(body) < 126:
        return b'\x81' + bytes([len(body)]) + body
    return b'\x81\x7e' + len(body).to_bytes(2, 'big') + body


class FrameObservationTest(unittest.TestCase):
    def setUp(self):
        self.events = []
        self.proxy = NativeAuthorizationTLSProxy.__new__(NativeAuthorizationTLSProxy)
        self.proxy.record = self.events.append

    def test_partial_frames_preserve_boundaries_and_redact_credentials(self):
        document = {'event': 'sync.users', 'data': {'node_id': 42,
            'users': [{'id': 7, 'uuid': 'sensitive-user-uuid'}], 'token': 'sensitive-bearer'}}
        first = text_frame(document)
        remaining = self.proxy.observe_frames(first[:7], '8')
        self.assertEqual(remaining, first[:7])
        self.assertEqual(self.events, [])
        self.assertEqual(self.proxy.observe_frames(remaining + first[7:], '8'), b'')
        self.assertEqual(self.events, [{'kind': 'ws-frame', 'event': 'sync.users', 'machine_id': '8', 'node_id': 42}])
        self.assertNotIn('sensitive', json.dumps(self.events))

    def test_coalesced_frames_and_non_user_events(self):
        data = text_frame({'event': 'session.ready', 'data': {'node_id': 12}})
        data += text_frame({'event': 'ping', 'data': {'secret': 'ignored'}})
        data += text_frame({'event': 'sync.user.delta', 'node_id': 13, 'data': {}})
        self.assertEqual(self.proxy.observe_frames(data, '9'), b'')
        self.assertEqual([event['event'] for event in self.events], ['session.ready', 'sync.user.delta'])
        self.assertEqual([event['node_id'] for event in self.events], [12, 13])

    def test_binary_and_malformed_payload_are_not_authority_observations(self):
        data = b'\x82\x02{}' + b'\x81\x01!'
        self.assertEqual(self.proxy.observe_frames(data, '9'), b'')
        self.assertEqual(self.events, [])


class FixtureJSONTest(unittest.TestCase):
    def test_laravel_console_output_and_incomplete_documents_are_explicit_failures(self):
        from run import fixture_json
        with self.assertRaisesRegex(RuntimeError, 'SEED_INVALID_JSON'):
            fixture_json('SQLSTATE synthetic-private-value', 'seed', ('machine_id',))
        with self.assertRaisesRegex(RuntimeError, 'SEED_INVALID_DOCUMENT'):
            fixture_json('{"error":{}}', 'seed', ('machine_id',))
        with self.assertRaisesRegex(RuntimeError, 'STATE_INVALID_DOCUMENT'):
            fixture_json('[]', 'state', ('users',))
        self.assertEqual(fixture_json('{"machine_id":42}', 'seed', ('machine_id',)), {'machine_id': 42})


class PersistedAuthorityTest(unittest.TestCase):
    def test_exact_go_state_envelope_and_ordered_digest(self):
        from run import AUTHORITY_STATE_FIELDS, persisted_authority_document
        state = dict(zip(AUTHORITY_STATE_FIELDS, (1, 'isolated-native-authorization-core',
            'native-node-authorization/v1', 2, 1, 'isolated-ed25519-v1', 'public',
            '1', '7', 1900000000, 1900000040, 1900000000, 'a'*64)))
        compact = json.dumps(state, separators=(',', ':')).encode()
        envelope = {'state': state, 'digest': hashlib.sha256(compact).hexdigest()}
        self.assertEqual(persisted_authority_document(json.dumps(envelope, sort_keys=True)), state)
        envelope['state']['revision'] = '8'
        with self.assertRaisesRegex(RuntimeError, 'DIGEST_MISMATCH'):
            persisted_authority_document(json.dumps(envelope))
        with self.assertRaisesRegex(RuntimeError, 'ENVELOPE_INVALID'):
            persisted_authority_document(json.dumps(state))


class PreflightTest(unittest.TestCase):
    def test_wrong_fixture_environment_is_rejected_before_tools_or_database(self):
        spec = importlib.util.spec_from_file_location('authorization_runner', DIRECTORY / 'run.py')
        runner = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(runner)
        with patch.object(sys, 'argv', ['run.py', '/missing-api', '/missing-node', '/missing-evidence']), \
             patch.dict(os.environ, {'APP_ENV': 'production'}, clear=True), \
             patch.object(runner.subprocess, 'check_output') as process, \
             patch.object(runner.subprocess, 'Popen') as daemon:
            with self.assertRaisesRegex(SystemExit, 'EXCLUSIVE_FIXTURE_REQUIRED'):
                runner.main()
            process.assert_not_called()
            daemon.assert_not_called()


if __name__ == '__main__':
    unittest.main()
