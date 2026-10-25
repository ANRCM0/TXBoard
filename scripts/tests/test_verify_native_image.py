import importlib.util
import json
from pathlib import Path
import unittest


spec = importlib.util.spec_from_file_location('native_image_probe', Path(__file__).resolve().parents[1]/'verify-native-image.py')
probe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(probe)


class IngressObservationTests(unittest.TestCase):
    def observe(self, after, ttl=1000, elapsed=10):
        return probe.ingress_probe_observation({'private-original-key'}, after, ttl, elapsed)

    def test_same_budget_and_natural_expiry_preserve_the_original_identity_assertion(self):
        self.assertTrue(self.observe({'private-original-key'})['accepted'])
        self.assertTrue(self.observe(set(), elapsed=1001)['accepted'])
        self.assertTrue(self.observe(set(), ttl=-2)['accepted'])
        self.assertFalse(self.observe(set(), elapsed=999)['accepted'])

    def test_forged_identity_cannot_add_a_bucket_even_when_the_original_expires(self):
        for after in ({'private-original-key', 'forged-key'}, {'forged-key'}, {'forged-a', 'forged-b'}):
            with self.subTest(after=after):
                self.assertFalse(self.observe(after, elapsed=2000)['accepted'])
        self.assertFalse(self.observe({'private-original-key'}, ttl=-1)['accepted'])
        self.assertFalse(self.observe({'private-original-key'}, ttl=60001)['accepted'])
        self.assertFalse(probe.ingress_probe_observation(set(), set(), -2, 10)['accepted'])

    def test_public_observation_contains_counts_and_timings_without_private_keys(self):
        result = self.observe({'private-original-key', 'private-forged-key'})
        self.assertEqual(result['added_buckets'], 1)
        self.assertEqual(result['removed_buckets'], 0)
        self.assertNotIn('private-', json.dumps(result))


if __name__ == '__main__':
    unittest.main()
