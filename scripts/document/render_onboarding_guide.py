"""Render the in-place guide update using the packaged renderer and native Word PDF."""
import argparse
import importlib.util
import json
import os
from pathlib import Path

import pdfplumber
from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser()
parser.add_argument('--area', choices=['recipient-onboarding-20261118', 'recipient-onboarding-live-demo-20261118'], default='recipient-onboarding-20261118')
args = parser.parse_args()
AREA = ROOT / '.tmp-docx' / args.area
GUIDE = AREA / 'guide-updated.docx'
PDF = AREA / 'guide.pdf'
RENDERER = Path('C:/Users/James/.codex/plugins/cache/openai-primary-runtime/documents/26.1116.12020/skills/documents/render_docx.py')
DEPS = Path('C:/Users/James/.cache/codex-runtimes/codex-primary-runtime/dependencies')
os.environ['PATH'] = str(DEPS / 'native/poppler/Library/bin') + os.pathsep + os.environ['PATH']
spec = importlib.util.spec_from_file_location('packaged_docx_renderer', RENDERER)
renderer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(renderer)

def native_word_pdf(doc_path, user_profile, convert_tmp_dir, stem, verbose):
    assert Path(doc_path).resolve() == GUIDE.resolve() and PDF.is_file()
    return str(PDF), 'Native Microsoft Word PDF; the configured Windows bundle has no LibreOffice.'

renderer.convert_to_pdf = native_word_pdf
pages = renderer.rasterize(str(GUIDE), str(AREA / 'render'), 110, False, False)
audit = []
with pdfplumber.open(PDF) as pdf:
    for number, page in enumerate(pdf.pages, 1):
        text = page.extract_text() or ''
        assert text.strip() or page.images, f'Blank page {number}'
        for image in page.images:
            assert image['x0'] >= -1 and image['top'] >= -1 and image['x1'] <= page.width + 1 and image['bottom'] <= page.height + 1, f'Image overflow on page {number}'
        audit.append({'page': number, 'images': len(page.images), 'text': text})
for start in range(0, len(pages), 8):
    sheet = Image.new('RGB', (1500, 2020), '#e5e7eb')
    draw = ImageDraw.Draw(sheet)
    for offset, filename in enumerate(pages[start:start + 8]):
        with Image.open(filename) as image:
            image.thumbnail((725, 465))
            x, y = (offset % 2) * 750, (offset // 2) * 505
            draw.text((x + 10, y + 5), f'Page {start + offset + 1}', fill='black')
            sheet.paste(image, (x + 10, y + 25))
    sheet.save(AREA / 'render' / f'contact-{start // 8 + 1}.png')
(AREA / 'render/page-audit.json').write_text(json.dumps(audit, indent=2), encoding='utf-8')
focused = [row['page'] for row in audit if any(term in row['text'] for term in [
    'Final provider agreement and onboarding', 'Configure the requirement before applications',
    'Required-path local preview', 'Final agreement pending', 'Read the offer and confirm intention',
    'Pending final agreement alone', 'orivanta.manager | Selection step',
    'Welcome Scholars', 'Hosting November 18 2026',
])]
print(json.dumps({'rendered_pages': len(pages), 'focused_pages': focused, 'blank_pages': 0, 'image_overflows': 0}))
