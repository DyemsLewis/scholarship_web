"""Refresh the existing guide in place after the verified November demo reset."""
import hashlib
import io
import json
import re
from pathlib import Path
from shutil import copy2
from zipfile import ZipFile, ZIP_DEFLATED

from docx import Document
from docx.shared import Inches
from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
AREA = ROOT / '.tmp-docx/document-reset-20261113'
GUIDE = ROOT / 'deliverables/Platform_Process_Guide_With_Accounts_Expanded.docx'
PHOTOS = Path('C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/fresh-demo-20261113')
BACKUP = AREA / 'guide-before.docx'
manifest = json.loads((ROOT / 'storage/app/private/workflow-demo-20261113/manifest.json').read_text())
inventory = json.loads((AREA / 'inventory.json').read_text())
assert json.loads((PHOTOS / 'screenshots/browser.json').read_text())['passed']
if not BACKUP.exists():
    copy2(GUIDE, BACKUP)
doc = Document(BACKUP)
blocks = list(doc.element.body)
replacements = json.loads((ROOT / 'scripts/document/platform_reset_mappings.json').read_text())['literal_replacements']
catalog = (ROOT / 'database/seeders/Support/WorkflowDemoCatalog.php').read_text()
rows = re.findall(r"\['([^']+)', '([^']+)', '([^']+)',", catalog)
for scenario, first, last in rows:
    account = next((a for a in manifest['accounts'] if a['username'] == 'cohort.learner.' + scenario), None)
    if account:
        replacements.insert(0, [first + ' ' + last, account['name']])
        replacements.insert(0, [first, account['name']])
old_process = ['Nerali Salindaw', 'Veylan Marahuyo', 'Amirel Talahir', 'Sorevi Lindawen', 'Iralen Dumaloy', 'Nivara Kalumay', 'Elvian Silaraw', 'Calira Maranaw', 'Revian Talihim', 'Avelis Dalisaw', 'Lenavi Salumay', 'Oriven Lumaray']
for number, name in enumerate(old_process, 1):
    replacements.insert(0, [name, f'Process Learner {number:02}'])
    replacements.append([name.split()[0], f'Process Learner {number:02}'])
# Replace complete names before first-name shorthand so the surname is not left behind.
replacements.sort(key=lambda pair: len(pair[0]), reverse=True)
def replace(text):
    for old, new in replacements:
        text = text.replace(old, new)
    return text

for paragraph in doc.element.xpath('.//w:p'):
    nodes = paragraph.xpath('.//w:t')
    if not nodes:
        continue
    old = ''.join(n.text or '' for n in nodes)
    new = replace(old)
    if new != old:
        nodes[0].text = new
        for n in nodes[1:]:
            n.text = ''

