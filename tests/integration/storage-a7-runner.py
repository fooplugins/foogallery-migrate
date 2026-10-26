#!/usr/bin/env python3
"""Finite A7 orchestration/consumer. Host tests use owned harmless processes only.
Exit/reap and BOTH EOFs share a monotonic deadline. No communicate()/unbounded wait.
This is not a full process-group teardown proof or a calibration approver.
"""
import argparse
import hashlib
import json
import math
import os
import re
from pathlib import Path
import selectors
import signal
import subprocess
import sys
import time
import uuid

CELLS = ('1000', '2000', '10000', '20000', 'over-bound')
STAGES = ('seed', 'preflight', 'forward', 'reverse', 'cleanup')
POSITIVE = 'a7-options-utf8mb4-20k-v1'
POSITIVE_STAGES = ('seed', 'forward', 'backup', 'reverse', 'cleanup')
LIMIT = 262144


def save(path, value):
    with Path(path).open('x') as stream:
        json.dump(value, stream, indent=2, sort_keys=True)
        stream.write('\n')


def bounded_worker(command, seconds=45, limit=LIMIT):
    """Returns bounded actual bytes, first failure, EOF/reap/resource observations."""
    started = time.monotonic()
    result = dict(first_failure=None, reaped=False, stdout_eof=False,
                  stderr_eof=False, exit=None, full_process_group_teardown=False)
    buffers = {'stdout': bytearray(), 'stderr': bytearray()}
    try:
        child = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                 start_new_session=True)
    except OSError as error:
        return dict(result, first_failure='spawn:' + str(error), closed=False,
                    seconds=time.monotonic() - started, stdout=b'', stderr=b'')
    selector = selectors.DefaultSelector()
    for name, pipe in (('stdout', child.stdout), ('stderr', child.stderr)):
        os.set_blocking(pipe.fileno(), False)
        selector.register(pipe, selectors.EVENT_READ, name)
    deadline, killed = started + seconds, False
    try:
        while not (result['reaped'] and not selector.get_map()):
            now = time.monotonic()
            if now >= deadline:
                result['first_failure'] = result['first_failure'] or 'watchdog:reap-and-both-EOF'
                if killed:
                    break
                try:
                    os.killpg(child.pid, signal.SIGKILL)  # only this new owned group
                except ProcessLookupError:
                    pass
                killed, deadline = True, now + 1.0
            for key, _ in selector.select(min(0.02, max(0, deadline - now))):
                chunk = os.read(key.fileobj.fileno(), 8192)
                name = key.data
                if not chunk:
                    result[name + '_eof'] = True
                    selector.unregister(key.fileobj)
                    key.fileobj.close()
                else:
                    cap = limit if name == 'stdout' else 65536
                    available = cap - len(buffers[name])
                    buffers[name].extend(chunk[:max(0, available)])
                    if len(chunk) > available:
                        result['first_failure'] = result['first_failure'] or 'output-bound:' + name
                        if not killed:
                            deadline = min(deadline, time.monotonic())
            if not result['reaped']:
                pid, status, usage = os.wait4(child.pid, os.WNOHANG)
                if pid:
                    child.returncode = os.waitstatus_to_exitcode(status)
                    result.update(reaped=True, exit=child.returncode,
                                  outer_ru_maxrss=usage.ru_maxrss,
                                  outer_rss_unit='bytes' if sys.platform == 'darwin' else 'KiB')
        if result['exit'] != 0:
            result['first_failure'] = result['first_failure'] or 'worker-exit:' + str(result['exit'])
    finally:
        for key in list(selector.get_map().values()):
            selector.unregister(key.fileobj)
            key.fileobj.close()
        selector.close()
    result.update(closed=result['reaped'] and result['stdout_eof'] and result['stderr_eof'],
                  seconds=time.monotonic() - started,
                  stdout=bytes(buffers['stdout']), stderr=bytes(buffers['stderr']))
    return result


def require(condition, label):
    if not condition:
        raise ValueError(label)


def finite_number(value):
    return type(value) in (int, float) and math.isfinite(value) and value >= 0


