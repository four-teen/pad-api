"""Independently parse and render every PDF prepared by the local legacy import."""
import argparse
import hashlib
import json
import math
from pathlib import Path
import sys


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('package', type=Path)
    parser.add_argument('--libraries', type=Path)
    args = parser.parse_args()
    if args.libraries:
        sys.path.insert(0, str(args.libraries.resolve()))
    import fitz
    from pypdf import PdfReader
    from PIL import Image, ImageDraw

    root = args.package.resolve()
    plan = json.loads((root / 'plan.json').read_text(encoding='utf-8'))
    pdfs = [d for d in plan['documents'] if d['attachment'] and d['row']['file_mime'] == 'application/pdf']
    qa = root / 'pdf-qa'
    qa.mkdir(exist_ok=True)
    thumbnails = []
    report = []
    for entry in pdfs:
        path = root / entry['attachment']['package_file']
        reader = PdfReader(path)
        assert not reader.is_encrypted
        assert len(reader.pages) == entry['attachment']['pages']
        sha = hashlib.sha256(path.read_bytes()).hexdigest()
        assert sha == entry['attachment']['sha256']
        with fitz.open(path) as rendered:
            assert rendered.page_count == len(reader.pages)
            for index, page in enumerate(rendered):
                assert page.rect.width > 0 and page.rect.height > 0
                pix = page.get_pixmap(matrix=fitz.Matrix(0.35, 0.35), alpha=False)
                image = Image.frombytes('RGB', [pix.width, pix.height], pix.samples)
                image.thumbnail((210, 280))
                tile = Image.new('RGB', (230, 310), '#eeeeee')
                tile.paste(image, ((230 - image.width) // 2, 24))
                ImageDraw.Draw(tile).text((6, 5), str(entry['legacy_id']) + ' / page ' + str(index + 1), fill='black')
                thumbnails.append(tile)
                if len(report) < 2 and index == 0:
                    page.get_pixmap(matrix=fitz.Matrix(1, 1), alpha=False).save(str(qa / ('sample-' + str(entry['legacy_id']) + '.png')))
        report.append({'legacy_id': entry['legacy_id'], 'pages': len(reader.pages), 'sha256': sha})
    for group_index, start in enumerate(range(0, len(thumbnails), 24)):
        batch = thumbnails[start:start + 24]
        sheet = Image.new('RGB', (230 * 6, 310 * math.ceil(len(batch) / 6)), 'white')
        for index, tile in enumerate(batch):
            sheet.paste(tile, ((index % 6) * 230, (index // 6) * 310))
        sheet.save(qa / ('contact-' + str(group_index + 1) + '.jpg'), quality=90)
    result = {'pdfs': report, 'total_pages': len(thumbnails), 'all_page_counts_match': True, 'all_pages_rendered': True}
    (root / 'pdf-validation.json').write_text(json.dumps(result, indent=2), encoding='utf-8')
    print(json.dumps({'pdfs_checked': len(pdfs), 'pages_rendered': len(thumbnails), 'contact_sheets': math.ceil(len(thumbnails) / 24)}, indent=2))


if __name__ == '__main__':
    main()
