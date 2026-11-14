"""Update the canonical process guide in place for the hosted single-provider demo."""
import hashlib
import io
import json
import re
from datetime import datetime, timezone
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

from docx import Document
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Inches, Pt, RGBColor
from docx.text.paragraph import Paragraph
from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
GUIDE = ROOT / 'deliverables/Platform_Process_Guide_With_Accounts_Expanded.docx'
AREA = ROOT / '.tmp-docx/single-provider-guide-20261114'
PHOTOS = Path('C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/single-provider-guide-20261114')
inventory = json.loads((AREA / 'inventory.json').read_text())
report = json.loads((PHOTOS / 'browser.json').read_text())
assert report['passed'], report.get('captureFailures')
assert hashlib.sha256(GUIDE.read_bytes()).hexdigest() == inventory['summary']['source_sha256'], 'The guide changed during preparation; inspect before overwriting it.'
manifest = json.loads((ROOT / 'storage/deployment/single-provider-candidate-20261114-bff7e7bb/storage/app/private/single-provider-demo-20261114/manifest.json').read_text())

actors = {
    'profile':'profile', 'verification':'verification', 'apply':'aurora', 'screening':'aurora', 'formal':'brio',
    'exam.assign':'brio', 'exam.confirm':'cielo', 'exam.complete':'dune', 'exam.result':'aurora',
    'interview.assign':'cielo', 'interview.confirm':'brio', 'interview.result':'aurora', 'decision':'aurora',
    'agreement':'aurora', 'release.plan':'brio', 'monitoring.appointment':'cielo', 'monitoring.review':'cielo',
    'monitoring.close':'dune', 'support.decision':'brio', 'release.record':'brio', 'release.confirm':'aurora',
    'onetime.plan':'aurora', 'onetime.record':'brio', 'onetime.confirm':'cielo', 'partner.screening':'aurora',
    'payout.details':'dune', 'payout.review':'dune', 'payout.transfer':'dune',
}
process = {'dss.first':'aurora', 'dss.second':'brio', 'dss.third':'cielo', 'dss.score':'dune',
           'support.appointment':'cielo', 'support.review':'cielo', 'support.close':'dune', 'support.decision':'brio',
           'support.history':'brio', 'award.receipt':'cielo', 'award.complete':'dune', 'award.followup':'brio'}
replacements = [(f'cohort.learner.{key}', f'learner.{name}') for key, name in actors.items()]
replacements += [(f'cohort.process.{key}', f'learner.{name}') for key, name in process.items()]
replacements += [(f'Learner Profile {i:02}', 'Applicant ' + name.capitalize()) for i, name in enumerate(actors.values(), 1)]
replacements += [(f'Process Learner {i:02}', 'Applicant ' + name.capitalize()) for i, name in enumerate(process.values(), 1)]
programs = {
    'Nuvareya Campus Pathways Grant':'Orivanta Open Learning Grant',
    'Nuvareya Classroom Bridge Grant':'Orivanta Campus Access Scholarship',
    'Nuvareya Applied Science Scholarship':'Orivanta Applied Science Scholarship',
    'Nuvareya Community Leadership Scholarship':'Orivanta Leadership Scholarship',
    'Nuvareya Senior Scholars Award':'Orivanta Academic Merit Scholarship',
    'Nuvareya Scholar Selection Round':'Orivanta Academic Merit Scholarship',
    'Nuvareya Assessment Completion Round':'Orivanta Academic Merit Scholarship',
    'Nuvareya Continuing Studies Scholarship':'Orivanta Continuing Learners Grant',
    'Nuvareya Learning Continuity Grant':'Orivanta Continuing Learners Grant',
    'Nuvareya Study Essentials Award':'Orivanta School Essentials Award',
    'Nuvareya Learning Kit Award':'Orivanta School Essentials Award',
    'Nuvareya School Start Award':'Orivanta School Essentials Award',
    'Nuvareya Digital Horizons Award':'Orivanta College Pathways Award',
    'Nuvareya Skills Lab Grant':'Orivanta Digital Learning Grant',
    'Velasora Study Access Grant':'Orivanta Open Learning Grant',
    'Velasora School Essentials Award':'Orivanta School Essentials Award',
    'Velasora Learning Routes Grant':'Orivanta Digital Learning Grant',
    'Nuvareya Learning Trust':'Orivanta Learning Foundation',
    'Velasora Education Foundation':'Orivanta Learning Foundation',
    'Kinoraya Study Guild':'Orivanta Learning Foundation',
}
replacements += list(programs.items())
replacements += [('nuvareya','orivanta'), ('novacore','lumenops'), ('kinoraya','orivanta.profile'), ('velasora','orivanta'),
                 ('Nuvareya','Orivanta'), ('Velasora','Orivanta'), ('Kinoraya','Orivanta')]