updates = {
    4: 'Verified November 13 2026 Philippine time. Both local and hosting databases were replaced with the same new fictional catalog. There are 77 accounts, 40 applicants, 16 programs and 37 applications. Earlier accounts and provider identities are no longer valid. Dates are fixed for this prepared walkthrough; do not move selection activities into an open application intake.',
    5: 'All retained screenshots below were freshly captured from the hosted platform after this reset. Applicant identities use fictional learner labels and the neutral silhouette, not real people or generated portraits. Screenshots show available controls without submitting tester decisions.',
    51: 'All application-stage examples now have fixed scoring snapshots. Use cohort.learner.exam.result or cohort.learner.interview.result for a blank current-stage score form. Earlier scored prerequisites are already recorded. Use the cohort.process DSS examples to demonstrate ranking and a missing prior evaluation. No active example uses the legacy manual path.',
    176: '4. Choose current files from My Documents or upload the required files. These prepared programs have no short application questions. Accept the required declarations and terms before submission; the question feature remains available for other programs.',
    285: 'Use cohort.process.dss.first, cohort.process.dss.second and cohort.process.dss.third in Nuvareya Scholar Selection Round. All three await the tester decision, with three evaluated applicants and two slots. Nuvareya Assessment Completion Round contains cohort.process.dss.score with an interview score still to enter. The regular cohort.learner.decision account also has a complete scoring plan.',
    413: 'Signed in account: cohort.process.support.history',
    414: 'The current image shows the actual successful renewal history for Process Learner 09. To demonstrate completion, first record the intended completion decision as nuvareya.recipients on an eligible settled support record, then sign in with that same applicant to inspect Past support. Completion is not pre-recorded for the tester.',
    438: '4. For the School supplies kit, set In person, the structured venue and map pin, collection instructions, and the proof required after distribution. A supplies kit is an in-kind benefit, not a bank transfer or mentoring session.',
    444: 'A published release schedule is a plan, not proof of delivery. The release officer records distribution for each recipient and attaches the required evidence. The school supplies kit has collection instructions and a venue map; bank and e-wallet awards require payout details and provider transfer evidence.',
    476: '4. The reset does not pre-record a missed collection. cohort.process.award.followup starts with an upcoming collection appointment. Create the missed-release branch only after its actual scheduled time and actual non-attendance, then follow the notice, reminder and response deadlines. Do not backdate contact or change the clock to bypass safeguards.',
    480: 'Process Learner 12 starts with an upcoming Study Essentials Award collection. There is no open notice or missed outcome. The following reply steps apply only after the tester legitimately records a missed collection and sends the required follow-up notice.',
    481: '1. Once a follow-up notice actually exists, sign in as the applicant, open the award, Benefit releases and the release follow-up. Read the instructions and response deadline.',
    720: 'Both databases now contain only the new fictional catalog. Prior records are retained solely in private rollback backups, not in the active account directory. Current decisions and responses remain tester actions except for the successful prerequisites identified above. Shared passwords and sample evidence must not be used for a real launch.',
    722: 'No failed, rejected, terminated or official waitlisted outcome is pre-recorded. The additional collection account has an upcoming schedule, not a missed or unclaimed award. Concern, appeal and missed-collection branches are created by the tester when their prerequisites are genuinely met.',
}
for index, text in updates.items():
    from docx.text.paragraph import Paragraph
    Paragraph(blocks[index], doc).text = text

def photo(stem):
    for area in ['screenshots', 'document-actions', 'document-extra']:
        path = PHOTOS / area / (stem + '.png')
        if path.is_file():
            return path
    raise FileNotFoundError(stem)

