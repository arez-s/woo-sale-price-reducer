"""Build an installable ZIP; never package the workspace or test database."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

ROOT = Path(__file__).resolve().parent
VERSION = "1.0.1"
TARGET = ROOT / "dist" / f"woo-sale-price-reducer-{VERSION}.zip"
TARGET.parent.mkdir(exist_ok=True)
FILES = [ROOT / "woo-sale-price-reducer.php", ROOT / "README.md", ROOT / "readme.txt", ROOT / "LICENSE"]
FILES += sorted((ROOT / "includes").glob("*.php"))
FILES += sorted((ROOT / "assets").glob("*"))
with ZipFile(TARGET, "w", ZIP_DEFLATED) as archive:
    for path in FILES:
        archive.write(path, "woo-sale-price-reducer/" + path.relative_to(ROOT).as_posix())
print(TARGET)