replacements.sort(key=lambda item: len(item[0]), reverse=True)

def replace(text):
    for old, new in replacements:
        text = text.replace(old, new)
    return text

doc = Document(GUIDE)
blocks = list(doc.element.body)
for paragraph in doc.element.xpath('.//w:p'):
    nodes = paragraph.xpath('.//w:t')
    text = ''.join(node.text or '' for node in nodes)
    changed = replace(text)
    if changed != text and nodes:
        nodes[0].text = changed
        for node in nodes[1:]: node.text = ''

updates = {
    3: 'Use Orivanta Open Learning Grant for a new application. The other published programs have closed intakes and prepared selection or support records. The same four applicants have records in several scholarships, so always check both the applicant name and the program. Use Orivanta Academic Merit Scholarship for DSS, Continuing Learners Grant for agreement and document review, Term Assistance Grant for receipt and renewal, and School Essentials Award for one-time support.',
    4: 'Updated November 14 2026 Philippine time. Only hosting was reset. Local data and its older accounts were not changed. Hosting has one provider organization, Orivanta Learning Foundation, with 28 accounts, 6 applicants, 10 scholarships and 25 applications. Four verified applicants hold 5 to 7 applications each; the other two accounts demonstrate profile completion and verification. Old hosted logins no longer work.',
    5: 'Screenshots in this guide are current captures from the hosted single-provider dataset. They identify the signed-in account and record context. Applicant pictures are neutral silhouettes. Capturing the screens did not save decisions, submit scores, verify accounts, confirm receipts or make payments.',
    50: 'Applicant accounts and scholarship records',
    51: 'Use learner.aurora, learner.brio, learner.cielo and learner.dune throughout the walkthrough. Each account has several scholarship records at different starting points; one application still follows one stage at a time. learner.profile completes its evidence and learner.verification awaits admin review. Published demo programs allow other scholarship support, so these parallel awards do not contradict eligibility. Every submitted application has a fixed scoring policy.',
    54: 'There is one organization only. Its specialist logins are orivanta.programs, orivanta.reviewer, orivanta.selection, orivanta.decisions, orivanta.recipients, orivanta.monitoring, orivanta.releases, orivanta.profile, orivanta.team, orivanta.support and orivanta.billing. orivanta.manager has combined workspaces; orivanta is the representative. orivanta.newstaff demonstrates first-login password setup. These staff accounts are not additional provider organizations.',
    55: 'Admin logins are lumenops.accounts, lumenops.reviews, lumenops.support, lumenops.billing, lumenops.finance and lumenops.records. Use lumenops.manager for portal oversight and lumenops.root for superadmin governance. The single provider is already approved; there is no second organization waiting for verification. Show manager and superadmin views after the specialist actions.',
    108: 'Use lumenops.reviews to inspect Orivanta Learning Foundation in Provider verification. Its current organization and supporting files are already approved so the operational demo can proceed. The screenshot shows this existing approved record, not an account awaiting a fresh approval. For a new or reopened review, inspect each current file before recording the separate final provider account decision. Verification is not scholarship publication.',
    112: '1. Sign in as lumenops.reviews, open Provider verification and select Orivanta Learning Foundation. Read its saved approval and individual proof history. To demonstrate a fresh review, first deliberately reopen the appropriate review or replace evidence through its actual workflow; do this only after the operational demonstration if it would block provider access.',
    115: '4. In a fresh review, repeat for each current file. Unreviewed, unavailable and replacement-requested evidence blocks approval. Reload if another reviewer or upload changes the record. Do not resave an existing approval merely to make a demonstration look complete.',
    122: '4. The hosted provider is already approved. This section explains the review workflow without changing that status. Reopening verification or replacing required proof can affect operational access; finish the other provider demonstrations first. Scholarship publication and applicant selection remain separate decisions.',
    134: 'Use orivanta.programs with the draft Orivanta Digital Learning Grant. Complete Basics, Support, Schedule, Eligibility, Application and Selection, then inspect the actual values in Review. Saving a draft is private; submitting sends the program to admin publication review. Orivanta College Pathways Award is already awaiting that review.',
    157: 'Use Orivanta Digital Learning Grant for program-builder changes. The six improvements preserve the existing workflow: save and leave protection, rule-based eligibility wording, guided options, organization-location reuse, clear DSS weights, and an actual-value final Review step.',
    158: '1. In Eligibility, choose education levels and use the compact Add controls for courses, years and locations. Selected values stay inside their fields. Changing education levels warns before clearing known incompatible choices; resolve replacement choices and review retained custom entries before saving.',
    159: '2. Keep mandatory rules separate from preferences. Use the rule-based eligibility summary or review a custom summary against the current rules. A change can invalidate that confirmation. In Schedule, Use organization location copies the address and pin into this program; a different venue requires confirmation and later manual address edits clear the old pin.',
    160: '3. In Selection, inspect the actual DSS contribution of screening, exam and interview. Eligibility, document completeness and formal verification are qualifying checks, not additional competitive points. First application submission fixes the intake policy; do not change a scored intake to rewrite earlier applications.',
    161: '4. In Review, inspect the actual title, branding, benefits, capacity, dates, venue, contact details, rules, file lists, stage order, scoring basis and recipient terms. Expand longer details and use the section Edit button to correct values. Review includes unsaved form changes; it is not the applicant preview of last saved data.',
    162: '5. Save the draft or submit after resolving the existing readiness checks and accepting the provider declaration. Save status distinguishes unsaved, saving, saved and failed changes. The leave confirmation protects unsaved edits. Publication remains a separate admin decision; a completed section is not an approval.',
    234: '3. Record the formal verification or required activity details and attach provider proof where the configured stage requires it. Checking the originals and recording the applicant outcome are separate actions.',
    239: 'The selection officer assigns eligible applicants to sessions. Separate sessions support different dates or venues, while online interviews use a meeting link. For the Applied Science Scholarship use learner.brio for assignment, learner.cielo for exam acknowledgment and learner.dune for session completion. Session attendance and an individual result are separate records.',
    285: 'Orivanta Academic Merit Scholarship has four current candidates and two slots. learner.aurora, learner.brio and learner.cielo have complete evaluations. learner.dune has a completed interview with its numeric evaluation still missing. The comparison therefore starts provisional. Demonstrate entering the missing evaluation before relying on selection or waitlist suggestions.',
    288: '1. As orivanta.decisions, open Pending decisions, choose Orivanta Academic Merit Scholarship and open Applicant Aurora. Read the saved evaluations, capacity and provisional comparison reason. Applicant Dune is in this same intake, not a second scoring scholarship.',
    289: '2. The current three totals are 95.5, 90.6 and 85.0; Dune has no final score. Because a pending evaluation is missing, do not describe the comparison as Ready to compare. Complete the missing assessment in the selection workspace, then reload and use the actual revised cohort order.',
    295: 'Choose Orivanta Academic Merit Scholarship. Applicant Aurora, Applicant Brio and Applicant Cielo have completed scores; Applicant Dune still needs an interview evaluation. All four final outcomes remain unsaved, and two award slots are available.',
    296: '1. Begin with the provisional comparison and its explanation. Follow Complete a missing evaluation before ranking for learner.dune. After the selection officer saves that genuine evaluation, reload this program and check that the comparison has become ready.',
    297: '2. Inspect the actual four-candidate scores, ranks, ties and two remaining slots. Dune enters the order using the score the tester records, so do not assume the first two prepared rows will remain the highest. Suggestions are advisory and are not official outcomes.',
    313: 'Use Applicant Dune, learner.dune, in Orivanta Academic Merit Scholarship. Its interview has attendance, provider proof and a passed result, but no numeric evaluation. This deliberately holds the comparison provisional until the tester records the real score.',
    336: 'Use orivanta.recipients to inspect Applicant Brio in Orivanta Term Assistance Grant. The agreement is accepted and its original-document check is closed. The support-status record is ready for a deliberate renewal or completion decision; agreement concerns and withdrawal branches are separate and are not pre-recorded.',
    343: 'Use learner.cielo and select Orivanta Term Assistance Grant in Scholarship Support. This is the upcoming original-document appointment. Cielo also has a separate Continuing Learners Grant record where attendance is already recorded and the provider is reviewing documents; do not confuse the two programs.',
    346: '1. Open Orivanta Term Assistance Grant, then Check-ins and the upcoming appointment. Read its date, time, exact venue, map pin and the original records requested.',
    358: '1. As orivanta.monitoring, open Orivanta Continuing Learners Grant and select Applicant Cielo in the attended check-in. Attendance is recorded; current academic and enrollment results still need the provider review.',
    385: 'The attended Continuing Learners Grant roster has Applicant Cielo awaiting document review and Applicant Dune ready for closure. The separate closed example for Applicant Brio belongs to Term Assistance Grant. Select both the correct program and person before opening an item.',
    387: '2. Review Applicant Cielo in Continuing Learners Grant. Open Current academic record, inspect the physical original, and record the verified grade, scale, result and notes. Repeat for enrollment. Applicant Dune in this same grant has both required items met and can demonstrate Close check-in.',
    408: 'Open Applicant Brio, learner.brio, in Orivanta Term Assistance Grant as orivanta.recipients, then Support status. Its check-in is closed and both allowance and school-supplies receipts are confirmed. Renew support if the award continues, or complete support when the agreed obligations are finished. The current support decision is deliberately left to the tester. Outstanding schedules, unconfirmed receipts and unresolved delivery issues block normal completion.',
    414: 'The screenshot shows learner.brio in the Term Assistance Grant support history before a current renewal or completion has been saved. There is no separate pre-renewed applicant. First record the intended eligible support decision as orivanta.recipients, then return with this same applicant to inspect its saved reason, effective date and available response. Completed support moves to Past support only after completion.',
    419: 'Applicant Brio in Orivanta Term Assistance Grant is the prepared renewal/completion record. No renewal response is pre-created. The same learner.brio account will read and acknowledge the outcome after the tester saves it.',
    420: '1. Open Applicant Brio in Term Assistance Grant and inspect Support status. Check the closed document review, accepted agreement and settled cash and school-supplies benefits. Choose Renewed only when the provider intends to continue support.',
    423: '4. Do not look for a separate successful-renewal account. The response becomes available on learner.brio after this actual provider decision. Refresh the history if another tab was already open.',
    434: 'Use learner.brio in Continuing Learners Grant for a first release that is not yet scheduled, or learner.aurora in School Essentials Award for one-time release planning. A selected outcome alone is insufficient; the prepared examples have already accepted their agreements.',
    443: 'Page shown: Provider benefit distribution record',
    455: '1. Use a newly scheduled bank or e-wallet release that actually requests destination details to demonstrate entering them. The prepared learner.dune Term Assistance Grant record has already submitted its fictional e-wallet destination, so its current screen demonstrates the submitted status rather than an empty entry form. The provider must review it next.',
    460: 'Signed in account: orivanta.releases',
    461: 'Use the scheduled Term Assistance Grant release for Applicant Dune. Its e-wallet details are submitted and still await provider review. Compare only the information necessary to confirm the destination; request a correction with a specific reason when it cannot be confirmed.',
    467: '1. For this same Applicant Dune release, finish the payout destination review first. The reset does not include a separate pre-approved bank transfer. Only after approval should the release officer record the actual simulated transfer date, reference and required provider proof. The platform records the evidence; it does not send bank money itself.',
    476: 'The reset does not pre-record a missed collection. learner.brio in Orivanta School Essentials Award has an upcoming collection appointment. Create a missed-release branch only after the scheduled time and actual non-attendance, then follow the required notice, reminder and response periods. Do not backdate contact or change the clock to unlock a shortcut.',
    480: 'Use learner.brio and School Essentials Award for the upcoming collection. There is no open missed-benefit notice. The reply instructions apply only after the tester legitimately records a missed collection and issues the required notice.',
    488: 'Page shown: Applicant benefit receipt record',
    499: 'Signed in account: orivanta.recipients',
    500: 'Use Applicant Dune, learner.dune, in Orivanta School Essentials Award. The one-time benefit is released and its receipt confirmed, so the provider can demonstrate completion. There is no recurring monitoring plan for this award, and the completion decision itself remains for the tester.',
    594: '2. The existing platform case Explain my notification settings belongs to learner.brio. Read its actual scope and reply as appropriate. No account-access referral has been seeded. If a genuine access task is needed for a demonstration, create that specific support request first, then use Send to Account Manager with a limited instruction.',
    596: '4. After Account Manager actually returns a newly created task, reopen the same support case, read the returned result and confirm the reporter-facing reply. The reset does not contain a separate pre-returned task. Returning an internal result does not itself send the final support response.',
    617: '1. Open the account directory and select Applicant Aurora, learner.aurora, to inspect the current account controls. There is no pre-created support referral in this dataset. To demonstrate Return result, first create a justified access request and send it from lumenops.support, then open that task here.',
    651: '1. Open the existing paid Application cycle support service whose delivery is In progress. It belongs to Orivanta. The service is ready for operational work, not a pre-created Finance follow-up; a Finance question must be sent through its actual workflow before Respond to Finance is available.',
    663: '1. Open the paid Application cycle support payment or receipt for Orivanta, such as the service currently in progress. There is no pre-returned Billing clarification in this reset. Inspect its real payment and delivery fields before deciding whether an internal review question is needed.',
    669: 'The new dataset has ordinary support and service records, but it does not pre-create the internal Support-to-Account-Manager or Finance-to-Billing exchanges. Demonstrate those handoffs by creating the first legitimate request and returning to that same record. A question and its response must actually exist before the next handoff action is available.',
    670: '1. For an access handoff, use a suitable account-access support case, assign a limited task as lumenops.support, then open the task as lumenops.accounts. Inspect the controls and Return result without changing access unless an access action is actually necessary.',
    671: '2. Return to that same case as lumenops.support. Read the Account Manager finding and record the reporter-facing response. There is no separate account or seeded returned case for this step.',
    672: '3. For Finance clarification, open a current paid service as lumenops.finance, use Request a Billing record check and confirm the precise question. Switch to lumenops.billing and respond in the same service record.',
    673: '4. Reopen the same service as lumenops.finance, inspect the Billing response, then record the financial check and its finding. The prepared payment remains separate from the operational delivery status.',
    720: 'Only hosting contains this new one-provider dataset. The local database still holds its earlier accounts and records; do not expect these hosted usernames to exist locally. Verified private rollback backups retain the previous hosted database. Current decisions and responses remain tester actions except for the successful prerequisites identified here. Shared passwords and sample evidence are not for a real launch.',
    722: 'No failed, rejected, terminated, official waitlisted, missed or unclaimed applicant outcome is pre-recorded. Current concern, withdrawal, appeal, account-referral and Finance-follow-up branches must be created by the tester through their real prerequisites. The one provider is already approved; there is no second pending provider.',
}
for index, text in updates.items():
    assert not blocks[index].xpath('.//w:drawing'), index
    Paragraph(blocks[index], doc).text = text