# Each replacement is an actual current platform capture, never a relabelled old image.
figures = {
1: ('registration', 'Not signed in'), 2: ('applicant-profile-loaded', 'cohort.learner.apply'),
3: ('admin-applicant-verification','novacore.reviews'),4:('provider-profile','nuvareya.profile'),5:('admin-provider-verification','novacore.reviews'),
6:('provider-programs','nuvareya.programs'),7:('admin-program-review','novacore.reviews'),8:('applicant-find-scholarships','cohort.learner.apply'),
9:('applicant-documents','cohort.learner.screening'),10:('applicant-application','cohort.learner.screening'),
11:('provider-screening','nuvareya.reviewer'),12:('provider-screening','nuvareya.reviewer'),13:('provider-formal','nuvareya.selection'),
14:('provider-sessions','nuvareya.selection'),15:('applicant-exam','cohort.learner.exam.confirm'),16:('applicant-interview','cohort.learner.interview.confirm'),
17:('selection-interview-evaluation','nuvareya.selection'),18:('decision-final-outcome','nuvareya.decisions'),19:('decision-ranked-candidates','nuvareya.decisions'),
20:('decision-stage-breakdown','nuvareya.decisions'),21:('selection-add-score-dialog','nuvareya.selection'),22:('applicant-agreement','cohort.learner.agreement'),
23:('provider-recipient-concerns','nuvareya.recipients'),24:('applicant-check-in','cohort.learner.monitoring.appointment'),
25:('monitoring-academic-result','nuvareya.monitoring'),26:('monitoring-plan','nuvareya.monitoring'),27:('monitoring-plan','nuvareya.monitoring'),
28:('monitoring-roster','nuvareya.monitoring'),29:('admin-monitoring-loaded','novacore.reviews'),30:('recipient-support-closeout','nuvareya.recipients'),
31:('applicant-support-history','cohort.process.support.history'),32:('recipient-support-closeout','nuvareya.recipients'),
33:('provider-release-schedule','nuvareya.releases'),34:('provider-release-detail','nuvareya.releases'),35:('applicant-payout-form','cohort.learner.payout.details'),
36:('provider-release-upcoming','nuvareya.releases'),37:('provider-release-detail','nuvareya.releases'),38:('provider-release-upcoming','nuvareya.releases'),
39:('provider-release-upcoming','nuvareya.releases'),40:('applicant-receipt','cohort.process.award.receipt'),41:('provider-delivery-reports','nuvareya.releases'),
42:('provider-recipient-concerns','nuvareya.recipients'),43:('provider-recipient-concerns','nuvareya.recipients'),44:('provider-delivery-reports','nuvareya.releases'),
45:('admin-independent-cases','novacore.reviews'),46:('provider-support','nuvareya.support'),47:('admin-support','novacore.support'),48:('provider-team','nuvareya.team'),
49:('admin-accounts','novacore.accounts'),50:('provider-billing','nuvareya.billing'),51:('admin-billing-detail','novacore.billing'),
52:('admin-finance-detail','novacore.finance'),53:('admin-finance-detail','novacore.finance'),54:('admin-records','novacore.records'),
55:('provider-manager','nuvareya.manager'),56:('provider-representative','nuvareya'),57:('admin-manager','novacore.manager'),58:('superadmin-dashboard','novacore.root'),
}
for figure in inventory['figures']:
    stem, account = figures[figure['figure']]
    image = photo(stem)
    p = Paragraph(blocks[figure['block_index']], doc)
    p.clear()
    with Image.open(image) as im:
        width, height = im.size
    inches = min(6.65, 4.3 * width / height)
    shape = p.add_run().add_picture(str(image), width=Inches(inches))
    shape._inline.docPr.set('descr', f'{stem}; account {account}; captured from hosting November 13 2026')
    p.add_run(f'\nCurrent hosted view | {account} | {stem.replace("-", " ")}').italic = True
    labels = set()
    for previous in reversed(blocks[max(0, figure['block_index'] - 18):figure['block_index']]):
        if not previous.tag.endswith('}p'):
            continue
        caption = Paragraph(previous, doc)
        if caption.style.name == 'Heading 1':
            break
        for label, value in [('Signed in account:', account), ('Page shown:', stem.replace('-', ' ').capitalize())]:
            if label not in labels and caption.text.startswith(label):
                caption.text = label + ' ' + value
                labels.add(label)

# Drop old image relationships, including the unused screenshots retained by previous edits.
used = set(doc.element.xpath('.//a:blip/@r:embed'))
for rid, relationship in list(doc.part.rels.items()):
    if relationship.reltype.endswith('/image') and rid not in used:
        del doc.part.rels[rid]
doc.save(GUIDE)
with ZipFile(GUIDE) as package:
    content = {name: package.read(name) for name in package.namelist()}
for name, raw in list(content.items()):
    if name.endswith('.xml') and name.startswith('word/'):
        content[name] = replace(raw.decode('utf-8')).encode('utf-8')
out = io.BytesIO()
with ZipFile(out, 'w', ZIP_DEFLATED) as package:
    for name, data in content.items(): package.writestr(name, data)
