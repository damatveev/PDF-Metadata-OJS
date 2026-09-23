"""Build deterministic installable archives with Python 3 standard library only."""
from pathlib import Path
import gzip
import hashlib
import io
import re
import tarfile
import zipfile
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parent.parent
version = ET.parse(root / 'version.xml').getroot()
release = version.findtext('release')
assert re.fullmatch(r'\d+\.\d+\.\d+\.\d+', release)
assert version.findtext('application') == 'pdfMetadata'
locales = []
for locale in ('en', 'ru', 'ru_RU'):
    text = (root / f'locale/{locale}/locale.po').read_text(encoding='utf-8')
    keys = re.findall(r'^msgid "(plugins\.[^"]+)"$', text, re.M)
    assert len(keys) == len(set(keys)), f'Duplicate keys in {locale}'
    locales.append(set(keys))
assert locales[0] == locales[1] == locales[2]
plugin = (root / 'PdfMetadataPlugin.php').read_text(encoding='utf-8')
labels = re.search(r'foreach \(\[(.*?)\] as \$key', plugin, re.S)[1]
for key in re.findall(r"'([^']+)'", labels):
    assert f'plugins.generic.pdfMetadata.{key}' in locales[0], key
files = [root / name for name in ('index.php', 'PdfMetadataPlugin.php', 'version.xml', 'README.md', 'CHANGELOG.md', 'LICENSE')]
for directory in ('classes', 'js', 'styles', 'locale', 'docs', 'tests', 'scripts'):
    files += [p for p in (root / directory).rglob('*') if p.is_file() and '__pycache__' not in p.parts]
files.sort()
out = root / 'dist'
out.mkdir(exist_ok=True)
tar_path = out / f'pdfMetadata-{release}.tar.gz'
with tar_path.open('wb') as raw, gzip.GzipFile(fileobj=raw, mode='wb', mtime=0, filename='') as compressed, tarfile.open(fileobj=compressed, mode='w', format=tarfile.USTAR_FORMAT) as archive:
    for path in files:
        data = path.read_bytes()
        info = tarfile.TarInfo('pdfMetadata/' + path.relative_to(root).as_posix())
        info.size = len(data); info.mode = 0o644
        archive.addfile(info, io.BytesIO(data))
zip_path = out / f'pdfMetadata-{release}.zip'
with zipfile.ZipFile(zip_path, 'w') as archive:
    for path in files:
        info = zipfile.ZipInfo('pdfMetadata/' + path.relative_to(root).as_posix(), (2026, 9, 24, 0, 0, 0))
        info.external_attr = 0o100644 << 16; info.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(info, path.read_bytes())
with tarfile.open(tar_path) as archive:
    assert len(archive.getmembers()) == len(files)
    for member in archive:
        assert member.isfile() and '..' not in Path(member.name).parts
        assert archive.extractfile(member).read() == (root / member.name.removeprefix('pdfMetadata/')).read_bytes()
with zipfile.ZipFile(zip_path) as archive:
    assert archive.testzip() is None and len(archive.namelist()) == len(files)
    for name in archive.namelist():
        assert archive.read(name) == (root / name.removeprefix('pdfMetadata/')).read_bytes()
sums = [f'{hashlib.sha256(path.read_bytes()).hexdigest()}  {path.name}' for path in sorted(out.glob('pdfMetadata-*')) if path.suffix in ('.gz', '.zip')]
(out / 'SHA256SUMS.txt').write_text('\n'.join(sums) + '\n', encoding='ascii')
print(f'{release}: {len(files)} files, {len(locales[0])} keys per locale, archives verified')
