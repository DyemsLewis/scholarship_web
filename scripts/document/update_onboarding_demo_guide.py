"""Point the existing onboarding walkthrough to the newly authorized hosted intake."""
import hashlib
import json
from pathlib import Path

from docx import Document
from docx.shared import Inches

ROOT = Path(__file__).resolve().parents[2]
AREA = ROOT / '.tmp-docx/recipient-onboarding-live-demo-20261118'
GUIDE = ROOT / 'deliverables/Platform_Process_Guide_With_Accounts_Expanded.docx'
PHOTOS = Path('C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/recipient-onboarding-part5-demo-hosted-20261118')
SOURCE_HASH = '485c490b5e6ba55f39a90ef365415385307cda09a3af8298b274f1388964fb4b'
assert hashlib.sha256(GUIDE.read_bytes()).hexdigest() == SOURCE_HASH, 'The canonical guide changed; inspect before updating.'
report = json.loads((ROOT / 'storage/deployment/recipient-onboarding-part5-demo-browser-20261118.json').read_text())
assert report['passed'] and report['intentionStillPending'] and not report['businessActionsSubmitted']
doc = Document(GUIDE)
changes = []

def replace(prefix, text):
    matches = [p for p in doc.paragraphs if p.text.startswith(prefix)]
    assert len(matches) == 1, (prefix, len(matches))
    matches[0].text = text
    changes.append(prefix)

replace('Updated November 18 2026 Philippine time for final provider agreement',
    'Updated November 18 2026 Philippine time for final provider agreement and onboarding. One separate intake, Orivanta Welcome Scholars Award, now has a selected offer awaiting learner.aurora\'s intention confirmation. No signing, distribution or receipt is pre-recorded for it. Earlier offers, tester-created records and passwords were preserved. Hosting and local databases remain different.')
replace('The onboarding setting screenshot was captured',
    'The Welcome Scholars offer, provider Onboarding, applicant Requirements and locked policy screenshots were captured from hosting on November 18. The later distribution-hold example is labelled an isolated local preview because the hosted offer is deliberately still unanswered. Earlier screenshots remain where their process is unchanged. Screenshot checks did not submit an intention response, agreement completion, benefit release or receipt.')
replace('7. Offer response: learner.aurora opens Continuing Learners Grant',
    '7. Offer and onboarding: learner.aurora opens Orivanta Welcome Scholars Award > Decision > Review and respond. Read the final-agreement requirement and confirm intention only when ready. Continue as orivanta.recipients to publish instructions, as orivanta.releases to arrange collection, and as the recipient officer to record actual agreement completion before distribution. Use Section 19 for the detailed handoffs. Earlier Continuing Learners offers remain legacy comparisons.')
replace('Use learner.aurora in Orivanta Continuing Learners Grant: the provider selected',
    'Use learner.aurora in Orivanta Welcome Scholars Award. The selected offer is awaiting the applicant\'s response and already discloses its final-agreement requirement. orivanta.recipients follows this same record after intention confirmation. The older Continuing Learners Grant can be used to compare an offer without a retroactive onboarding requirement; do not reset an accepted response.')
replace('1. Open Applications, choose the selected Continuing Learners Grant record',
    '1. Open Applications, choose Orivanta Welcome Scholars Award, then Decision > Review and respond. Check the scholarship title because learner.aurora has several application records. The application record remains accessible after the response; Scholarship Support is a separate destination.')
replace('2. Read the disclosed benefits, support period and provider expectations. A new intake',
    '2. Read the disclosed benefits, support period and provider expectations. Welcome Scholars displays Final provider agreement required and its instructions above the offer tabs before the applicant confirms intention. Actual contracts stay with the provider; the intention response is not a signature or consent to undisclosed obligations.')
replace('5. For an existing response, View offer summary',
    '5. After a response, View offer summary preserves the frozen offer. To demonstrate a new response, use the unanswered Welcome Scholars offer. Existing accepted records should not be reset just to repeat this step.')
replace('Existing hosted offers are legacy records. They correctly show',
    'Live starting point: Orivanta Welcome Scholars Award with learner.aurora. The program requirement was set before its prepared application, and the offer is still awaiting intention confirmation. The same applicant account can continue through onboarding, first benefit collection and receipt. No new login is needed. Earlier offers remain unchanged legacy examples; do not add this requirement to them retroactively.')
replace('Open Programs > Create program or an unused draft > Selection.',
    'As orivanta.programs, open Welcome Scholars Award > Edit > Selection to inspect the required final provider agreement and its disclosed instructions. It is locked because the intake already has an application. To demonstrate configuring a different intake, use Create program or an unused draft before applications, save and follow publication review. Actual contract files remain with the provider.')
replace('Signed in accounts: the selected applicant, then orivanta.recipients',
    'Signed in accounts: learner.aurora, then orivanta.recipients')
replace('1. The applicant opens Applications > the selected record > Decision',
    '1. As learner.aurora, open Applications > Orivanta Welcome Scholars Award > Decision > Review and respond. Read the required final agreement and the disclosed instructions, then confirm intention if proceeding. Selection stays Selected. The separate onboarding record starts Pending, never Completed.')
replace('2. The recipient officer opens Recipients > that same applicant and program > Onboarding.',
    '2. As orivanta.recipients, open Applicant Aurora in Orivanta Welcome Scholars Award > Onboarding. Until intention is confirmed, instructions and completion actions correctly remain unavailable. After the response, use Publish instructions to explain where to report, what to bring and how to request another arrangement. Confirm before publishing. A notification is sent; the original disclosed requirement is retained.')
