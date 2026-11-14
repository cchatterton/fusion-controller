#!/usr/bin/env python3
"""Mirror changed WordPress.org packages without changing approval decisions."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from urllib.parse import urlencode, urlparse
from urllib.request import Request, urlopen
import zipfile


ROOT = Path(__file__).resolve().parents[1]
OWNER_REPO = "cchatterton/fusion-controller"
API = "https://api.wordpress.org/plugins/info/1.2/"


def read_json(path):
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path, value):
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def run(*args):
    subprocess.run(args, check=True, cwd=ROOT)


def plugin_information(slug):
    query = urlencode({"action": "plugin_information", "request[slug]": slug})
    with urlopen(Request(API + "?" + query, headers={"User-Agent": "Fusion-Mirror/0.1"}), timeout=30) as response:
        data = json.load(response)
    if data.get("slug") != slug or not isinstance(data.get("version"), str):
        raise ValueError(f"WordPress.org returned an unexpected identity for {slug}")
    download = data.get("download_link", "")
    parsed = urlparse(download)
    if parsed.scheme != "https" or parsed.hostname != "downloads.wordpress.org" or not parsed.path.startswith("/plugin/" + slug + "."):
        raise ValueError(f"WordPress.org returned an unexpected download URL for {slug}")
    return data["version"], download, data.get("requires") or "", data.get("requires_php") or ""


def download_and_verify(slug, url, destination):
    request = Request(url, headers={"User-Agent": "Fusion-Mirror/0.1"})
    with urlopen(request, timeout=120) as response, destination.open("wb") as output:
        if urlparse(response.url).hostname != "downloads.wordpress.org":
            raise ValueError(f"Unexpected download redirect for {slug}")
        size = 0
        while True:
            chunk = response.read(1024 * 1024)
            if not chunk:
                break
            size += len(chunk)
            if size > 100 * 1024 * 1024:
                raise ValueError(f"Package exceeds size limit: {slug}")
            output.write(chunk)
    with zipfile.ZipFile(destination) as package:
        names = package.namelist()
        if not names or package.testzip() is not None:
            raise ValueError(f"Corrupt package: {slug}")
        for name in names:
            if not name.startswith(slug + "/") or ".." in Path(name).parts or "\\" in name:
                raise ValueError(f"Unexpected ZIP path in {slug}: {name}")
    return hashlib.sha256(destination.read_bytes()).hexdigest()


def selected_plugins(catalog_id, slug_filter, include_supported):
    index = read_json(ROOT / "fusion-plugins/catalogs/index.json")
    catalogs = index["catalogs"]
    current = index["current"]
    chosen = []
    for record in catalogs:
        year, status = record["year"], record["status"]
        if catalog_id and year != catalog_id:
            continue
        if not catalog_id and year != current and not (include_supported and status in ("supported", "archive_planned")):
            continue
        if status == "archived":
            continue
        chosen.append(read_json(ROOT / "fusion-plugins/catalogs" / (year + ".json")))
    if catalog_id and not chosen:
        raise ValueError("Unknown or archived catalog")
    plugins = {}
    for catalog in chosen:
        for entry in catalog["plugins"]:
            if entry["source"] != "wordpress.org":
                continue
            if slug_filter and entry["slug"] != slug_filter:
                continue
            plugins.setdefault(entry["slug"], entry)
    if slug_filter and not plugins:
        raise ValueError("Plugin not found in the selected active catalog")
    return plugins


def sync(slug, index):
    version, url, requires, requires_php = plugin_information(slug)
    existing = index["packages"].get(slug, {}).get("versions", {}).get(version)
    if existing:
        changed = False
        for key, value in (("requires", requires), ("requires_php", requires_php)):
            if value and existing.get(key) != value:
                existing[key] = value
                changed = True
        print(f"Metadata refreshed: {slug} {version}" if changed else f"Unchanged: {slug} {version}")
        return changed
    tag = f"mirror-{slug}-v{version}"
    asset = f"{slug}-{version}.zip"
    with tempfile.TemporaryDirectory(prefix="fusion-mirror-") as temporary:
        package = Path(temporary) / asset
        sha256 = download_and_verify(slug, url, package)
        found = subprocess.run(["gh", "release", "view", tag, "--repo", OWNER_REPO], cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if found.returncode == 0:
            mirror = Path(temporary) / "existing"
            mirror.mkdir()
            run("gh", "release", "download", tag, "--repo", OWNER_REPO, "--pattern", asset, "--dir", str(mirror))
            existing_package = mirror / asset
            if not existing_package.is_file() or hashlib.sha256(existing_package.read_bytes()).hexdigest() != sha256:
                raise ValueError(f"Published release differs from upstream: {tag}. Review before continuing.")
        else:
            run("gh", "release", "create", tag, str(package), "--repo", OWNER_REPO, "--latest=false", "--title", f"{slug} {version}", "--notes", f"Verified WordPress.org package for {slug} {version}. SHA-256: {sha256}")
    record = index["packages"].setdefault(slug, {"latest": version, "versions": {}})
    record["versions"][version] = {"sha256": sha256, "url": f"https://github.com/{OWNER_REPO}/releases/download/{tag}/{asset}"}
    if requires:
        record["versions"][version]["requires"] = requires
    if requires_php:
        record["versions"][version]["requires_php"] = requires_php
    record["latest"] = version
    print(f"Mirrored: {slug} {version}")
    return True


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--catalog", default="", help="Run one non-archived catalog")
    parser.add_argument("--slug", default="", help="Run one WordPress.org plugin")
    parser.add_argument("--include-supported", action="store_true", help="Also refresh previous supported catalogs")
    args = parser.parse_args()
    plugins = selected_plugins(args.catalog, args.slug, args.include_supported)
    index_path = ROOT / "mirror-index.json"
    index = read_json(index_path)
    if index.get("schema") != 1 or not isinstance(index.get("packages"), dict):
        raise ValueError("Invalid mirror index")
    failures = []
    changed = False
    for slug in plugins:
        try:
            changed = sync(slug, index) or changed
        except Exception as error:
            failures.append(f"{slug}: {error}")
            print(f"ERROR {slug}: {error}", file=sys.stderr)
    if changed:
        write_json(index_path, index)
    if failures:
        raise RuntimeError("Mirror check failed for: " + ", ".join(failures))


if __name__ == "__main__":
    main()