def validate_common(receipt, cell, stage, owner, manifest):
    """Independent identity and cumulative resources for every lane/stage."""
    require(receipt.get('format') == 'a7-worker-v1' and receipt.get('producer') == 'real-StorageOperations-WP-SQL', 'missing-producer')
    require((receipt.get('cell'), receipt.get('stage'), receipt.get('owner')) == (cell, stage, owner), 'wrong-worker-binding')
    require(receipt.get('status') == 'measurement_only' and receipt.get('method_approved') is False and receipt.get('profile_approved') is False, 'approval-confusion')
    require(receipt.get('fixture') == manifest['cells'][cell], 'fixture-mismatch')
    require(receipt.get('ok') is True and receipt.get('first_failure') is None, 'producer-failure:' + str(receipt.get('first_failure')))
    require(type(receipt.get('blog_id')) is int and receipt['blog_id'] > 0, 'blog-identity')
    require(isinstance(receipt.get('runtime'), list) or isinstance(receipt.get('runtime'), dict), 'runtime-identity')
    require(isinstance(receipt.get('fingerprint'), str) and re.fullmatch('[a-f0-9]{64}', receipt['fingerprint']) is not None, 'runtime-fingerprint')
    resources = receipt['resources']
    require(resources.get('peaks_include_bootstrap') is True and resources.get('peaks_reset_or_subtracted') is False, 'peak-reset')
    for sample in (receipt['bootstrap'], resources):
        for field in ('live', 'allocated', 'peak_live_cumulative', 'peak_allocated_cumulative'):
            require(finite_number(sample.get(field)), 'missing-resource:' + field)
        require(sample['peak_live_cumulative'] >= sample['live'] and sample['peak_allocated_cumulative'] >= sample['allocated'], 'invalid-peak')
        require(finite_number(sample.get('rusage', {}).get('ru_maxrss')) and sample.get('rss_unit') in ('bytes', 'KiB'), 'missing-RSS')
    require(resources['peak_live_cumulative'] >= receipt['bootstrap']['peak_live_cumulative'] and resources['peak_allocated_cumulative'] >= receipt['bootstrap']['peak_allocated_cumulative'], 'bootstrap-peak-lost')
    require(finite_number(receipt.get('seconds')) and receipt['seconds'] <= 45, 'worker-elapsed')