replace('Signed in accounts: the applicant, then orivanta.releases',
    'Signed in accounts: learner.aurora, then orivanta.releases')
replace('3. The applicant opens Scholarship Support > the relevant program > Requirements',
    '3. As learner.aurora, open Scholarship Support > Orivanta Welcome Scholars Award > Requirements > Final agreement. Before responding, this says Review offer first. After intention confirmation, Overview links to Complete provider onboarding and published provider updates appear here. Read the instructions or use the recorded provider contact. Applicants cannot self-complete signing or upload the contract. One-time awards keep this Requirements tab.')
replace('4. The release officer may schedule the first collection while onboarding is pending',
    '4. After learner.aurora confirms intention, orivanta.releases opens Schedule release and selects Welcome Scholars Award. Pending final agreement alone does not block scheduling; the candidate shows Can schedule when all other readiness checks pass. Describe where signing happens in the same first-collection or orientation instructions. No appointment is pre-created. The event appears in Benefits & Events; the agreement stays in Requirements.')
replace('5. After checking the provider-held agreement records, the recipient officer',
    '5. Only after checking the provider-held agreement records, orivanta.recipients opens Welcome Scholars Award > Applicant Aurora > Onboarding > Record completion. Enter the actual date, optionally add a private reference, acknowledge and review the confirmation. The date cannot be future-dated or earlier than intention confirmation. The applicant sees completion and its date, not private notes or audit history. Attendance alone never completes this requirement.')
replace('6. The release officer reloads the distribution record. Pending onboarding',
    '6. As orivanta.releases, open the newly scheduled Welcome Scholars distribution record. Before completion, Released / received and transfer Sent are blocked, including direct requests; Prepared, Missed and Withheld remain available. After provider completion, reload the record and follow the normal scheduled-time, originals, monitoring and payout checks. Record only actual delivery, with its own proof and confirmation. This does not send money.')
replace('7. The applicant checks the actual benefit, uploads their receipt proof',
    '7. As learner.aurora, open the Welcome Scholars benefit after the provider records actual distribution. Check what was received, upload applicant receipt proof and confirm receipt, or report a problem. Provider onboarding completion and delivery evidence cannot make this response on the applicant\'s behalf. The hosted starting record deliberately has no such response yet.')

captions = {
    'Hosting November 16 | learner.aurora | pending Continuing Learners offer':
        ('hosted-welcome-award-offer.png', 'Hosting November 18 2026 | learner.aurora | Welcome Scholars offer with disclosed onboarding requirement and intention response still pending.'),
    'Hosting November 18 2026 | orivanta.manager | Selection step':
        ('hosted-welcome-award-frozen-policy.png', 'Hosting November 18 2026 | orivanta.programs | Welcome Scholars final-agreement policy is enabled and locked after its prepared application. No edit was saved.'),
    'Required-path local preview | account shown onboarding.preview.manager | same controls':
        ('hosted-welcome-award-provider-onboarding.png', 'Hosting November 18 2026 | orivanta.recipients | Welcome Scholars onboarding waits for learner.aurora to confirm intention. No completion is pre-recorded.'),
    'Required-path local preview | account shown onboarding.preview.recipient | applicant Requirements':
        ('hosted-welcome-award-requirement.png', 'Hosting November 18 2026 | learner.aurora | Welcome Scholars Requirements before intention confirmation. Provider updates appear after the tester confirms and publishes them.'),
}
for prefix, (name, caption) in captions.items():
    paragraphs = doc.paragraphs
    matches = [i for i, p in enumerate(paragraphs) if p.text.strip().startswith(prefix)]
    assert len(matches) == 1, (prefix, len(matches))
    index = matches[0]
    if paragraphs[index]._p.xpath('.//w:drawing'):
        picture = paragraphs[index]
        picture.clear()
        picture.add_run().add_picture(str(PHOTOS / name), width=Inches(6.2))
        picture.add_run().add_break()
        picture.add_run(caption)
    else:
        picture = paragraphs[index - 1]
        assert picture._p.xpath('.//w:drawing') and not picture.text.strip()
        for drawing in picture._p.xpath('.//w:drawing'):
            drawing.getparent().remove(drawing)
        picture.add_run().add_picture(str(PHOTOS / name), width=Inches(6.2))
        paragraphs[index].text = caption

table = doc.tables[0]
assert not any('Welcome Scholars' in cell.text for row in table.rows for cell in row.cells)
row = table.add_row()
for cell, text in zip(row.cells, [
    'learner.aurora',
    'Orivanta Welcome Scholars Award\nConfirm intention, follow provider onboarding, then collect and confirm the one-time school essentials kit. No response or release is pre-recorded.',
    'orivanta.recipients\norivanta.releases',
]):
    cell.text = text
AREA.mkdir(parents=True, exist_ok=True)
doc.save(AREA / 'guide-updated.docx')
(AREA / 'update.json').write_text(json.dumps({
    'source_sha256': SOURCE_HASH, 'updated_paragraphs': changes, 'replaced_figures': list(captions),
    'hosted_program': 'Orivanta Welcome Scholars Award', 'applicant': 'learner.aurora',
    'staged_file': str(AREA / 'guide-updated.docx'),
}, indent=2), encoding='utf-8')
print('Updated the existing onboarding walkthrough and account table in staging with hosted screens; no new public document.')
