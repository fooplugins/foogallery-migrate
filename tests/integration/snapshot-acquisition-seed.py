#!/usr/bin/env python3
"""Closed real native seed acquisition and independent scalar-stream semantics.
Never qualifies a profile or emits admission authority. All outputs are new-only.
"""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import math
import sys

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('bounded_native_worker', HERE / 'storage-a7-runner.py')
assert spec is not None and spec.loader is not None
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


def save(path, value):
    with Path(path).open('x') as stream:
        json.dump(value, stream, indent=2, sort_keys=True)
        stream.write('\n')


def digest(path):
    h = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for chunk in iter(lambda: stream.read(65536), b''):
            h.update(chunk)
    return h.hexdigest()


def validate(path, receipt):
    """Hash/framing plus actual seam order, fixed scalar bounds and guard debt.
    Does NOT pretend these observations prove a prospective private envelope.
    """
    assert digest(path) == receipt['trace_sha256'], 'trace hash'
    phases, maxima, count, last_time, guard = set(), {}, 0, 0, 0
    root_seen = reconstruction = payload = final = exited = False
    last_phase = None
    with Path(path).open('rb') as stream:
        header = json.loads(stream.readline(65537))
        assert header['format'] == 'snapshot-read-ndjson-v1'
        assert header['trace']['pid'] == receipt['pid']
        assert header['trace']['input'] == receipt['input']
        assert header['trace']['collector'] == receipt['classes']['StorageScalarObserver']
        while True:
            line = stream.readline(65537)
            assert line and len(line) <= 65536 and line.endswith(b'\n'), 'line bound'
            record = json.loads(line)
            if 'end' in record:
                assert record == {'end': count} and not stream.read(1), 'end/EOF'
                break
            e = record['event']; count += 1
            assert len(e) == 17 and all(type(v) in (int, float) and math.isfinite(v) and v >= 0 for v in e.values()), 'scalar schema'
            assert e['sequence'] == count and e['time'] >= last_time and e['guard'] >= guard
            assert 1 <= e['phase'] <= 40 and e['depth'] <= 64
            if e['phase'] == 1:
                assert e['bytes'] == 0, 'legacy-only selector, including checked reentry'
                if 1 not in phases:
                    assert not root_seen, 'initial actual pre-root selector'
            if e['phase'] == 2:
                assert 1 in phases, 'selector before root native decode'
                root_seen = True
            if e['phase'] == 6:
                assert root_seen
                reconstruction = True
            if e['phase'] == 4:
                assert reconstruction
            if e['phase'] == 5:
                assert last_phase == 22, 'actual native payload buffered/assoc/free chain'
                payload = True
            if e['phase'] == 27:
                assert 17 in phases
                final = True
            if e['phase'] == 19:
                assert final and last_phase == 28
                exited = True
            if e['phase'] == 30:
                assert e['reserve'] == 33554432 and e['pending'] >= 262144, 'unchanged reserve and observer debt'
                assert e['amplification'] in (1, 64) and e['ceiling'] == 268435456
                forecast = e['forecast'] * e['amplification'] + e['pending'] + e['backing'] + e['reserve']
                assert forecast <= 268435456, 'actual legacy admitted inequality'
                maxima['legacy_guard_total'] = max(maxima.get('legacy_guard_total', 0), forecast)
            for k in ('live', 'allocated', 'peak_live_cumulative', 'peak_allocated_cumulative', 'pending', 'bytes'):
                maxima[k] = max(maxima.get(k, 0), e[k])
            last_time, guard, last_phase = e['time'], e['guard'], e['phase']
            phases.add(e['phase'])
    assert count == receipt['events'] and 0 < count <= 200000
    assert root_seen and reconstruction and payload and final and exited
    assert set(range(1, 29)) <= phases, 'missing actual seam'
    assert receipt['ok'] and not receipt['profile_approved'] and not receipt['certificate_selected'] and not receipt['production_enabled']
    assert all(receipt['cleanup_readback'].values())
    assert receipt['output_digest'] == receipt['input']['digest']
    facts, head = receipt['sql']['facts'][0], receipt['sql']['head'][0]
    assert int(facts['records']) == int(head['record_count']) <= 1024
    assert int(facts['max_row_bytes']) <= 65536 and receipt['total_encoded_bytes'] <= 1048576
    assert head['digest'].lower() == receipt['input']['digest']
    return dict(events=count, phases=sorted(phases), maxima=maxima, pid=receipt['pid'],
                input=receipt['input'], encoded_bytes=receipt['total_encoded_bytes'], sql=receipt['sql'])


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--source', required=True, type=Path)
    ap.add_argument('--product', required=True, type=Path)
    ap.add_argument('--site', required=True, type=Path)
    ap.add_argument('--out', required=True, type=Path)
    ap.add_argument('--pilot', action='store_true')
    args = ap.parse_args()
    args.out.mkdir(exist_ok=False, parents=True)
    cells = [f'{s}-{n}' for s in ('scalar', 'dense', 'escaped', 'depth', 'wide', 'mixed') for n in (16, 64, 256)] + ['UNUSED128', 'UNUSED256']
    save(args.out / 'declared-domain.json', dict(cells=cells, repeats=3, encoded_bytes=1048576, row_bytes=65536,
        records=1024, depth=64, held_out=['UNUSED128', 'UNUSED256'], unused_before_this_run=True,
        production_enabled=False, driver=str(Path(__file__).resolve()), driver_sha256=digest(__file__)))
    if args.pilot:
        cells = ['scalar-16']
    rows, pids = [], set()
    for cell in cells:
        for repeat in range(1, 4):
            dest = args.out / (cell + '-' + str(repeat)); dest.mkdir()
            definitions = dict(FOOGM_SEED_ROOT=str(args.product.resolve()), FOOGM_SEED_OUT=str(dest.resolve()),
                FOOGM_SEED_CELL=cell, FOOGM_SEED_DB='cove_fgm_seed_20261019_34032_')
            code = 'define("WP_USE_THEMES",false);require ' + json.dumps(str(args.site / 'wp-load.php')) + ';'
            code += ''.join('define(' + json.dumps(k) + ',' + json.dumps(v) + ');' for k, v in definitions.items())
            code += 'require ' + json.dumps(str(args.source / 'tests/integration/snapshot-acquisition-seed.php')) + ';'
            command = ['/opt/homebrew/bin/php', '-d', 'memory_limit=256M', '-r', code]
            result = worker.bounded_worker(command, seconds=45)
            for stream in ('stdout', 'stderr'):
                (dest / (stream + '.txt')).write_bytes(result.pop(stream))
            save(dest / 'worker.json', dict(result, command=command))
            try:
                assert result['closed'] and result['exit'] == 0 and result['first_failure'] is None, 'native worker failure'
                receipt = json.loads((dest / 'receipt.json').read_text())
                row = validate(dest / 'trace.ndjson', receipt)
                assert row['pid'] not in pids, 'fresh process PID reuse'
                pids.add(row['pid']); rows.append(dict(row, cell=cell, repeat=repeat, artifact=str(dest)))
                save(dest / 'semantics.json', row)
            except Exception as error:
                save(args.out / 'failure.json', dict(cell=cell, repeat=repeat, reason=str(error), type=type(error).__name__))
                print('RED', cell, repeat, str(error)); return 2
    save(args.out / 'summary.json', dict(status='MEASURED_EVIDENCE_PENDING_RECEIVING', count=len(rows), fresh_pids=sorted(pids), rows=rows,
        method_approved=False, profile_approved=False, production_enabled=False, acquisition_authority=False))
    print('native seed observations:', len(rows), 'fresh processes; semantic checks passed; NO calibration approval')
    return 0


if __name__ == '__main__':
    sys.exit(main())
