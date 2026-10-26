#!/usr/bin/env python3
"""Offline producer and regression-mutation checks. NO native/SQL acceptance.
All mutation copies and generated corpora live in a NEW owned TMPDIR directory.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class OptionsHostTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='a7-options-host-', dir=os.environ['TMPDIR'])
        self.addCleanup(self.temp.cleanup)
        self.scratch = Path(self.temp.name)

    def test_producer_exact_manifest_raw_and_new_only(self):
        output = self.scratch / 'fixture'
        command = ['php', str(ROOT / 'tests/integration/storage-a7-options-fixtures.php'), str(output)]
        result = subprocess.run(command, capture_output=True, timeout=45)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, (ROOT / 'tests/integration/storage-a7-options-manifest.json').read_bytes())
        self.assertEqual(result.stdout, (output / 'manifest.json').read_bytes())
        manifest = json.loads(result.stdout)
        import hashlib
        raw = (output / (manifest['format'] + '.serialized')).read_bytes()
        self.assertEqual(len(raw), manifest['fixture']['raw_bytes'])
        self.assertEqual(hashlib.sha256(raw).hexdigest(), manifest['fixture']['sha256'])
        before = {p.name: p.read_bytes() for p in output.iterdir()}
        again = subprocess.run(command, capture_output=True, timeout=45)
        self.assertNotEqual(again.returncode, 0)
        self.assertIn(b'New-only fixture directory required.', again.stderr)
        self.assertEqual(before, {p.name: p.read_bytes() for p in output.iterdir()})
        link = self.scratch / 'link'
        link.symlink_to(output, target_is_directory=True)
        symlink = subprocess.run(command[:-1] + [str(link)], capture_output=True, timeout=45)
        self.assertNotEqual(symlink.returncode, 0)
        self.assertEqual(before, {p.name: p.read_bytes() for p in output.iterdir()})

    def test_guard_deletion_and_wrong_substitution_mutants_are_killed(self):
        # Executable copies only: never edit the canonical source to inject faults.
        files = ['tests/support/StorageA7Fixture.php', 'tests/support/StorageA7OptionsFixture.php',
                 'tests/integration/storage-a7-options-harness.php',
                 'tests/StorageA7OptionsFixtureTest.php', 'tests/StorageA7OptionsHarnessTest.php']
        for relative in files:
            target = self.scratch / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / relative, target)
        harness = self.scratch / 'tests/integration/storage-a7-options-harness.php'
        fixture = self.scratch / 'tests/support/StorageA7OptionsFixture.php'
        harness_text, fixture_text = harness.read_text(), fixture.read_text()
        mutants = [
            ('seed-guard', harness, 'foreach ( $required as $key => $value )', 'foreach ( array() as $key => $value )',
             'test_missing_error_or_mutated_receipt_blocks_all_reads_and_downstream'),
            ('sql-guard', harness, 'call_user_func( $read_sql, $name ) !== $exact', 'false',
             'test_fresh_sql_failure_or_mismatch_blocks_wp_and_downstream'),
            ('wp-guard', harness, '! is_array( $value ) || $logical !== $fixture[\'logical_sha256\']', 'false',
             'test_missing_empty_original_or_mutated_wp_readback_blocks_downstream'),
            ('empty-result', harness, '$result !== $expected_result', 'false',
             'test_empty_conversion_or_backup_never_accepted'),
            ('binary-sql', harness, 'SHA2(CAST(option_value AS BINARY),256)', 'SHA2(option_value,256)',
             'test_wordpress_adapter_queries_binary_digest_and_evicts_caches'),
            ('wrong-substitution', fixture, '$image[\'unknown\'][\'binary\'] = "x\\x00"', '$image[\'unknown\'][\'binary\'] = "?\\x00"',
             'test_only_declared_208_bytes_and_leaves_change'),
        ]
        for name, path, before, after, test in mutants:
            with self.subTest(mutant=name):
                harness.write_text(harness_text)
                fixture.write_text(fixture_text)
                original = path.read_text()
                self.assertEqual(original.count(before), 1)
                path.write_text(original.replace(before, after))
                test_file = 'StorageA7OptionsFixtureTest.php' if path == fixture else 'StorageA7OptionsHarnessTest.php'
                command = [str(ROOT / 'vendor/bin/phpunit'), '--no-configuration', '--bootstrap',
                           str(ROOT / 'tests/bootstrap.php'), '--do-not-cache-result', '--filter', test,
                           str(self.scratch / 'tests' / test_file)]
                result = subprocess.run(command, capture_output=True, timeout=45)
                self.assertNotEqual(result.returncode, 0, name + ' survived')
                self.assertIn(b'FAILURES!' if path == harness else b'ERRORS!', result.stdout, result.stdout)
                # Compact actual executed mutant receipt; not a synthetic test count.
                summary = [line for line in result.stdout.decode().splitlines() if line.startswith('Tests:')]
                print(json.dumps(dict(mutant=name, exit=result.returncode, result=summary)), flush=True)


if __name__ == '__main__':
    unittest.main(verbosity=2)
