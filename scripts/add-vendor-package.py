#!/usr/bin/env python3
"""Verify a supplied licensed ZIP and add its release to the Fusion mirror."""

import argparse
import hashlib
import json
from pathlib import Path
import re
import shutil
import stat
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
REPO = "cchatterton/fusion-controller"
INDEX = ROOT / "fusion-index-v3.json"
CATALOG_DIR = ROOT / "fusion-plugins/catalogs"


def header(text, key):
    match = re.search(r"^[ \t]*\*?[ \t]*" + re.escape(key) + r":[ \t]*([^\r\n]+)", text, re.M | re.I)
    return match.group(1).strip() if match else ""


def inspect(package, entry, expected_version):
    if not package.is_file() or not 0 < package.stat().st_size <= 100 * 1024 * 1024:
        raise ValueError("The ZIP is missing or exceeds 100 MB")
    with zipfile.ZipFile(package) as archive:
        files = archive.infolist()
        names = [item.filename for item in files]
        if not names or len(names) != len(set(names)) or archive.testzip() is not None:
            raise ValueError("The ZIP is corrupt or has duplicate paths")
        roots = {name.split("/")[0] for name in names}
        if len(roots) != 1:
            raise ValueError("The ZIP must have one plugin directory")
        root = roots.pop()
        if not re.fullmatch(r"[a-z0-9][a-z0-9_-]*", root):
            raise ValueError("The ZIP has an invalid plugin directory")
        if entry.get("slug") and root != entry["slug"]:
            raise ValueError("The ZIP directory differs from the catalog plugin slug")
        if sum(item.file_size for item in files) > 250 * 1024 * 1024:
            raise ValueError("The ZIP expands beyond 250 MB")
        for item in files:
            name = item.filename
            if not name.startswith(root + "/") or "\\" in name or ".." in Path(name).parts or stat.S_ISLNK(item.external_attr >> 16):
                raise ValueError("The ZIP contains an unsafe path or symlink")
        candidates = []
        for name in names:
            if len(name.split("/")) != 2 or not name.endswith(".php"):
                continue
            if archive.getinfo(name).file_size > 2 * 1024 * 1024:
                raise ValueError("The plugin main file exceeds 2 MB")
            text = archive.read(name).decode("utf-8", "replace")[:8192]
            if header(text, "Plugin Name") and header(text, "Version"):
                candidates.append((name, text))
        if len(candidates) != 1:
            raise ValueError("Expected one root-level WordPress plugin main file")
        name, text = candidates[0]
        version = header(text, "Version")
        if not re.fullmatch(r"\d+\.\d+(?:\.\d+){0,3}", version) or version != expected_version:
            raise ValueError("The ZIP version differs from --version")
        plugin_name = header(text, "Plugin Name")
        if plugin_name.casefold() != entry["name"].casefold():
            raise ValueError(f"The ZIP identifies '{plugin_name}', not '{entry['name']}'")
        requirements = {}
        for field, key in (("Requires at least", "requires"), ("Requires PHP", "requires_php")):
            value = header(text, field)
            if value and re.fullmatch(r"\d+(?:\.\d+){0,3}", value):
                requirements[key] = value
    return root, name, requirements, hashlib.sha256(package.read_bytes()).hexdigest()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--id", required=True, help="Fusion catalog plugin ID")
    parser.add_argument("--catalog", default="", help="Catalog year (defaults to current)")
    parser.add_argument("--zip", required=True, type=Path, help="Vendor ZIP supplied by the license holder")
    parser.add_argument("--version", required=True, help="Expected plugin version")
    parser.add_argument("--publish", action="store_true", help="Publish after verifying distribution rights")
    args = parser.parse_args()
    year = args.catalog or json.loads((CATALOG_DIR / "index.json").read_text())["current"]
    if not re.fullmatch(r"\d{2}", year):
        raise ValueError("Invalid catalog year")
    catalog = json.loads((CATALOG_DIR / f"{year}.json").read_text())
    entry = next((item for item in catalog["plugins"] if item["id"] == args.id), None)
    if not entry or entry["source"] != "vendor":
        raise ValueError(f"The ID must be a licensed vendor entry in Fusion {year}")
    root, main_file, requirements, sha256 = inspect(args.zip, entry, args.version)
    key = entry.get("slug", entry["id"])
    tag = f"mirror-{key}-v{args.version}"
    asset = f"{key}-{args.version}.zip"
    url = f"https://github.com/{REPO}/releases/download/{tag}/{asset}"
    print(f"Verified {entry['name']} {args.version}: {main_file}, SHA-256 {sha256}")
    if not args.publish:
        print("Review distribution rights, then rerun with --publish to add the package to the public Fusion mirror.")
        return
    index = json.loads(INDEX.read_text())
    record = index["packages"].setdefault(key, {"latest": args.version, "versions": {}})
    existing = record["versions"].get(args.version)
    if existing and existing["sha256"] != sha256:
        raise ValueError("A different ZIP is already indexed for this version")
    with tempfile.TemporaryDirectory(prefix="fusion-vendor-") as temporary:
        output = Path(temporary) / asset
        shutil.copyfile(args.zip, output)
        found = subprocess.run(["gh", "release", "view", tag, "--repo", REPO], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if found.returncode == 0:
            existing_dir = Path(temporary) / "existing"
            existing_dir.mkdir()
            subprocess.run(["gh", "release", "download", tag, "--repo", REPO, "--pattern", asset, "--dir", str(existing_dir)], check=True)
            if hashlib.sha256((existing_dir / asset).read_bytes()).hexdigest() != sha256:
                raise ValueError("The published ZIP differs from the supplied ZIP")
        else:
            subprocess.run(["gh", "release", "create", tag, str(output), "--repo", REPO, "--latest=false", "--title", f"{entry['name']} {args.version}", "--notes", f"Verified licensed package. SHA-256: {sha256}"], check=True)
    record["versions"][args.version] = {"sha256": sha256, "url": url, "root": root, **requirements}
    if not record.get("latest") or tuple(map(int, args.version.split("."))) > tuple(map(int, record["latest"].split("."))):
        record["latest"] = args.version
    INDEX.write_text(json.dumps(index, indent=2, sort_keys=True) + "\n")
    print(f"Indexed {entry['name']} {args.version}; commit and publish {INDEX.name}.")


if __name__ == "__main__":
    main()