def validate(receipt, cell, stage, owner, manifest):
    validate_common(receipt, cell, stage, owner, manifest)
    if stage == 'cleanup':
        require(receipt['observations'].get('cleanup_completed') is True, 'missing-cleanup')
        require(all(v is None for values in receipt['after'].values() for v in values.values()), 'cleanup-not-absent')
    elif stage == 'seed':
        source = receipt['observations']['seed']['options']['foogallery-migrate-data']
        require(source == dict(bytes=manifest['cells'][cell]['raw_bytes'], sha256=manifest['cells'][cell]['sha256']), 'seed-readback')
    else:
        require(receipt.get('milestones_valid') is True, 'invalid-milestones')
        marks = receipt.get('milestones', [])
        names = [mark['name'] for mark in marks]
        require(names[:3] == ['receiver-entry', 'authorized', 'policy'], 'receiver-order')
        last = 0
        for mark in marks:
            require(finite_number(mark['time']) and mark['time'] >= last, 'phase-time')
            for field in ('live', 'allocated', 'peak_live_cumulative', 'peak_allocated_cumulative'):
                require(finite_number(mark[field]), 'phase-resource')
            last = mark['time']
        require(type(receipt.get('response_bytes')) is int and 0 < receipt['response_bytes'] <= 1024, 'response-bound')
        authority, result = receipt['authority'], receipt['result']
        if stage == 'refusal':
            require(result == {'error': 'foogallery_migrate_storage_decode_refused'}, 'wrong-refusal')
            require(receipt['before'] == receipt['after'] and authority == {'control': None, 'sentinel': None}, 'refusal-mutated')
            require(not any(name.startswith('control-') for name in names), 'refusal-control-phase')
        elif stage == 'preflight':
            require(result.get('decode_admitted') is True and result.get('conversion_ready') is False and result.get('raw_bytes') == manifest['cells'][cell]['raw_bytes'], 'preflight-semantics')
            require(receipt['before'] == receipt['after'], 'preflight-mutated')
        else:
            target = 'table' if stage == 'forward' else 'option'
            epoch = 1 if stage == 'forward' else 2
            require(set(result) == {'blog_id', 'backend', 'epoch', 'phase'} and result == dict(blog_id=receipt['blog_id'], backend=target, epoch=epoch, phase='stable'), 'response-semantics')
            control = authority['control']
            require(control['backend'] == target and control['phase'] == 'stable' and control['epoch'] == epoch and control['blog_id'] == receipt['blog_id'], 'authority-semantics')
            require(control['logical_digest'] == manifest['cells'][cell]['logical_sha256'] == receipt['observations']['logical_sha256'], 'logical-parity')
            require([name for name in names if name.startswith('control-')] == ['control-copying', 'control-verified', 'control-flipped', 'control-stable'], 'authority-phase-order')
            phases = ['source-verified', 'control-copying']
            phases += (['snapshot-preflight', 'snapshot-staged', 'snapshot-readback', 'snapshot-committed'] if stage == 'forward' else ['reverse-serialized', 'reverse-written', 'source-read', 'source-decoded'])
            phases += ['control-verified', 'control-flipped', 'control-stable', 'released', 'response']
            cursor = -1
            for name in phases:
                cursor = names.index(name, cursor + 1)
            if stage == 'forward':
                require(names.index('source-read') < names.index('source-decoded') < names.index('source-verified') and 'retained-backup' in names, 'source-phase-order')
                require(isinstance(authority['sentinel'], dict) and authority['sentinel']['epoch'] == epoch, 'table-sentinel')
            else:
                require(authority['sentinel'] is None, 'reverse-sentinel-retained')
                require(isinstance(receipt.get('retained_table_before'), str) and re.fullmatch('[a-f0-9]{64}', receipt['retained_table_before']) is not None and receipt['retained_table_before'] == receipt.get('retained_table_after'), 'retained-table-byte-parity')
            options = receipt['after']['options']
            expected = dict(bytes=manifest['cells'][cell]['raw_bytes'], sha256=manifest['cells'][cell]['sha256'])
            require(options['foogallery-migrate-data-storage-backup'] == expected, 'backup-parity')
            require(options['foogallery-migrate-data'] == (None if stage == 'forward' else expected), 'source-option-parity')
    return receipt


def validate_positive(p, cell, stage, owner, manifest):
    require(cell == POSITIVE and re.fullmatch('[a-f0-9]{32}', owner), 'positive-binding')
    fixture = manifest['fixture']
    exact = dict(bytes=fixture['raw_bytes'], sha256=fixture['sha256'])
    if stage != 'backup':
        validate(p, cell, stage, owner, manifest)
    else:
        validate_common(p, cell, stage, owner, manifest)
        require(p['before'] == p['after'] and p['result'] == exact, 'backup-mutated-or-inexact')
        control = p['backup_authority']
        require((control['backend'], control['epoch'], control['phase'], control['blog_id'], control['logical_digest']) ==
                ('table', 1, 'stable', p['blog_id'], fixture['logical_sha256']), 'backup-authority')
    require(p['product_pins_before'] == p['product_pins_after'] and bool(p['product_pins_before']), 'product-mutated')
    required = ['StorageOperations', 'StorageConversion', 'LegacyStateCodec', 'LegacyTableSnapshot', 'StorageControl']
    for name in required:
        entry = p['product_classes'][chr(92).join(('FooPlugins', 'FooGalleryMigrate', name))]
        relative = str(Path(entry['file']).relative_to(p['product_root']))
        require(p['product_pins_after'][relative] == entry['sha256'], 'product-class-pin')
    if stage == 'seed' or stage == 'reverse':
        seed = p['positive_seed'] if stage == 'seed' else p['reverse_readback']
        require(seed == dict(format='a7-options-seed-v1', corpus=POSITIVE, stage='seed', owner=owner, blog_id=p['blog_id'],
                status='measurement_only', contract_decision='pending', method_approved=False, profile_approved=False,
                outcome='SUCCESS', first_failure=None, fixture=fixture, persisted_sql=exact, wp_logical_sha256=fixture['logical_sha256']), 'positive-seed-inexact')
    if stage in ('forward', 'backup', 'reverse'):
        fresh = p['fresh_read']
        name = 'foogallery-migrate-data' if stage == 'forward' else 'foogallery-migrate-data-storage-backup'
        require(fresh == dict(option=name, sql=exact, wp_logical_sha256=fixture['logical_sha256'], wp_serialized_sha256=fixture['sha256']), 'fresh-read-mismatch')
    return p


