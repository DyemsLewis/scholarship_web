"""Use the packaged renderer with a native Word PDF on Windows without LibreOffice."""
import importlib.util
import json
import os
from pathlib import Path

import pdfplumber
from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parents[2]
AREA = ROOT / '.tmp-docx/single-provider-guide-20261114'
GUIDE = ROOT / 'deliverables/Platform_Process_Guide_With_Accounts_Expanded.docx'
PDF = AREA / 'render/guide.pdf'
RENDERER = Path('C:/Users/James/.codex/plugins/cache/openai-primary-runtime/documents/26.1112.11217/skills/documents/render_docx.py')
DEPENDENCIES = Path('C:/Users/James/.cache/codex-runtimes/codex-primary-runtime/dependencies')
os.environ['PATH'] = os.pathsep.join([str(DEPENDENCIES/'native/poppler/Library/bin'), str(DEPENDENCIES/'native/poppler/bin'), os.environ['PATH']])
assert PDF.is_file(), 'Export the existing DOCX using Word first.'
spec = importlib.util.spec_from_file_location('packaged_docx_renderer',RENDERER)
renderer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(renderer)

def native_word_pdf(doc_path, user_profile, convert_tmp_dir, stem, verbose):
    assert Path(doc_path).resolve() == GUIDE.resolve()
    return str(PDF), 'Native Microsoft Word PDF; LibreOffice is not installed on this Windows workspace.'

renderer.convert_to_pdf = native_word_pdf
pages = renderer.rasterize(str(GUIDE),str(AREA/'render'),110,False,False)
audit = []
with pdfplumber.open(PDF) as pdf:
    for number, page in enumerate(pdf.pages,1):
        text = page.extract_text() or ''
        assert text.strip() or page.images, f'Blank page {number}'
        for image in page.images:
            assert image['x0'] >= 0 and image['top'] >= 0 and image['x1'] <= page.width+1 and image['bottom'] <= page.height+1, f'Image overflow on page {number}'
        assert not any(old in text.lower() for old in ['nuvareya','velasora','kinoraya','novacore','cohort.learner','cohort.process']), f'Old account on page {number}'
        audit.append({'page':number,'images':len(page.images),'text':text})
for start in range(0,len(pages),8):
    sheet = Image.new('RGB',(1400,1900),'#e5e7eb')
    draw = ImageDraw.Draw(sheet)
    for offset, filename in enumerate(pages[start:start+8]):
        with Image.open(filename) as image:
            image.thumbnail((680,435))
            x,y=(offset%2)*700,(offset//2)*475
            draw.text((x+10,y+6),f'Page {start+offset+1}',fill='black')
            sheet.paste(image,(x+10,y+26))
    sheet.save(AREA/'render'/f'contact-{start//8+1}.png')
(AREA/'render/page-audit.json').write_text(json.dumps(audit,indent=2))
print(f'{len(pages)} pages rendered with the packaged rasterizer; no blank pages, old accounts or image overflow.')
