#!/usr/bin/env python3
"""Finite historical/current source+actual-ZIP diagnostics, NOT final acceptance.

No checkout, reset, clean, deploy, download, fixture/VM owner or shared-tree writes.
Requires a clean committed candidate and existing local npm/composer dev deps.
Every artifact, including failed producers, stays in a fresh explicit output dir.
"""
import argparse
from collections import Counter
import hashlib
import io
import json
from pathlib import Path
import re
import shutil
import subprocess
import tarfile
import time
import xml.etree.ElementTree as ET
import zipfile

BASE = '11bd3b6a0b7ef4436f5a07dca83901e316c21062'
HISTORICAL = '11ba73abb4264fa1e4c49ae42d509d79c2af3767'
FAMILIES = ('FixedHelperBounds', 'ScannerTraceWitness', 'ScannerTraceCapacity',
            'ProductCallerTrace', 'ProductArgumentRetention', 'ReceiverNewComposition',
            'ReceiverScalarEvents', 'DiagnosticSuccessor')
ROOT = Path(__file__).resolve().parents[2]


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def json_write(path, value):
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + '\n')


def tracked(commit):
    data = subprocess.check_output(['git', 'ls-tree', '-r', '--name-only', '-z', commit], cwd=ROOT)
    return data.decode().rstrip('\0').split('\0')


def manifest(root, names):
    return {name: digest(root / name) for name in names}