def php_string(value):
    return "'" + str(value).replace('\\', '\\\\').replace("'", "\\'") + "'"


def command_for(base, source, plugin, fixtures, cell, stage, owner, lane='original', seed_receipt=None):
    values = {'FOOGM_STORAGE_TEST_DISPOSABLE': True,
              'FOOGM_STORAGE_TEST_MODE': 'storage-calibration-a7-gallery',
              'FOOGM_STORAGE_TEST_ROOT': plugin, 'FOOGM_A7_FIXTURES': fixtures,
              'FOOGM_A7_CELL': cell, 'FOOGM_A7_STAGE': stage, 'FOOGM_A7_OWNER': owner}
    if lane == 'native-positive':
        values['FOOGM_STORAGE_TEST_MODE'] = 'storage-a7-native-positive'
        values['FOOGM_A7_SEED_RECEIPT'] = seed_receipt
    code = ''.join('define(%s,%s);' % (php_string(key), 'true' if val is True else php_string(val)) for key, val in values.items())
    code += 'require ' + php_string(Path(source) / 'tests/integration/storage-operations.php') + ';'
    return list(base) + ['--require=' + str(Path(source) / 'tests/integration/storage-a7-bootstrap.php'), 'eval', code]


def run_matrix(output, manifest, invoke, lane='original'):
    """Stop at first failure. Only exact-owner cleanup follows a closed failure."""
    output = Path(output)
    output.mkdir(mode=0o700)  # new-only, never retry over evidence
    summary = dict(format='a7-matrix-v1', status='measurement_only', method_approved=False,
                   profile_approved=False, first_failure=None, closure_complete=True,
                   receipts=[], completed_cells=[], cleanup_complete=True)
    cells = (POSITIVE,) if lane == 'native-positive' else CELLS
    for cell in cells:
        owner = uuid.uuid4().hex
        sequence = POSITIVE_STAGES if lane == 'native-positive' else (('seed', 'refusal', 'cleanup') if cell == 'over-bound' else STAGES)
        failed, seeded = False, False
        for stage in sequence:
            if failed and stage != 'cleanup':
                continue
            worker = invoke(cell, stage, owner)
            stdout, stderr = worker.pop('stdout'), worker.pop('stderr')
            stem = output / (cell + '-' + stage)
            stem.with_suffix('.stdout').write_bytes(stdout)
            stem.with_suffix('.stderr').write_bytes(stderr)
            error = worker['first_failure']
            producer = None
            if not error:
                try:
                    require(not stderr, 'unexpected-stderr')
                    producer = json.loads(stdout)
                    if lane == 'native-positive':
                        validate_positive(producer, cell, stage, owner, manifest)
                    else:
                        validate(producer, cell, stage, owner, manifest)
                except (ValueError, KeyError, TypeError, IndexError, AttributeError) as exc:
                    error = 'receipt:' + str(exc)
            worker.update(producer=producer, validation_failure=error)
            save(stem.with_suffix('.json'), worker)
            summary['receipts'].append(stem.name + '.json')
            if stage == 'seed':
                seeded = not error
            if error:
                summary['first_failure'] = summary['first_failure'] or dict(cell=cell, stage=stage, error=error)
                failed = True
            if not worker['closed']:
                summary['closure_complete'] = False
                summary['cleanup_complete'] = False
                break
            if stage == 'cleanup' and error:
                summary['cleanup_complete'] = False
            if stage == 'seed' and not seeded:
                # An incomplete seed may have acquired an owner. Cleanup verifies
                # that exact owner; it cannot delete pre-existing evidence.
                failed = True
        if failed or not summary['closure_complete']:
            break
        summary['completed_cells'].append(cell)
    summary['passed'] = summary['first_failure'] is None and summary['completed_cells'] == list(cells) and summary['closure_complete'] and summary['cleanup_complete']
    save(output / 'summary.json', summary)
    return summary


