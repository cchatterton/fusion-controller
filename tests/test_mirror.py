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
    def test_current_catalog_has_unique_roles_and_expected_plugins(self):
        catalog = json.loads((sync.ROOT / "fusion-plugins/catalogs/26.json").read_text())
        self.assertEqual(16, len(catalog["plugins"]))
        self.assertEqual(16, len({entry["role"] for entry in catalog["plugins"]}))
        self.assertEqual("cms-tree-page-view", next(entry["slug"] for entry in catalog["plugins"] if entry["role"] == "Page tree"))
        self.assertEqual(10, len(sync.selected_plugins("", "", False)))

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


if __name__ == "__main__":
    unittest.main()
