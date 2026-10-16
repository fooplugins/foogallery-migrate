#!/usr/bin/env python3
"""Host-only tests: actual runner/finite corpus; harmless owned worker processes.
Synthetic consumer records below are NOT native WordPress/calibration evidence.
"""
import copy
import importlib.util
import json
import os
import shutil
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('a7', ROOT / 'tests/integration/storage-a7-runner.py')
a7 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(a7)
MANIFEST = json.loads((ROOT / 'tests/integration/storage-a7-manifest.json').read_text())


def sample(cell='1000', stage='forward', owner='a' * 32):
    fixture = MANIFEST['cells'][cell]
    resource = dict(live=4096, allocated=8192, peak_live_cumulative=16384, peak_allocated_cumulative=32768,
                    rusage={'ru_maxrss': 4096}, rss_unit='KiB')
    names = ['receiver-entry', 'authorized', 'policy']
    if stage == 'forward':
        names += ['source-read', 'source-decoded', 'source-verified', 'control-copying', 'snapshot-preflight', 'snapshot-staged',
                  'snapshot-readback', 'snapshot-committed', 'control-verified', 'retained-backup', 'control-flipped', 'control-stable', 'released', 'response']
    elif stage == 'reverse':
        names += ['source-verified', 'control-copying', 'reverse-serialized', 'reverse-written', 'source-read', 'source-decoded',
                  'control-verified', 'control-flipped', 'control-stable', 'released', 'response']
    target, epoch = ('option', 2) if stage == 'reverse' else ('table', 1)
    expected = dict(bytes=fixture['raw_bytes'], sha256=fixture['sha256'])
    value = dict(format='a7-worker-v1', producer='real-StorageOperations-WP-SQL', cell=cell, stage=stage, owner=owner,
                 status='measurement_only', method_approved=False, profile_approved=False, fixture=fixture,
                 blog_id=1, runtime={'mock_host_test_only': True}, fingerprint='a' * 64, ok=True, first_failure=None,
                 bootstrap=copy.deepcopy(resource), resources=dict(resource, peaks_include_bootstrap=True, peaks_reset_or_subtracted=False),
                 seconds=0.001, milestones_valid=True, response_bytes=80,
                 milestones=[dict(resource, name=name, time=index + 1) for index, name in enumerate(names)],
                 result=dict(blog_id=1, backend=target, epoch=epoch, phase='stable'),
                 authority=dict(control=dict(backend=target, epoch=epoch, blog_id=1, phase='stable', logical_digest=fixture.get('logical_sha256')),
                                sentinel={'epoch': epoch} if target == 'table' else None),
                 observations={'logical_sha256': fixture.get('logical_sha256')}, before={'host-test-only': True},
                 after={'options': {'foogallery-migrate-data': None if target == 'table' else expected, 'foogallery-migrate-data-storage-backup': expected}})
    if stage == 'reverse':
        value.update(retained_table_before='a' * 64, retained_table_after='a' * 64)
    if stage == 'seed':
        value['observations'] = {'seed': {'options': {'foogallery-migrate-data': expected}}}
    if stage == 'cleanup':
        value['observations'] = {'cleanup_completed': True}
        value['after'] = {'options': {'owned': None}, 'tables': {'owned': None}}
    if stage == 'preflight':
        value['result'] = dict(decode_admitted=True, conversion_ready=False, raw_bytes=fixture['raw_bytes'])
        value['after'] = copy.deepcopy(value['before'])
    if stage == 'refusal':
        value['result'] = {'error': 'foogallery_migrate_storage_decode_refused'}
        value['after'] = copy.deepcopy(value['before'])
        value['authority'] = dict(control=None, sentinel=None)
    return value


def harmless(value):
    return a7.bounded_worker([sys.executable, '-c', 'import sys;sys.stdout.write(' + repr(json.dumps(value)) + ')'], seconds=3)