def envelope(matrices, manifest):
    """No baseline subtraction: 2x whole-process peaks plus explicit fixed reserve.
    This deliberately conservative candidate remains unapproved and CLI-only.
    """
    peaks, ratios, hashes, fingerprints = [], [], [], set()
    for directory in matrices:
        summary = json.loads((directory / 'summary.json').read_text())
        require(summary['passed'] is True and summary['completed_cells'] == list(CELLS), 'incomplete-matrix')
        expected_names = [cell + '-' + stage + '.json' for cell in CELLS for stage in (('seed', 'refusal', 'cleanup') if cell == 'over-bound' else STAGES)]
        require(summary['receipts'] == expected_names, 'missing-worker-stages')
        for name in summary['receipts']:
            path = directory / name
            worker = json.loads(path.read_text())
            require(worker['closed'] and worker['exit'] == 0 and worker['validation_failure'] is None, 'incomplete-worker')
            p = worker['producer']
            require(json.loads(path.with_suffix('.stdout').read_bytes()) == p and path.with_suffix('.stderr').read_bytes() == b'', 'producer-artifact-mismatch')
            validate(p, p['cell'], p['stage'], p['owner'], manifest)
            if p['stage'] in ('forward', 'reverse'):
                peak = p['resources']['peak_allocated_cumulative']
                peaks.append(peak)
                ratios.append(math.ceil(peak / p['fixture']['raw_bytes']))
                fingerprints.add(p['fingerprint'])
            hashes.append(dict(path=str(path), sha256=hashlib.sha256(path.read_bytes()).hexdigest()))
    require(len(fingerprints) == 1, 'source-package-runtime-fingerprint-mismatch')
    return dict(status='measurement_only', method_approved=False, profile_approved=False,
                domain='PHP>=7 native CLI, exact recorded runtime/allocator/product, disposable supported hooks only',
                measured_policy_candidate=dict(max_raw_bytes=manifest['cells']['20000']['raw_bytes'], amplification=2 * max(ratios), reserve_bytes=33554432),
                derivation=dict(max_full_worker_allocated_peak=max(peaks), max_peak_to_raw_ceil=max(ratios), margin=2,
                                subtraction=False, fixed_reserve_bytes=33554432,
                                reserve_rationale='Proposed 32MiB phase/response reserve retained from experimental routing policy; adequacy REQUIRES independent methodology review, not claimed proven.'),
                boundary='one exact +1-byte refusal against held-out byte ceiling; candidate margin/reserve not replayed as an approved profile',
                receipt_hashes=hashes, fingerprint=next(iter(fingerprints)),
                exclusions=['HTTP/FPM/UI', 'PHP<7/literal floors', 'remaining A7 corpus', 'recovery/retained replacement', 'arbitrary hooks', 'production profile approval'])