GUIDE.write_bytes(out.getvalue())
with ZipFile(GUIDE) as package:
    text = '\n'.join(package.read(name).decode() for name in package.namelist() if name.startswith('word/') and name.endswith('.xml'))
    assert not re.search(r'Talunaw|Sidlakbay|Hirayalon|demoops|demo\.student|demo\.process', text)
    old_hashes = {row['sha256'] for row in inventory['images'].values()}
    media = [name for name in package.namelist() if name.startswith('word/media/')]
    fresh_hashes = {hashlib.sha256(photo(stem).read_bytes()).hexdigest() for stem, _ in figures.values()}
    assert all(hashlib.sha256(package.read(name)).hexdigest() in fresh_hashes for name in media)
    # The public registration page may be byte-identical; its new capture contains no old identity.
    registration_hash = hashlib.sha256(photo('registration').read_bytes()).hexdigest()
    assert all(hashlib.sha256(package.read(name)).hexdigest() not in old_hashes or hashlib.sha256(package.read(name)).hexdigest() == registration_hash for name in media)
assert len(Document(GUIDE).inline_shapes) == 58
(AREA / 'updated.json').write_text(json.dumps({'guide':str(GUIDE),'figures':58,'fresh_images':len(media),'old_images_remaining':0}, indent=2))
lines = ['# Fresh Workflow Demo', '', 'Local and hosting were reset on November 13, 2026. All identities are fictional; applicant photos use the neutral silhouette.', '',
         'All accounts use `password123`. Old accounts are no longer valid. The superadmin is `novacore.root` and provider manager is `nuvareya.manager`.', '',
         '## Accounts By Process', '', '| Applicant account | Name | Program | Current stage |', '|---|---|---|---|']
for row in manifest['applications']:
    lines.append(f"| `{row['username']}` | {row['name']} | {row['program']} | {row['stage']} |")
lines += ['', 'Applicants without applications: `cohort.learner.profile` completes profile evidence; `cohort.learner.verification` awaits admin profile verification; `cohort.learner.apply` is ready to apply.', '',
          '## Provider And Admin Roles', '', '| Account | Role | Organization |', '|---|---|---|']
for row in manifest['accounts']:
    if row['role'] != 'applicant':
        lines.append(f"| `{row['username']}` | {row['title'] or 'Superadmin'} | {row['organization'] or 'Platform administration'} |")
lines += ['', '## Demonstration Order', '',
          '1. Register or complete the profile, then use `novacore.reviews` to verify supporting evidence.',
          '2. Use the organization profile, program coordinator and admin review roles to prepare and publish programs.',
          '3. Apply to the open Campus Pathways Grant. The other stage rounds have closed intakes and later activity dates.',
          '4. Use `nuvareya.reviewer` for screening and `nuvareya.selection` for scheduling, attendance, session proof and scored outcomes.',
          '5. Use `cohort.learner.exam.result` and `cohort.learner.interview.result` to demonstrate the current blank evaluation forms. Missing scores are not zero.',
          '6. Use `nuvareya.decisions` and the Scholar Selection Round for three ranked applicants and two slots. The tester decides selection or waitlisting.',
          '7. Use the agreement, recurring-support and one-time-award accounts to demonstrate acceptance, document handoffs, benefit release, payout details, receipt confirmation and closeout.',
          '8. Use the specialist admin roles for verification, support, account handoffs, service delivery, finance and audit history.',
          '9. Finish with `nuvareya.manager`, the organization representative, `novacore.manager`, and `novacore.root`.', '',
          'No rejected, failed, terminated, official waitlisted or missed-collection outcome is seeded. The tester creates those branches only when their safeguards are met.',
          'Published benefits use School supplies kit, not mentoring. All applications have fixed scoring snapshots; formal verification is a qualifying check. Prepared programs have no short application questions.', '',
          'The existing expanded Word process guide in `deliverables` contains the detailed record-level instructions and fresh hosted screenshots.']
(ROOT / 'docs/fresh-workflow-demo.md').write_text('\n'.join(lines) + '\n', encoding='utf-8')
print('UPDATED_EXISTING_GUIDE; 58 current figure placements; old identities and images removed')
