"""Validate the actual gulp ZIP's production footprint, not a mocked bundler."""
import hashlib
import json
import os
import pathlib
import stat
import sys
import zipfile

root = pathlib.Path(__file__).resolve().parent.parent
archive = pathlib.Path(sys.argv[1])
excluded_dirs = {"node_modules", "vendor", "bin", "dist", "docs", "scripts", "src", "tests", "wp-content"}
excluded_files = {"fs-config.json", "composer.json", "composer.lock", "phpunit.xml.dist", "wpgulp.config.js", "gulpfile.babel.js"}
expected = set()
for directory, dirs, files in os.walk(root):
    dirs[:] = [name for name in dirs if not name.startswith(".") and (pathlib.Path(directory) != root or name not in excluded_dirs)]
    for name in files:
        if name.startswith("."):
            continue
        if pathlib.Path(directory) == root and (name in excluded_files or (name.startswith("package") and name.endswith(".json"))):
            continue
        expected.add((pathlib.Path(directory) / name).relative_to(root).as_posix())
with zipfile.ZipFile(archive) as package:
    assert package.testzip() is None, "Bad ZIP CRC"
    entries = package.infolist()
    names = [entry.filename for entry in entries]
    assert len(names) == len(set(names)), "Duplicate ZIP paths"
    payloads = [entry for entry in entries if not entry.is_dir()]
    assert {entry.filename for entry in payloads} == expected, "ZIP production footprint incomplete or unexpected"
    for entry in entries:
        path = pathlib.PurePosixPath(entry.filename)
        assert not path.is_absolute() and ".." not in path.parts, "Unsafe ZIP path"
        assert not stat.S_ISLNK(entry.external_attr >> 16), "ZIP symlink"
        assert path.parts[0] not in excluded_dirs, "Development or local payload shipped: " + entry.filename
    for entry in payloads:
        assert package.read(entry) == (root / entry.filename).read_bytes(), "Source/package bytes differ: " + entry.filename
    assert "migrate.php" in names and "includes/functions.php" in names, "Production entrypoints absent"
    print(json.dumps({"status": "pass", "zip": str(archive.resolve()), "sha256": hashlib.sha256(archive.read_bytes()).hexdigest(), "product_files": len(payloads), "dev_files": 0, "crc_paths_symlinks_source_parity": "pass"}))
