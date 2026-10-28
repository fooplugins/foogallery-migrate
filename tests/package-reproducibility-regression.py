"""Check two real gulp ZIP builds, including deterministic entry order."""
import hashlib
import pathlib
import sys
import zipfile

first, second = map(pathlib.Path, sys.argv[1:3])
with zipfile.ZipFile(first) as left, zipfile.ZipFile(second) as right:
    assert left.testzip() is None and right.testzip() is None, 'Invalid ZIP CRC'
    assert left.namelist() == sorted(left.namelist()), 'ZIP entries must be sorted'
    assert left.namelist() == right.namelist(), 'Repeated build changed entry order'
    assert all(item.date_time == (1980, 1, 1, 0, 0, 0) for item in left.infolist()), 'ZIP timestamps must not depend on checkout times'
    assert all(left.read(name) == right.read(name) for name in left.namelist()), 'Repeated build changed payloads'
assert first.read_bytes() == second.read_bytes(), 'Repeated build changed ZIP bytes or metadata'
print('PASS: repeated real ZIP builds are byte-identical; SHA-256 ' + hashlib.sha256(first.read_bytes()).hexdigest())