# Preserve the existing account table, but associate repeated logins with their actual scholarships.
table = doc.tables[0]
for row in list(table.rows)[1:]: table._tbl.remove(row._tr)
for cell, title in zip(table.rows[0].cells, ['Applicant login', 'Scholarship and starting action', 'Responsible account']): cell.text = title
records = [
    ('learner.profile', 'Complete the profile and supporting evidence', 'Applicant'),
    ('learner.verification', 'Review submitted profile evidence', 'lumenops.reviews'),
    ('learner.aurora', 'Orivanta Open Learning Grant\nStart a new application', 'Applicant'),
]
for item in manifest['scenarios']:
    scenario = item['scenario']
    actor = 'orivanta.reviewer' if scenario == 'screening' else 'orivanta.selection' if scenario in ['formal','exam.assign','exam.complete','exam.result','interview.assign','interview.result'] else 'orivanta.decisions' if scenario == 'decision' else 'orivanta.monitoring' if scenario in ['monitoring.review','monitoring.close'] else 'orivanta.recipients' if scenario in ['support.decision','onetime.complete'] else 'orivanta.releases' if scenario in ['release.plan','payout.review','onetime.plan','onetime.record'] else 'Applicant'
    if scenario == 'decision' and item['username'] == 'learner.dune': actor = 'orivanta.selection'
    records.append((item['username'], item['program']+'\n'+item['next_action'], actor))
