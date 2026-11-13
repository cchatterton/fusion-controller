#!/usr/bin/env python3
"""Record an explicit catalog approval for an already mirrored package."""

import argparse
import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def read(path):
    return json.loads(path.read_text(encoding="utf-8"))


def write(path, value):
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("catalog", help="Catalog year, for example 26")
    parser.add_argument("slug", help="WordPress.org plugin slug")
    parser.add_argument("version", help="Already mirrored version")
    args = parser.parse_args()
    index = read(ROOT / "fusion-plugins/catalogs/index.json")
    if args.catalog not in {item["year"] for item in index["catalogs"] if item["status"] != "archived"}:
        parser.error("Catalog is unknown or archived")
    catalog = read(ROOT / "fusion-plugins/catalogs" / (args.catalog + ".json"))
    ids = {item["slug"]: item["id"] for item in catalog["plugins"] if item["source"] == "wordpress.org"}
    if args.slug not in ids:
        parser.error("Plugin is not in this catalog")
    index_release = read(ROOT / "mirror-index.json")["packages"].get(args.slug, {}).get("versions", {}).get(args.version)
    if not index_release:
        parser.error("Version has not been mirrored")
    path = ROOT / "approved.json"
    approved = read(path)
    approved["catalogs"].setdefault(args.catalog, {})[ids[args.slug]] = {
        "version": args.version,
        "sha256": index_release["sha256"],
        "url": index_release["url"],
    }
    write(path, approved)
    print(f"Approved {args.slug} {args.version} for Fusion {args.catalog}; review and commit approved.json")


if __name__ == "__main__":
    main()