def finalize(root, exit_code):
    root = Path(root)
    first, proposed = None, None
    try:
        require(exit_code == 0, 'outer-run-exit:' + str(exit_code))
        rows = (root / 'ledger.tsv').read_text().splitlines()[1:]
        ids = [kind + '-' + scope + '-storage-calibration-a7-gallery' for kind in ('source', 'package') for scope in ('single', 'multi-1', 'multi-2')]
        parsed = [row.split('\t') for row in rows]
        require(len(parsed) == len(ids) and {row[0] for row in parsed} == set(ids), 'missing-selector-ledger')
        require(all(row[1:] == ['PASS', '0', 'true'] for row in parsed), 'selector-or-nonowned-mismatch')
        cleanup = (root / 'cleanup.tsv').read_text().splitlines()
        require(len(cleanup) == 2 and all(row.endswith('\tabsent-root-and-database') for row in cleanup), 'missing-exact-cleanup')
        require((root / 'source-status-after.txt').read_text() == '', 'canonical-not-clean')
        manifest = json.loads((root / 'fixtures/manifest.json').read_text())
        proposed = envelope([root / 'raw' / ident for ident in ids], manifest)
        save(root / 'candidate-envelope.json', proposed)
    except (OSError, ValueError, KeyError, TypeError, IndexError) as error:
        first = str(error)
        # Preserve the actual earliest retained worker failure, not a generic exit.
        for path in sorted((root / 'raw').glob('*/summary.json'), key=lambda p: p.stat().st_mtime_ns):
            candidate = json.loads(path.read_text()).get('first_failure')
            if candidate:
                first = candidate
                break
        save(root / 'candidate-envelope.json', dict(status='measurement_only', method_approved=False, profile_approved=False,
                                                  measured_policy_candidate=None, first_failure=first, observations_not_invented=True))
    save(root / 'terminal-acceptance.json', dict(status='measurement_only', method_approved=False, profile_approved=False,
         passed=first is None, first_failure=first,
         completed_cleanup=(root / 'cleanup.tsv').exists() and len((root / 'cleanup.tsv').read_text().splitlines()) == 2 and all(row.endswith('\tabsent-root-and-database') for row in (root / 'cleanup.tsv').read_text().splitlines()) and not (root / 'incomplete-closure.json').exists(),
         candidate=(root / 'candidate.txt').read_text().strip(), package_sha256=(root / 'package-sha256.txt').read_text().strip(),
         candidate_envelope_sha256=hashlib.sha256((root / 'candidate-envelope.json').read_bytes()).hexdigest()))
    return 0 if first is None else 1


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--finalize', nargs=2, metavar=('OUT', 'EXIT'))
    parser.add_argument('--lane', choices=('original', 'native-positive'), default='original')
    parser.add_argument('--source')
    parser.add_argument('--plugin')
    parser.add_argument('--fixtures')
    parser.add_argument('--out')
    parser.add_argument('command', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    if args.finalize:
        return finalize(args.finalize[0], int(args.finalize[1]))
    manifest = json.loads((Path(args.fixtures) / 'manifest.json').read_text())
    name = 'storage-a7-options-manifest.json' if args.lane == 'native-positive' else 'storage-a7-manifest.json'
    expected = json.loads((Path(args.source) / 'tests/integration' / name).read_text())
    require(manifest == expected, 'unfrozen-corpus-manifest')
    cells = (POSITIVE,) if args.lane == 'native-positive' else CELLS
    if args.lane == 'native-positive':
        manifest = dict(manifest, cells={POSITIVE: manifest['fixture']})
    for cell in cells:
        raw = (Path(args.fixtures) / (cell + '.serialized')).read_bytes()
        require(len(raw) == manifest['cells'][cell]['raw_bytes'] and hashlib.sha256(raw).hexdigest() == manifest['cells'][cell]['sha256'], 'frozen-corpus-bytes')
    base = args.command[1:] if args.command[:1] == ['--'] else args.command
    require(bool(base), 'missing-worker-command')
    summary = run_matrix(args.out, manifest, lambda cell, stage, owner: bounded_worker(command_for(
        base, args.source, args.plugin, args.fixtures, cell, stage, owner, args.lane,
        str(Path(args.out) / (cell + '-seed.stdout')))), args.lane)
    if not summary['closure_complete']:
        save(Path(args.out).parent.parent / 'incomplete-closure.json', dict(output=args.out, first_failure=summary['first_failure'], cleanup_forbidden=True))
    return 0 if summary['passed'] else 1


if __name__ == '__main__':
    sys.exit(main())
