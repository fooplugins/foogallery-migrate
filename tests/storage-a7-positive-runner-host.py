#!/usr/bin/env python3
"""Host-only synthetic consumer regressions; NEVER native persistence receipts."""
import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('original_host', ROOT / 'tests/storage-a7-runner-host.py')
assert spec is not None and spec.loader is not None
host = importlib.util.module_from_spec(spec)
spec.loader.exec_module(host)
a7 = host.a7
MANIFEST = json.loads((ROOT / 'tests/integration/storage-a7-options-manifest.json').read_text())
MANIFEST['cells'] = {a7.POSITIVE: MANIFEST['fixture']}


def sample(stage, owner='a' * 32):
    p = host.sample('20000', stage, owner)
    fixture = MANIFEST['fixture']
    exact = dict(bytes=fixture['raw_bytes'], sha256=fixture['sha256'])
    p.update(cell=a7.POSITIVE, fixture=fixture, product_root='/HOST-ONLY', product_classes={},
             product_pins_before={}, product_pins_after={})
    for name in ('StorageOperations', 'StorageConversion', 'LegacyStateCodec', 'LegacyTableSnapshot', 'StorageControl'):
        relative = 'includes/' + name + '.php'
        p['product_classes'][chr(92).join(('FooPlugins', 'FooGalleryMigrate', name))] = dict(file='/HOST-ONLY/' + relative, sha256='a' * 64)
        p['product_pins_before'][relative] = 'a' * 64
        p['product_pins_after'][relative] = 'a' * 64
    p['observations']['logical_sha256'] = fixture['logical_sha256']
    p['authority']['control']['logical_digest'] = fixture['logical_sha256']
    if stage == 'seed':
        p['observations'] = {'seed': {'options': {'foogallery-migrate-data': exact}}}
    if stage in ('forward', 'reverse'):
        p['after']['options'] = {'foogallery-migrate-data': None if stage == 'forward' else exact,
                                 'foogallery-migrate-data-storage-backup': exact}
    if stage in ('seed', 'reverse'):
        receipt = dict(format='a7-options-seed-v1', corpus=a7.POSITIVE, stage='seed', owner=owner, blog_id=1,
                       status='measurement_only', contract_decision='pending', method_approved=False, profile_approved=False,
                       outcome='SUCCESS', first_failure=None, fixture=fixture, persisted_sql=exact,
                       wp_logical_sha256=fixture['logical_sha256'])
        p['positive_seed' if stage == 'seed' else 'reverse_readback'] = receipt
    if stage in ('forward', 'backup', 'reverse'):
        p['fresh_read'] = dict(option='foogallery-migrate-data' if stage == 'forward' else 'foogallery-migrate-data-storage-backup',
                               sql=exact, wp_logical_sha256=fixture['logical_sha256'], wp_serialized_sha256=fixture['sha256'])
    if stage == 'backup':
        p['after'] = copy.deepcopy(p['before'])
        p['result'] = exact
        p['backup_authority'] = dict(backend='table', epoch=1, phase='stable', blog_id=1, logical_digest=fixture['logical_sha256'])
    return p


class PositiveTests(unittest.TestCase):
    def test_all_separate_stages_have_strict_consumers(self):
        for stage in a7.POSITIVE_STAGES:
            with self.subTest(stage=stage):
                a7.validate_positive(sample(stage), a7.POSITIVE, stage, 'a' * 32, MANIFEST)

    def test_mutated_fresh_sql_wp_seed_provenance_authority_resources_refuse(self):
        cases = [
            ('seed', ('positive_seed', 'outcome'), 'SQL_SUCCESS'),
            ('seed', ('positive_seed', 'blog_id'), 2),
            ('seed', ('positive_seed', 'owner'), 'b' * 32),
            ('forward', ('fresh_read', 'wp_logical_sha256'), 'b' * 64),
            ('forward', ('fresh_read', 'wp_serialized_sha256'), 'b' * 64),
            ('forward', ('fresh_read', 'sql'), {'bytes': 0, 'sha256': 'a' * 64}),
            ('backup', ('backup_authority', 'epoch'), 2),
            ('backup', ('fresh_read', 'option'), 'foogallery-migrate-data'),
            ('backup', ('resources', 'peaks_include_bootstrap'), False),
            ('reverse', ('retained_table_after',), 'b' * 64),
            ('reverse', ('reverse_readback', 'wp_logical_sha256'), 'b' * 64),
            ('cleanup', ('product_pins_after',), {}),
            ('forward', ('product_classes',), {}),
        ]
        for stage, keys, value in cases:
            with self.subTest(stage=stage, keys=keys):
                p = sample(stage)
                target = p
                for key in keys[:-1]:
                    target = target[key]
                target[keys[-1]] = value
                with self.assertRaises((ValueError, KeyError)):
                    a7.validate_positive(p, a7.POSITIVE, stage, 'a' * 32, MANIFEST)

    def test_real_owned_harmless_workers_stop_after_forward_mismatch_then_cleanup(self):
        calls = []
        def invoke(cell, stage, owner):
            calls.append((cell, stage))
            p = sample(stage, owner)
            if stage == 'forward':
                p.update(ok=False, first_failure='host-injected-forward-refusal')
            return host.harmless(p)
        with tempfile.TemporaryDirectory() as parent:
            result = a7.run_matrix(Path(parent) / 'new-only', MANIFEST, invoke, 'native-positive')
        self.assertFalse(result['passed'])
        self.assertTrue(result['closure_complete'])
        self.assertTrue(result['cleanup_complete'])
        self.assertEqual(result['first_failure']['stage'], 'forward')
        self.assertEqual(calls, [(a7.POSITIVE, s) for s in ('seed', 'forward', 'cleanup')])

    def test_positive_manifest_cannot_enter_original_consumer(self):
        with self.assertRaises((ValueError, KeyError)):
            a7.validate(sample('seed'), '20000', 'seed', 'a' * 32, host.MANIFEST)

    def test_named_command_routes_actual_worker_with_seed_receipt(self):
        command = a7.command_for(['cove', 'wp', 'OWNED'], '/SOURCE', '/ACTUAL-EXTRACTED-ZIP', '/FIXTURES',
                                 a7.POSITIVE, 'forward', 'a' * 32, 'native-positive', '/OUTPUT/seed.stdout')
        code = command[-1]
        for expected in ('storage-a7-native-positive', '/ACTUAL-EXTRACTED-ZIP', 'FOOGM_A7_SEED_RECEIPT', '/OUTPUT/seed.stdout'):
            self.assertIn(expected, code)
        self.assertIn('/SOURCE/tests/integration/storage-operations.php', code)


if __name__ == '__main__':
    unittest.main()