for record in records:
    for cell, text in zip(table.add_row().cells, record): cell.text = text
table.autofit = False
widths = [1.4, 4.15, 1.65]
for column, width in zip(table.columns, widths): column.width = Inches(width)
for row_index, row in enumerate(table.rows):
    properties = row._tr.get_or_add_trPr()
    properties.append(OxmlElement('w:cantSplit'))
    if row_index == 0: properties.append(OxmlElement('w:tblHeader'))
    for cell, width in zip(row.cells, widths):
        cell.width = Inches(width)
        props = cell._tc.get_or_add_tcPr()
        margins = OxmlElement('w:tcMar')
        for edge in ['top','bottom','left','right']:
            node = OxmlElement('w:'+edge); node.set(qn('w:w'),'80' if edge in ['top','bottom'] else '110'); node.set(qn('w:type'),'dxa'); margins.append(node)
        props.append(margins)
        if row_index == 0:
            fill = OxmlElement('w:shd'); fill.set(qn('w:fill'),'EDEFF2'); props.append(fill)
        for paragraph in cell.paragraphs:
            paragraph.paragraph_format.keep_with_next = row_index == 0
            paragraph.paragraph_format.space_after = Pt(2)
            for run in paragraph.runs:
                run.font.size = Pt(10.5); run.bold = row_index == 0; run.font.color.rgb = RGBColor(0,0,0)