def archive(commit, target, out):
    data = subprocess.check_output(['git', 'archive', '--format=tar', commit], cwd=ROOT)
    (out / (target.name + '.tar')).write_bytes(data)
    target.mkdir()
    with tarfile.open(fileobj=io.BytesIO(data)) as tar:
        # The host's python3 can predate tarfile's filter keyword. Git archive
        # has regular files/directories here; refuse every link/special member
        # rather than falling back to unfiltered arbitrary extraction.
        for member in tar.getmembers():
            name = Path(member.name)
            if name.is_absolute() or '..' in name.parts or not (member.isfile() or member.isdir()):
                raise RuntimeError('Unsupported/unsafe source archive member: ' + member.name)
        tar.extractall(target)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', required=True, type=Path)
    args = parser.parse_args()
    out = args.output
    if not out.is_absolute() or out.exists():
        parser.error('--output must be a new absolute directory; nothing is removed/overwritten')
    out.mkdir(parents=True)
    report = {'status': 'RUNNING', 'commands': [], 'runs': {}, 'mutations': {}}
    json_write(out / 'results.json', report)

    def run(label, argv, cwd, expected=0, timeout=300):
        log = out / (label + '.log')
        entry = {'argv': list(map(str, argv)), 'cwd': str(cwd), 'log': str(log),
                 'expected_exit': expected, 'started': time.time()}
        report['commands'].append(entry)
        json_write(out / 'results.json', report)
        with log.open('wb') as stream:
            try:
                result = subprocess.run(argv, cwd=cwd, stdout=stream, stderr=subprocess.STDOUT, timeout=timeout)
                entry['producer_exit'] = result.returncode
            except subprocess.TimeoutExpired:
                entry['producer_exit'] = 'TIMEOUT'
        entry['log_sha256'] = digest(log)
        entry['finished'] = time.time()
        json_write(out / 'results.json', report)
        if entry['producer_exit'] != expected:
            raise RuntimeError(f'{label}: producer {entry["producer_exit"]}, expected {expected}; {log}')
        return log.read_text(errors='replace')

    def dependencies(root):
        shutil.copytree(ROOT / 'vendor', root / 'vendor', symlinks=False)

    php = shutil.which('php')
    php_args = [php or 'php', '-d', 'memory_limit=256M', '-d', 'opcache.enable_cli=0']
    test_args = php_args + ['vendor/bin/phpunit', '-c', 'phpunit.xml.dist', '--do-not-cache-result']

    def cases(xml):
        tree = ET.parse(xml)
        values = tree.findall('.//testcase')
        stats = {key: sum(len(t.findall(key)) for t in values) for key in ('failure', 'error', 'skipped', 'warning')}
        ids = []
        for test in values:
            name = re.sub(r' with data set #([0-9]+)$', r'#\1', test.attrib['name'])
            name = re.sub(r' with data set "(.*)"$', r'"\1"', name)
            ids.append(test.attrib['class'] + '::' + name)
        return ids, {'tests': len(values), 'assertions': sum(int(t.get('assertions', '0')) for t in values), **stats}

    def focused(label, root, current):
        suffixes = list(FAMILIES) + (['DiagnosticNativeScale'] if current else [])
        prefix = 'StorageCurrent' if current else 'Storage'
        pattern = r'\\' + prefix + '(' + '|'.join(suffixes) + r')Test::'
        # List-tests cannot honor --filter in PHPUnit9: discover original config,
        # then select the exact class IDs in our own recorded prerequisite step.
        listing = run(label + '-discovery', test_args + ['--list-tests'], root)
        all_ids = [s.strip()[2:] for s in listing.splitlines() if s.strip().startswith('- ')]
        expected = [s for s in all_ids if re.search(pattern, s)]
        if not expected or any(not any(prefix + family + 'Test::' in s for s in expected) for family in suffixes):
            raise RuntimeError(label + ': missing/nonzero family discovery prerequisite')
        json_write(out / (label + '-expected.json'), expected)
        before = manifest(root, report['roots'][label]['verified_files'])
        xml = out / (label + '.xml')
        run(label, test_args + ['--filter', pattern, '--log-junit', str(xml)], root)
        actual, stats = cases(xml)
        if Counter(actual) != Counter(expected) or any(stats[k] for k in ('failure', 'error', 'skipped', 'warning')):
            raise RuntimeError(label + ': JUnit coverage does not equal nonzero discovered obligations')
        if manifest(root, list(before)) != before:
            raise RuntimeError(label + ': root changed during diagnostics')
        report['runs'][label] = dict(stats, junit=str(xml), junit_sha256=digest(xml),
                                     expected=str(out / (label + '-expected.json')), immutable_verified=True)
        version = 'V3' if current else 'V2'
        fixed = 'StorageFixedHelperBoundsV2' if current else 'StorageFixedHelperBounds'
        probe = "require 'tests/bootstrap.php'; require 'tests/support/StorageReceiverNewComposition" + version + ".php'; "
        probe += "require_once 'tests/support/" + fixed + ".php'; $out=array();"
        probe += "foreach(array('5.4.0','5.6.20') as $v){$p='FooPlugins\\\\FooGalleryMigrate\\\\Tests\\\\';"
        probe += "$t=$p.'StorageProductCallerTrace" + version + "';$f=$p.'" + fixed + "';$r=$p.'StorageReceiverNewComposition" + version + "';"
        probe += "$m=new $t($v);$e=$m->envelope(60000,16,256);$b=new $f($v);$receiver=new $r($v);"
        probe += "$out[$v]=array('bindings'=>$receiver->bindings(),'descriptors'=>$b->descriptors(),'paths'=>$e['paths'],'case_count'=>count($e['cases']),'domain'=>$e['domain'],'max_joined_frames'=>$e['max_joined_frames'],'catch_nominal_upper'=>$e['catch_nominal_upper'],'construction_nominal_upper'=>$e['construction_nominal_upper'],'whole_operation_nominal_upper'=>$e['whole_operation_nominal_upper']);}echo json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);"
        model_json = json.loads(run(label + '-model', php_args + ['-r', probe], root))
        json_write(out / (label + '-model.json'), model_json)
        report['runs'][label]['model'] = str(out / (label + '-model.json'))
        report['runs'][label]['families'] = dict(Counter(s.split('::')[0] for s in actual))
        if manifest(root, list(before)) != before:
            raise RuntimeError(label + ': probe changed frozen root')
        json_write(out / 'results.json', report)
        return actual

    try:
        if not php or not shutil.which('npm') or not (ROOT / 'vendor/bin/phpunit').is_file() or not (ROOT / 'node_modules/.bin/gulp').is_file():
            raise RuntimeError('Missing local PHP/npm/composer/gulp prerequisite; no install is attempted')
        status = run('git-status-prerequisite', ['git', 'status', '--porcelain'], ROOT)
        if status.strip():
            raise RuntimeError('Candidate must be committed and CLEAN; shared state is not modified')
        candidate = run('candidate', ['git', 'rev-parse', 'HEAD'], ROOT).strip()
        run('ancestor', ['git', 'merge-base', '--is-ancestor', BASE, candidate], ROOT)
        report['candidate'] = candidate
        report['tree'] = run('tree', ['git', 'rev-parse', candidate + '^{tree}'], ROOT).strip()
        report['historical_commit'] = HISTORICAL
        run('php-version', [php, '-v'], ROOT)
        run('phpunit-version', test_args[:5] + ['vendor/bin/phpunit', '--version'], ROOT)
        run('node-version', ['node', '--version'], ROOT)
        run('npm-version', ['npm', '--version'], ROOT)
        run('python-version', ['python3', '--version'], ROOT)
        runtime = run('php-runtime', php_args + ['-r', 'echo json_encode(array("version"=>PHP_VERSION,"memory_limit"=>ini_get("memory_limit"),"opcache.enable_cli"=>ini_get("opcache.enable_cli"),"auto_prepend_file"=>ini_get("auto_prepend_file"),"auto_append_file"=>ini_get("auto_append_file"),"zend.exception_ignore_args"=>ini_get("zend.exception_ignore_args")));'], ROOT)
        report['runtime'] = json.loads(runtime)
        if report['runtime']['auto_prepend_file'] or report['runtime']['auto_append_file']:
            raise RuntimeError('Unreviewed auto prepend/append file')
        report['dev_dependency_pins'] = {name: digest(ROOT / name) for name in (
            'composer.lock', 'package-lock.json', 'vendor/composer/installed.json', 'vendor/bin/phpunit')}
        report['php_executable'] = {'path': php, 'sha256': digest(Path(php).resolve())}
        report['roots'] = {}
        roots = {}
        for domain, commit in [('historical', HISTORICAL), ('current', candidate)]:
            source = out / (domain + '-source')
            archive(commit, source, out)
            names = tracked(commit)
            source_manifest = manifest(source, names)
            json_write(out / (domain + '-tracked.json'), source_manifest)
            dependencies(source)
            roots[domain] = source
            report['roots'][domain + '-source'] = {'path': str(source), 'verified_files': names,
                'manifest': str(out / (domain + '-tracked.json')), 'harness': commit}
            build = out / (domain + '-build')
            archive(commit, build, out)
            (build / 'node_modules').symlink_to(ROOT / 'node_modules', target_is_directory=True)
            run(domain + '-build', ['npm', 'run', 'build'], build)
            package = json.loads((build / 'package.json').read_text())
            produced = build / 'dist' / (package['name'] + '.v' + package['version'] + '.zip')
            if not produced.is_file():
                raise RuntimeError(domain + ': successful build did not produce production ZIP')
            artifact = out / (domain + '-production.zip')
            shutil.copyfile(produced, artifact)
            zipped = out / (domain + '-zip')
            zipped.mkdir()
            zip_hashes = {}
            with zipfile.ZipFile(artifact) as z:
                for item in z.infolist():
                    p = Path(item.filename)
                    if p.is_absolute() or '..' in p.parts or p.parts[0] in ('vendor', 'tests', 'node_modules', 'bin'):
                        raise RuntimeError('Unsafe or dev-only production ZIP member: ' + item.filename)
                    if not item.is_dir():
                        zip_hashes[item.filename] = hashlib.sha256(z.read(item)).hexdigest()
                z.extractall(zipped)
            deltas = {name: sha for name, sha in zip_hashes.items() if source_manifest.get(name) != sha}
            # npm's normal translate task regenerates POT timestamp/content. No
            # other product difference (especially PHP) is silently allowed.
            if any(not name.startswith('languages/') or not name.endswith('.pot') for name in deltas):
                raise RuntimeError(domain + ': unexpected production ZIP/source byte delta ' + repr(deltas))
            source_php = {n: h for n, h in source_manifest.items() if n.startswith('includes/') and n.endswith('.php')}
            if not source_php or any(zip_hashes.get(n) != h for n, h in source_php.items()):
                raise RuntimeError(domain + ': ZIP omitted or changed product PHP')
            json_write(out / (domain + '-zip-members.json'), zip_hashes)
            shutil.copytree(source / 'tests', zipped / 'tests')
            for name in ('phpunit.xml.dist', 'composer.json', 'composer.lock'):
                shutil.copyfile(source / name, zipped / name)
            dependencies(zipped)
            zip_verified = sorted(set(zip_hashes) | {n for n in names if n.startswith('tests/')} | {'phpunit.xml.dist', 'composer.json', 'composer.lock'})
            report['roots'][domain + '-zip'] = {'path': str(zipped), 'verified_files': zip_verified,
                'harness': commit, 'product_origin': str(artifact), 'package_sha256': digest(artifact),
                'zip_members': len(zip_hashes), 'generated_pot_deltas': deltas,
                'member_manifest': str(out / (domain + '-zip-members.json'))}
            if manifest(source, names) != source_manifest:
                raise RuntimeError(domain + ': packaging contaminated immutable source')
            roots[domain + '-zip'] = zipped
        # Original files must match historical bytes, not just historical names.
        historical_names = tracked(HISTORICAL)
        originals = [n for n in historical_names if n.startswith(('tests/support/', 'docs/')) or any(n == 'tests/Storage' + f + 'Test.php' for f in FAMILIES)]
        changed_originals = [n for n in originals if digest(roots['historical'] / n) != digest(roots['current'] / n)]
        if changed_originals:
            raise RuntimeError('Changed historical obligations: ' + repr(changed_originals))
        if run('unchanged-existing-files', ['git', 'diff', '--name-only', BASE, candidate, '--diff-filter=MDR'], ROOT).strip():
            raise RuntimeError('This finite candidate must be additive, preserving every preexisting file')
        report['historical_files_preserved'] = len(originals)
        h = focused('historical-source', roots['historical'], False)
        hz = focused('historical-zip', roots['historical-zip'], False)
        c = focused('current-source', roots['current'], True)
        cz = focused('current-zip', roots['current-zip'], True)
        if Counter(h) != Counter(hz) or Counter(c) != Counter(cz):
            raise RuntimeError('Source/ZIP obligation drift')
        equivalent = Counter(s.replace('\\StorageCurrent', '\\Storage') for s in c)
        if Counter(h) - equivalent:
            raise RuntimeError('Current equivalents dropped an original provider/method obligation')
        report['original_obligations_current_equivalent'] = len(h)
        mutations = [
            ('descriptor-lowering', 'tests/support/StorageFixedHelperBoundsV2.php', '41 => 8', '41 => 0',
             'StorageCurrentFixedHelperBoundsTest::test_current_line_lowering', 1, 'Failed asserting'),
            ('zero-arg-as-raw', 'tests/support/StorageScannerTraceCapacityV2.php',
             "array( 'decode', 'scan', 'unsigned', 'token', 'literal' )", "array( 'decode', 'scan', 'unsigned', 'token', 'literal', 'tick' )",
             'StorageCurrentDiagnosticNativeScaleTest::test_zero_arg_tick_material', 1, 'Failed asserting'),
            ('decoder-source-domain', 'includes/class-legacy-option-decoder.php', None, '\n',
             'StorageCurrentDiagnosticSuccessorTest::test_originals_and_provenance', 2, 'fixed_helper_domain'),
            ('scale-source-domain', 'includes/class-storage-scale-admission.php', None, '\n',
             'StorageCurrentProductCallerTraceTest::test_exact_product_bindings', 2, 'diagnostic_binding:includes/class-storage-scale-admission.php')]
        for label, file, old, new, pattern, expected_exit, excerpt in mutations:
            root = out / ('mutation-' + label)
            archive(candidate, root, out)
            dependencies(root)
            target = root / file
            content = target.read_text()
            if old is not None and content.count(old) != 1:
                raise RuntimeError('Mutation prerequisite not unique: ' + label)
            target.write_text(content + new if old is None else content.replace(old, new))
            xml = out / ('mutation-' + label + '.xml')
            log = run('mutation-' + label, test_args + ['--filter', pattern, '--log-junit', str(xml)], root, expected_exit)
            ids, stats = cases(xml)
            if not ids or stats['tests'] != 1 or stats['skipped'] or not (stats['failure'] or stats['error']) or excerpt not in log:
                raise RuntimeError('Mutation was not independently detected: ' + label)
            report['mutations'][label] = dict(stats, expected_nonzero_exit=expected_exit, junit=str(xml), junit_sha256=digest(xml), detection=excerpt)
        if run('final-shared-status', ['git', 'status', '--porcelain'], ROOT).strip():
            raise RuntimeError('Shared working tree changed during the run; no cleanup attempted')
        report['status'] = 'FOCUSED_PASS_REVIEW_REQUIRED'
        report['final_gates'] = 'OPEN; no full-suite, snapshot RED, literal floor or real product gate verdict'
    except Exception as exc:
        report['status'] = 'FAILED'
        report['failure'] = str(exc)
        raise
    finally:
        json_write(out / 'results.json', report)
    print(json.dumps({'status': report['status'], 'candidate': candidate,
                      'runs': report['runs'], 'mutations_detected': list(report['mutations']),
                      'results': str(out / 'results.json')}, indent=2))


if __name__ == '__main__':
    main()
