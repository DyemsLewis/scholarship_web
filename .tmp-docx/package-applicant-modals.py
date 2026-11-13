from pathlib import Path
from shutil import copy2
from zipfile import ZipFile, ZIP_DEFLATED
from PIL import Image, ImageOps, ImageDraw, ImageFont

root = Path('C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958')
folder = root / 'applicant-modals-20261114'
extras = {
    'payout-details.png': 'fresh-demo-20261113/document-actions/applicant-payout-form.png',
    'earlier-agreement-concern.png': 'agreement-concern-part2/applicant-agreement-concern.png',
    'earlier-concern-response.png': 'agreement-concern-part2/applicant-concern-response-mobile.png',
    'earlier-appeal.png': 'recipient-case-review-part4/applicant-appeal-mobile.png',
    'earlier-unsaved-changes-confirmation.png': 'confirmation-style/unsaved-profile-confirmation.png',
    'earlier-agreement-confirmation.png': 'outcome-confirmations/applicant-agreement-confirmation-desktop.png',
}
for name, source in extras.items():
    copy2(root / source, folder / name)
images = sorted(folder.glob('*.png'))
images = [p for p in images if not p.name.startswith('overview-')]
font_path = 'C:/Windows/Fonts/arial.ttf'
font = ImageFont.truetype(font_path, 22)
small = ImageFont.truetype(font_path, 17)
overviews = []
for start in range(0, len(images), 8):
    batch = images[start:start + 8]
    canvas = Image.new('RGB', (1600, 4 * 610), '#eef2f6')
    draw = ImageDraw.Draw(canvas)
    for index, file in enumerate(batch):
        x, y = (index % 2) * 800, (index // 2) * 610
        label = file.stem.replace('earlier-', '').replace('-', ' ').title()
        draw.text((x + 16, y + 10), label, fill='#0f172a', font=font)
        if file.name.startswith('earlier-'):
            draw.text((x + 16, y + 38), 'Earlier capture; sample account data', fill='#64748b', font=small)
        with Image.open(file) as image:
            thumb = ImageOps.contain(image.convert('RGB'), (768, 540))
            canvas.paste(thumb, (x + (800 - thumb.width) // 2, y + 64))
    output = folder / f'overview-{start // 8 + 1}.jpg'
    canvas.save(output, quality=90)
    overviews.append(output)
notes = [
    f'{len(images)} applicant modal screenshots. No application or database changes were made.',
    'Most captures are from hosting on November 14, 2026.',
    'Payout details reuses the November 13 hosted capture.',
    'Files prefixed earlier- are previous captures of conditional dialogs with previous sample account data.',
    'The camera window shows the automated browser without a physical camera.',
    'Conditional correction requests, check-in adjustments, missed-benefit follow-ups and notification-detail variants are not captured in this pack.',
    '',
    *[p.name for p in images],
]
(folder / 'CONTENTS.txt').write_text('\n'.join(notes), encoding='utf-8')
archive = root / 'Applicant-Modal-Screenshots.zip'
with ZipFile(archive, 'w', ZIP_DEFLATED) as bundle:
    for file in [*images, *overviews, folder / 'CONTENTS.txt']:
        bundle.write(file, file.name)
print(f'{len(images)} screenshots; {len(overviews)} overview sheets; {archive}')