borders = OxmlElement('w:tblBorders')
for edge in ['top','left','bottom','right','insideH','insideV']:
    node = OxmlElement('w:'+edge); node.set(qn('w:val'),'single'); node.set(qn('w:sz'),'4'); node.set(qn('w:color'),'D9D9D9'); borders.append(node)
table._tbl.tblPr.append(borders)

figures = {
    1:'registration',2:'applicant-profile',3:'admin-applicant-review',4:'provider-profile',5:'admin-provider-review',6:'provider-program-review',
    7:'admin-program-review',8:'applicant-scholarships',9:'applicant-documents',10:'applicant-application',11:'provider-screening',12:'provider-screening',
    13:'provider-formal',14:'provider-sessions',15:'applicant-exam',16:'applicant-interview',17:'provider-interview-result',18:'decision-record',
    19:'decision-comparison',20:'decision-breakdown',21:'selection-score-dialog',22:'applicant-agreement',23:'recipient-closeout',24:'applicant-check-in',
    25:'monitoring-academic-result',26:'monitoring-plan',27:'monitoring-plan',28:'monitoring-ready-to-close',29:'admin-monitoring',30:'recipient-closeout',
    31:'applicant-history',32:'recipient-closeout',33:'provider-release-schedule',34:'provider-release-record',35:'applicant-payout',36:'provider-payout-review',
    37:'provider-payout-review',38:'provider-release-record',39:'applicant-upcoming-release',40:'applicant-receipt',41:'recipient-one-time-closeout',
    42:'recipient-withdrawals',43:'recipient-concerns',44:'provider-delivery-reports',45:'admin-case-review',46:'provider-support',47:'admin-support',
    48:'provider-team',49:'admin-account',50:'provider-billing',51:'admin-billing',52:'admin-finance',53:'admin-finance',54:'admin-records',
    55:'provider-manager',56:'provider-representative',57:'admin-manager',58:'superadmin',
}
captures = {item['name']: item for item in report['captureDetails']}
for figure in inventory['figures']:
    name = figures[figure['figure']]
    capture = captures[name]
    path = PHOTOS / (name+'.png')
    with Image.open(path) as image: width, height = image.size
    paragraph = Paragraph(blocks[figure['block_index']], doc)
    paragraph.clear()
    paragraph.paragraph_format.keep_together = True
    shape = paragraph.add_run().add_picture(str(path), width=Inches(min(6.65,4.3*width/height)))
    shape._inline.docPr.set('descr',f'{name}; signed in as {capture["account"]}; actual hosted capture November 14 2026')
    caption = paragraph.add_run('\nCurrent hosted view | '+capture['account']+' | '+name.replace('-',' '))
    caption.italic = True; caption.font.size = Pt(9)
    labels = set()
    for previous in reversed(blocks[max(0,figure['block_index']-18):figure['block_index']]):
        if not previous.tag.endswith('}p'): continue
        candidate = Paragraph(previous,doc)
        if candidate.style.name in ['Heading 1','Heading 2']: break
        for prefix, value in [('Signed in account:',capture['account']),('Page shown:',name.replace('-',' ').capitalize())]:
            if prefix not in labels and candidate.text.startswith(prefix): candidate.text = prefix+' '+value; labels.add(prefix)

