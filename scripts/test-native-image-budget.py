#!/usr/bin/env python3
"""Regression cases for the immutable-image counter observation, without Docker."""
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('image_proof', Path(__file__).with_name('verify-native-image.py'))
proof = importlib.util.module_from_spec(spec)
spec.loader.exec_module(proof)


class IngressObservationTest(unittest.TestCase):
    def exercise(self, states, times=(0, 1)):
        requests, waits = [], []
        iterator, ticks = iter(states), iter(times)
        proof.verify_ingress_budget(lambda *args, **kwargs: requests.append((args, kwargs)),
                                    lambda: next(iterator), waits.append, lambda: next(ticks))
        return requests, waits

    def test_shared_counter_consumes_both_attacker_headers(self):
        requests, waits = self.exercise([{'socket': [7, 59000]}, {'socket': [9, 58000]}])
        self.assertEqual(len(requests), 2)
        self.assertTrue(all('X-Forwarded-For' in call[1]['headers'] for call in requests))
        self.assertEqual(waits, [])

    def test_near_expiry_waits_for_a_fresh_natural_window(self):
        requests, waits = self.exercise([{'socket': [7, 20]}, {'socket': [1, 59900]}, {'socket': [3, 59000]}])
        self.assertEqual(len(waits), 1)
        self.assertAlmostEqual(waits[0], 0.07)
        self.assertEqual(len(requests), 3)

    def test_expiry_during_observation_retries_without_resetting_policy(self):
        requests, waits = self.exercise([{'socket': [7, 15000]}, {}, {'socket': [1, 59900]}, {'socket': [3, 59000]}],
                                        (0, 16, 16, 17))
        self.assertEqual(len(requests), 5)
        self.assertEqual(waits, [])

    def test_another_counter_is_refused_even_when_original_expires(self):
        with self.assertRaisesRegex(RuntimeError, 'created another'):
            self.exercise([{'socket': [7, 15000]}, {'forged': [2, 59000]}], (0, 16))

    def test_matching_keys_without_increment_are_refused(self):
        with self.assertRaisesRegex(RuntimeError, 'did not consume'):
            self.exercise([{'socket': [7, 59000]}, {'socket': [7, 58000]}])

    def test_repeated_expiry_is_bounded(self):
        with self.assertRaisesRegex(RuntimeError, 'three bounded'):
            self.exercise([{'socket': [7, 20]}] * 3, ())


if __name__ == '__main__':
    unittest.main()
