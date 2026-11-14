import importlib.util
from pathlib import Path
import tempfile
import unittest
import zipfile


SCRIPT = Path(__file__).resolve().parents[1] / "scripts/add-vendor-package.py"
spec = importlib.util.spec_from_file_location("fusion_vendor", SCRIPT)
vendor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(vendor)


class VendorPackageTests(unittest.TestCase):
    def test_inspects_matching_vendor_zip(self):
        with tempfile.TemporaryDirectory() as directory:
            package = Path(directory) / "vendor.zip"
            with zipfile.ZipFile(package, "w") as archive:
                archive.writestr("gravityforms/gravityforms.php", "<?php\n/*\nPlugin Name: Gravity Forms\nVersion: 2.1.0\nRequires PHP: 7.4\n*/")
            root, main, requirements, sha = vendor.inspect(package, {"name": "Gravity Forms"}, "2.1.0")
            self.assertEqual((root, main, requirements["requires_php"], len(sha)), ("gravityforms", "gravityforms/gravityforms.php", "7.4", 64))

    def test_rejects_wrong_catalog_identity(self):
        with tempfile.TemporaryDirectory() as directory:
            package = Path(directory) / "vendor.zip"
            with zipfile.ZipFile(package, "w") as archive:
                archive.writestr("gravityforms/gravityforms.php", "<?php\n/*\nPlugin Name: Other Plugin\nVersion: 2.1.0\n*/")
            with self.assertRaisesRegex(ValueError, "not 'Gravity Forms'"):
                vendor.inspect(package, {"name": "Gravity Forms"}, "2.1.0")


if __name__ == "__main__":
    unittest.main()
