import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import zipfile


SCRIPT = Path(__file__).resolve().parents[1] / "scripts/sync-mirror.py"
spec = importlib.util.spec_from_file_location("fusion_sync", SCRIPT)
sync = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sync)


class DownloadResponse(io.BytesIO):
    url = "https://downloads.wordpress.org/plugin/example.1.0.zip"

    def __enter__(self):
        return self

    def __exit__(self, *_):
        self.close()


def archive(names):
    output = io.BytesIO()
    with zipfile.ZipFile(output, "w") as package:
        for name in names:
            package.writestr(name, "plugin")
    return output.getvalue()


class MirrorTests(unittest.TestCase):
    def test_older_feeds_keep_their_known_package_sets(self):
        legacy = json.loads((sync.ROOT / "mirror-index.json").read_text())["packages"]
        first_fusion = json.loads((sync.ROOT / "fusion-index.json").read_text())["packages"]
        current = json.loads((sync.ROOT / "fusion-index-v3.json").read_text())["packages"]
        self.assertEqual(10, len(legacy))
        self.assertEqual(set(legacy) | {"fusion-plugins"}, set(first_fusion))
        self.assertTrue((set(first_fusion) | {"font-awesome", "query-monitor", "search-regex"}).issubset(set(current)))

    def test_current_catalog_has_unique_roles_and_expected_plugins(self):
        catalog = json.loads((sync.ROOT / "fusion-plugins/catalogs/26.json").read_text())
        self.assertEqual(25, len(catalog["plugins"]))
        self.assertEqual(25, len({entry["role"] for entry in catalog["plugins"]}))
        self.assertEqual("cms-tree-page-view", next(entry["slug"] for entry in catalog["plugins"] if entry["role"] == "Page tree"))
        self.assertEqual(14, len(sync.selected_plugins("", "", False)))
        self.assertEqual("github", sync.selected_plugins("", "fusion-plugins", False)["fusion-plugins"]["source"])
        entries = {entry["slug"]: entry for entry in catalog["plugins"] if "slug" in entry}
        for slug in ("font-awesome", "query-monitor", "search-regex"):
            self.assertEqual("wordpress.org", entries[slug]["source"])
        for slug in ("gravityformsadvancedpostcreation", "gravityformssalesforce", "gravityformsstripe", "gravityformssurvey", "gravityformswebhooks"):
            self.assertEqual("vendor", entries[slug]["source"])

    def test_download_rejects_other_plugin_folder(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "package.zip"
            with patch.object(sync, "urlopen", return_value=DownloadResponse(archive(["other/plugin.php"]))):
                with self.assertRaisesRegex(ValueError, "Unexpected ZIP path"):
                    sync.download_and_verify("example", "https://downloads.wordpress.org/plugin/example.1.0.zip", target)

    def test_download_accepts_expected_folder(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "package.zip"
            with patch.object(sync, "urlopen", return_value=DownloadResponse(archive(["example/plugin.php"]))):
                self.assertEqual(64, len(sync.download_and_verify("example", "https://downloads.wordpress.org/plugin/example.1.0.zip", target)))

    def test_existing_release_receives_requirement_metadata(self):
        index = {"packages": {"example": {"latest": "1.0", "versions": {"1.0": {"sha256": "a" * 64, "url": "https://example.test/package.zip"}}}}}
        with patch.object(sync, "plugin_information", return_value=("1.0", "https://downloads.wordpress.org/plugin/example.1.0.zip", "6.5", "7.4")):
            self.assertTrue(sync.sync("example", index))
        self.assertEqual("7.4", index["packages"]["example"]["versions"]["1.0"]["requires_php"])


if __name__ == "__main__":
    unittest.main()