class WorkerTests(unittest.TestCase):
    def test_actual_output_and_both_EOF_and_reap(self):
        result = a7.bounded_worker([sys.executable, '-c', "import sys;print('ok');print('err',file=sys.stderr)"])
        self.assertTrue(result['closed'])
        self.assertEqual((result['stdout'], result['stderr'], result['exit']), (b'ok\n', b'err\n', 0))
        self.assertIsNone(result['first_failure'])

    def test_delayed_output_after_parent_exit(self):
        # Owned inherited pipe, both EOFs must outlive root reap.
        code = "import os,time; p=os.fork(); (time.sleep(.15),os.write(1,b'late'),os._exit(0)) if p==0 else os._exit(0)"
        result = a7.bounded_worker([sys.executable, '-c', code], seconds=2)
        self.assertTrue(result['closed'])
        self.assertEqual(result['stdout'], b'late')
        self.assertGreaterEqual(result['seconds'], .15)

    def test_inherited_pipe_deadline_even_after_root_exit(self):
        code = 'import os,time; p=os.fork(); time.sleep(3) if p==0 else os._exit(0)'
        result = a7.bounded_worker([sys.executable, '-c', code], seconds=.12)
        self.assertEqual(result['first_failure'], 'watchdog:reap-and-both-EOF')
        self.assertTrue(result['closed'])
        self.assertLess(result['seconds'], 1.5)

    def test_sleeping_child_watchdog(self):
        result = a7.bounded_worker([sys.executable, '-c', 'import time;time.sleep(3)'], seconds=.1)
        self.assertTrue(result['closed'])
        self.assertEqual(result['first_failure'], 'watchdog:reap-and-both-EOF')

    def test_stdout_bound_preserves_first_failure(self):
        result = a7.bounded_worker([sys.executable, '-c', 'import os,time;os.write(1,b"x"*8192);time.sleep(3)'], limit=100, seconds=.2)
        self.assertEqual(result['first_failure'], 'output-bound:stdout')
        self.assertEqual(len(result['stdout']), 100)
        self.assertTrue(result['closed'])

    def test_stderr_bound(self):
        result = a7.bounded_worker([sys.executable, '-c', 'import os;os.write(2,b"x"*100000)'])
        self.assertEqual(result['first_failure'], 'output-bound:stderr')
        self.assertEqual(len(result['stderr']), 65536)
        self.assertTrue(result['closed'])

    def test_exit_and_spawn_failure(self):
        result = a7.bounded_worker([sys.executable, '-c', 'raise SystemExit(7)'])
        self.assertEqual(result['first_failure'], 'worker-exit:7')
        self.assertTrue(result['closed'])
        absent = a7.bounded_worker(['/no-such-owned-a7-executable'])
        self.assertFalse(absent['closed'])
        self.assertTrue(absent['first_failure'].startswith('spawn:'))


class ConsumerTests(unittest.TestCase):
    def test_valid_all_stage_shapes(self):
        for stage in ('seed', 'preflight', 'forward', 'reverse', 'refusal', 'cleanup'):
            cell = 'over-bound' if stage == 'refusal' else '1000'
            a7.validate(sample(cell, stage), cell, stage, 'a' * 32, MANIFEST)

    def mutated(self, mutate, stage='forward'):
        value = sample(stage=stage)
        mutate(value)
        with self.assertRaises((ValueError, KeyError, TypeError)):
            a7.validate(value, '1000', stage, 'a' * 32, MANIFEST)

    def test_missing_producer(self):
        self.mutated(lambda p: p.pop('producer'))

    def test_binding(self):
        self.mutated(lambda p: p.update(owner='b' * 32))

    def test_fixture_semantics(self):
        self.mutated(lambda p: p.update(fixture=dict(p['fixture'], images=17)))

    def test_approval_confusion(self):
        self.mutated(lambda p: p.update(profile_approved=True))

    def test_phase_order_mutation(self):
        self.mutated(lambda p: p['milestones'].reverse())

    def test_missing_readback(self):
        self.mutated(lambda p: p.update(milestones=[m for m in p['milestones'] if m['name'] != 'snapshot-readback']))

    def test_wrong_authority(self):
        self.mutated(lambda p: p['authority']['control'].update(backend='option'))

    def test_wrong_epoch(self):
        self.mutated(lambda p: p['result'].update(epoch=7))

    def test_wrong_logical_digest(self):
        self.mutated(lambda p: p['observations'].update(logical_sha256='b' * 64))

    def test_wrong_retained_bytes(self):
        self.mutated(lambda p: p['after']['options']['foogallery-migrate-data-storage-backup'].update(sha256='0' * 64))

    def test_peak_reset(self):
        self.mutated(lambda p: p['resources'].update(peaks_reset_or_subtracted=True))

    def test_missing_RSS(self):
        self.mutated(lambda p: p['resources'].pop('rusage'))

    def test_nan_resource(self):
        self.mutated(lambda p: p['resources'].update(allocated=float('nan')))

    def test_response_bound(self):
        self.mutated(lambda p: p.update(response_bytes=1025))

    def test_cleanup_mutation(self):
        self.mutated(lambda p: p['after']['tables'].update(owned=1), stage='cleanup')

    def test_wrong_refusal(self):
        value = sample('over-bound', 'refusal')
        value['result']['error'] = 'foogallery_migrate_storage_conversion_failed'
        with self.assertRaises(ValueError):
            a7.validate(value, 'over-bound', 'refusal', 'a' * 32, MANIFEST)


class MatrixTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='a7-host-', dir=os.environ['TMPDIR'])
        self.addCleanup(self.temp.cleanup)
        self.output = Path(self.temp.name) / 'new'

    def test_actual_harmless_workers_all_cells(self):
        calls = []
        def invoke(cell, stage, owner):
            calls.append((cell, stage))
            return harmless(sample(cell, stage, owner))
        result = a7.run_matrix(self.output, MANIFEST, invoke)
        self.assertTrue(result['passed'], result)
        self.assertEqual(len(calls), 23)
        proposed = a7.envelope([self.output], MANIFEST)
        self.assertFalse(proposed['profile_approved'])
        self.assertEqual(proposed['measured_policy_candidate']['max_raw_bytes'], MANIFEST['cells']['20000']['raw_bytes'])
        self.assertFalse(proposed['derivation']['subtraction'])
        with self.assertRaises(FileExistsError):
            a7.run_matrix(self.output, MANIFEST, invoke)

    def test_missing_receipt_blocks_next_cell_and_cleans(self):
        calls = []
        def invoke(cell, stage, owner):
            calls.append((cell, stage))
            return harmless(sample(cell, stage, owner)) if stage != 'preflight' else a7.bounded_worker([sys.executable, '-c', 'pass'])
        result = a7.run_matrix(self.output, MANIFEST, invoke)
        self.assertFalse(result['passed'])
        self.assertEqual(calls, [('1000', 'seed'), ('1000', 'preflight'), ('1000', 'cleanup')])
        self.assertEqual(result['first_failure']['stage'], 'preflight')
        self.assertTrue(result['cleanup_complete'])

    def test_incomplete_closure_blocks_even_cleanup(self):
        calls = []
        def invoke(cell, stage, owner):
            calls.append((cell, stage))
            worker = harmless(sample(cell, stage, owner))
            # Explicit host fault injection for otherwise difficult unclosed-EOF branch.
            worker.update(closed=False, stdout_eof=False, first_failure='owned-injected-incomplete-EOF')
            return worker
        result = a7.run_matrix(self.output, MANIFEST, invoke)
        self.assertEqual(len(calls), 1)
        self.assertFalse(result['closure_complete'])
        self.assertFalse(result['cleanup_complete'])

    def test_producer_failure_preserved_before_cleanup_failure(self):
        def invoke(cell, stage, owner):
            value = sample(cell, stage, owner)
            if stage in ('preflight', 'cleanup'):
                value.update(ok=False, first_failure='FIRST' if stage == 'preflight' else 'SECOND')
            return harmless(value)
        result = a7.run_matrix(self.output, MANIFEST, invoke)
        self.assertIn('FIRST', result['first_failure']['error'])
        self.assertFalse(result['cleanup_complete'])

    def test_selector_uses_exact_package_root_and_source_harness(self):
        for selector in ('source', 'package'):
            command = a7.command_for(['owned-cove', 'wp', 'owned-site', '--user=1'], '/frozen/source', '/owned/' + selector,
                                     '/frozen/fixtures', '20000', 'reverse', 'a' * 32)
            self.assertIn('--require=/frozen/source/tests/integration/storage-a7-bootstrap.php', command)
            self.assertIn("'/owned/" + selector + "'", command[-1])
            self.assertIn("require '/frozen/source/tests/integration/storage-operations.php'", command[-1])
            self.assertIn("'storage-calibration-a7-gallery'", command[-1])

    def test_fixture_freeze_real_producer_new_only(self):
        path = Path(self.temp.name) / 'fixtures'
        result = subprocess.run(['php', str(ROOT / 'tests/integration/storage-a7-fixtures.php'), str(path)], capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), MANIFEST)
        self.assertEqual(json.loads((path / 'manifest.json').read_text()), MANIFEST)
        self.assertEqual((path / 'manifest.json').read_bytes(), (ROOT / 'tests/integration/storage-a7-manifest.json').read_bytes())
        self.assertEqual(result.stdout, (path / 'manifest.json').read_bytes())
        for cell in a7.CELLS:
            raw = (path / (cell + '.serialized')).read_bytes()
            self.assertEqual(len(raw), MANIFEST['cells'][cell]['raw_bytes'])
            self.assertEqual(a7.hashlib.sha256(raw).hexdigest(), MANIFEST['cells'][cell]['sha256'])
        again = subprocess.run(['php', str(ROOT / 'tests/integration/storage-a7-fixtures.php'), str(path)], capture_output=True)
        self.assertNotEqual(again.returncode, 0)

    def test_final_source_package_consumer_requires_all_selectors_and_cleanup(self):
        # Entirely synthetic host fixtures, executed through harmless workers.
        template = Path(self.temp.name) / 'template'
        a7.run_matrix(template, MANIFEST, lambda c, s, o: harmless(sample(c, s, o)))
        self.output.mkdir()
        (self.output / 'raw').mkdir()
        (self.output / 'fixtures').mkdir()
        (self.output / 'fixtures/manifest.json').write_text(json.dumps(MANIFEST))
        (self.output / 'candidate.txt').write_text('a' * 40)
        (self.output / 'package-sha256.txt').write_text('b' * 64)
        (self.output / 'source-status-after.txt').write_text('')
        (self.output / 'cleanup.tsv').write_text('owned1\tdb1\tabsent-root-and-database\nowned2\tdb2\tabsent-root-and-database\n')
        rows = ['case\tresult\texit\tdb_equal']
        for kind in ('source', 'package'):
            for scope in ('single', 'multi-1', 'multi-2'):
                ident = kind + '-' + scope + '-storage-calibration-a7-gallery'
                shutil.copytree(template, self.output / 'raw' / ident)
                rows.append(ident + '\tPASS\t0\ttrue')
        (self.output / 'ledger.tsv').write_text('\n'.join(rows) + '\n')
        self.assertEqual(a7.finalize(self.output, 0), 0)
        terminal = json.loads((self.output / 'terminal-acceptance.json').read_text())
        self.assertTrue(terminal['passed'] and terminal['completed_cleanup'])
        self.assertFalse(terminal['profile_approved'])
        # Semantic mutation in a source/package producer cannot survive final readback.
        damaged = self.output / 'raw/package-multi-2-storage-calibration-a7-gallery/20000-forward.stdout'
        producer = json.loads(damaged.read_text())
        producer['observations']['logical_sha256'] = '0' * 64
        damaged.write_text(json.dumps(producer))
        with self.assertRaises(ValueError):
            a7.envelope([damaged.parent], MANIFEST)

    def test_refusal_preserves_authority_and_source(self):
        value = sample('over-bound', 'refusal')
        value['after'] = {'mutated': True}
        with self.assertRaises(ValueError):
            a7.validate(value, 'over-bound', 'refusal', 'a' * 32, MANIFEST)

    def test_finalize_failure_has_no_invented_envelope(self):
        self.output.mkdir()
        (self.output / 'candidate.txt').write_text('a' * 40)
        (self.output / 'package-sha256.txt').write_text('b' * 64)
        self.assertEqual(a7.finalize(self.output, 1), 1)
        value = json.loads((self.output / 'candidate-envelope.json').read_text())
        self.assertIsNone(value['measured_policy_candidate'])
        self.assertFalse(json.loads((self.output / 'terminal-acceptance.json').read_text())['passed'])


if __name__ == '__main__':
    unittest.main(verbosity=1)