for index in [443,488]: Paragraph(blocks[index],doc).text = updates[index]

# Remove old screenshot parts so no obsolete names remain embedded in the guide.
used = set(doc.element.xpath('.//a:blip/@r:embed'))
for rid, relation in list(doc.part.rels.items()):
    if relation.reltype.endswith('/image') and rid not in used: del doc.part.rels[rid]
for style in doc.styles:
    if style.name in ['Title','Subtitle'] or style.name.startswith('Heading'):
        style.font.color.rgb = RGBColor(0,0,0)
for paragraph in doc.paragraphs:
    if paragraph.style.name in ['Title','Subtitle'] or paragraph.style.name.startswith('Heading'):
        for run in paragraph.runs: run.font.color.rgb = RGBColor(0,0,0)
doc.core_properties.modified = datetime(2026,11,14,0,0,tzinfo=timezone.utc)
buffer = io.BytesIO(); doc.save(buffer)
with ZipFile(buffer) as package: contents = {name:package.read(name) for name in package.namelist()}
for name, raw in list(contents.items()):
    if name.endswith(('.xml','.rels')):
        contents[name] = replace(raw.decode('utf-8')).encode('utf-8')
result = io.BytesIO()
with ZipFile(result,'w',ZIP_DEFLATED) as package:
    for name, raw in contents.items(): package.writestr(name,raw)
assert hashlib.sha256(GUIDE.read_bytes()).hexdigest() == inventory['summary']['source_sha256'], 'The guide was edited elsewhere; not overwriting it.'
with ZipFile(io.BytesIO(result.getvalue())) as package:
    xml = '\n'.join(package.read(name).decode('utf-8') for name in package.namelist() if name.endswith(('.xml','.rels')))
assert not re.search(r'Nuvareya|Velasora|Kinoraya|novacore|nuvareya|cohort\.(learner|process)|Learner Profile \d|Process Learner \d',xml)
assert len(Document(io.BytesIO(result.getvalue())).inline_shapes) == 58
GUIDE.write_bytes(result.getvalue())
(AREA / 'updated.json').write_text(json.dumps({'guide':str(GUIDE),'figures':58,'current_screenshots':len(set(figures.values())),
    'account_rows':len(records),'scope':'hosting only','sha256':hashlib.sha256(GUIDE.read_bytes()).hexdigest()},indent=2))
print('UPDATED_EXISTING_GUIDE; 58 current figure placements; hosted accounts and reusable-applicant processes updated')
