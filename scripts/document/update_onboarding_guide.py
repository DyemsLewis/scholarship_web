"""Update the existing expanded guide without replacing its other processes."""
import hashlib
import json
from pathlib import Path

from docx import Document
from docx.shared import Inches
from docx.text.paragraph import Paragraph

ROOT = Path(__file__).resolve().parents[2]
AREA = ROOT / '.tmp-docx/recipient-onboarding-20261118'
GUIDE = ROOT / 'deliverables/Platform_Process_Guide_With_Accounts_Expanded.docx'
PHOTOS = Path('C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958')
inventory = json.loads((AREA / 'inventory.json').read_text(encoding='utf-8'))
assert hashlib.sha256(GUIDE.read_bytes()).hexdigest() == inventory['summary']['source_sha256'], 'The source changed; inspect before updating.'
hosted = json.loads((ROOT / 'storage/deployment/recipient-onboarding-part5-hosted-browser-20261118.json').read_text())
assert hosted['passed'] and not hosted['businessActionsSubmitted']
doc = Document(GUIDE)
blocks = list(doc.element.body)
updates = {
    4: 'Updated November 18 2026 Philippine time for final provider agreement and onboarding. The initial hosted demonstration reset used Orivanta Learning Foundation and the accounts listed here. Additional tester-created records may now exist and have not been removed. Hosting and local databases are not identical. Existing hosted offers remain unchanged; the new required-onboarding path applies only when the requirement was disclosed in a new intake before applications.',
    5: 'The onboarding setting screenshot was captured from hosting on November 18. Required-path screenshots are labelled isolated local previews because existing hosted offers have no recorded onboarding requirement. Earlier screenshots remain where the process is unchanged. Capture did not save a hosted decision, response, receipt or payment. Recheck the named program and its current state before repeating a step.',
    15: '7. Offer response: learner.aurora opens Continuing Learners Grant and chooses Review and respond. Explain Confirm intention versus the separate final provider agreement. This legacy offer does not acquire a new agreement requirement. For a newly configured required-onboarding intake, continue with the provider and applicant steps in Section 19.',
    41: '19. Recipient support and provider onboarding',
    416: '2. Read the disclosed benefits, support period and provider expectations. A new intake with required onboarding also shows Final provider agreement required and its instructions above the offer tabs, before confirming intention. The existing Continuing Learners offer is a legacy example without that added requirement.',
    418: '4. Review the confirmation before saving. This records intention only. If the frozen offer requires onboarding, Scholarship Support then links to Complete provider onboarding and Requirements > Final agreement. It does not record signing, distribute a benefit, or confirm receipt.',
    421: '19 Recipient support and provider onboarding',
    424: 'Existing recipient example: use orivanta.recipients to inspect Applicant Brio in Orivanta Term Assistance Grant. This legacy offer has confirmed intention and a closed original-document check. It remains ready for the existing renewal or completion workflow. Its Onboarding tab is read-only because no final-agreement requirement was recorded with the offer. Do not add an obligation retroactively or reset an accepted response.',
    426: 'Applicant: confirms intention and follows disclosed instructions. Provider: orivanta.recipients manages final-agreement onboarding and support outcomes; orivanta.manager can use the same actions. Admin: handles independent cases or oversight, not contract signing or applicant receipt confirmation.',
    434: '1. Open Orivanta Term Assistance Grant, then Requirements and the upcoming appointment. Read its date, time, exact venue, map pin and the original records requested.',
    517: 'The benefit release officer can schedule an otherwise eligible recipient after intention confirmation without adding recurring monitoring just to distribute a benefit. A required final agreement may still be pending when arranging first collection or orientation. Only actual distribution or a transfer recorded as sent is held until provider-confirmed completion.',
    522: 'Use learner.brio in Continuing Learners Grant for a first release, or learner.aurora in School Essentials Award for one-time planning. These are legacy offers and do not gain a new onboarding requirement. For a newly configured required-onboarding intake, Can schedule means the appointment can be arranged now while final-agreement completion is still needed before distribution.',
    523: '1. Open the release scheduling form, choose the program and recipients, and inspect their readiness. Pending final agreement alone does not disable selection. Existing monitoring, inactive-support and entitlement holds still apply. Match the benefit description and amount to what will actually be delivered.',
    524: '2. Enter the actual collection date, method, venue and instructions. If signing will be handled during this visit, include where to report and what to bring. Do not create a signing-only appointment just to satisfy the platform. Attendance does not confirm agreement completion or receipt.',
    537: '2. Select the recipient and inspect readiness. A required Final agreement pending hold identifies the recipient officer or manager. Prepared, Missed and Withheld remain available; Released / received and transfer Sent are blocked. Only authorized recipient staff see Open onboarding.',
    538: '3. After the recipient officer records actual agreement completion, reload the release record and record actual delivery with its own confirmation and proof. Completion does not bypass the scheduled time, original-document checks, monitoring holds or payout approval. A bank or e-wallet transfer date cannot precede agreement completion or payout approval. The portal records the transfer evidence; it does not send money.',
    827: 'Selected applicants confirm intention, not a signed final contract. For a new required-onboarding intake, show the disclosed requirement, provider instructions, applicant Requirements card and provider-confirmed completion before actual distribution. Existing offers are not retroactively opted in. Appointments, original-document checks and check-in closure remain separate.',
}
for index, text in updates.items():
    assert not blocks[index].xpath('.//w:drawing'), index
    Paragraph(blocks[index], doc).text = text

anchor = Paragraph(blocks[427], doc)
def add(text='', style=None):
    p = anchor.insert_paragraph_before(text, style)
    return p

def figure(relative, caption):
    path = PHOTOS / relative
    assert path.is_file(), path
    p = add()
    p.add_run().add_picture(str(path), width=Inches(6.2))
    p.paragraph_format.keep_with_next = True
    add(caption, 'Caption')

heading = add('Final provider agreement and onboarding', 'Heading2')
heading.paragraph_format.page_break_before = True
add('Use orivanta.programs for configuration, orivanta.recipients for provider instructions and completion, orivanta.releases for collection and distribution, and the selected applicant account for intention confirmation and receipt. orivanta.manager can demonstrate the combined provider workflow. Admin and release-only roles cannot confirm final-agreement completion.')
add('Existing hosted offers are legacy records. They correctly show no recorded final-agreement requirement and are not silently enrolled in this process. To demonstrate the required path, create a new intake with the policy below before it receives applications. Continue with an applicant through the normal verification, application and selection stages. Do not invent signing records or modify an old offer to bypass the disclosure rule.')

add('Configure the requirement before applications', 'Heading3')
add('Signed in account: orivanta.programs or orivanta.manager')
add('Open Programs > Create program or an unused draft > Selection. Enable Final provider agreement and enter how recipients complete it, such as signing during orientation or their first collection visit. Save and follow the normal publication review. The material requirement is locked after the first application. Actual contract files stay with the provider; this setting is not a contract upload or electronic-signature service.')
figure('recipient-onboarding-part5-hosted-20261118/hosted-program-final-agreement-setting.png', 'Hosting November 18 2026 | orivanta.manager | Selection step with the final agreement setting. No draft changes were saved during capture.')

add('Confirm intention and publish practical instructions', 'Heading3')
add('Signed in accounts: the selected applicant, then orivanta.recipients')
add('1. The applicant opens Applications > the selected record > Decision > Review and respond. Read Final provider agreement required and the disclosed instructions, then confirm intention if they wish to proceed. Selection stays selected. This creates Pending onboarding, not Completed signing.')
add('2. The recipient officer opens Recipients > that same applicant and program > Onboarding. Use Publish instructions or Edit instructions to explain where to report, what to bring and how to request another arrangement. Review the confirmation before publishing. Instructions notify the applicant but do not replace the requirement originally disclosed with the offer.')
figure('recipient-onboarding-part2-local-20261118/provider-onboarding-record.png', 'Required-path local preview | account shown onboarding.preview.manager | same controls as orivanta.recipients on a newly configured intake. This temporary preview login is not a hosted account.')

add('Read the applicant requirement and arrange the existing visit', 'Heading3')
add('Signed in accounts: the applicant, then orivanta.releases')
add('3. The applicant opens Scholarship Support > the relevant program > Requirements > Final agreement. Overview links directly to it when onboarding is the next action. Read the provider update and original offer instructions, or use Email provider or Call provider where recorded. Applicants cannot self-complete signing or upload a contract. One-time awards keep Requirements when onboarding is required, including after completion.')
add('4. The release officer may schedule the first collection while onboarding is pending if all other readiness checks pass. The recipient shows Can schedule. Include signing arrangements in the existing visit instructions if appropriate; a separate appointment is not mandatory. Published dates stay in Benefits & Events, and the agreement requirement stays in Requirements.')
figure('recipient-onboarding-part3-local-20261118/applicant-final-agreement-requirement.png', 'Required-path local preview | account shown onboarding.preview.recipient | applicant Requirements with original and published instructions. Use the actual applicant account for the same new intake on hosting.')

add('Record agreement completion before actual distribution', 'Heading3')
add('Signed in accounts: orivanta.recipients, then orivanta.releases')
add('5. After checking the provider-held agreement records, the recipient officer opens Onboarding > Record completion. Enter the actual completion date, optionally add a private reference, explicitly confirm and review the confirmation dialog. The date cannot be future-dated or earlier than intention confirmation. The applicant sees completion and its date; private provider notes and audit history stay restricted. Attendance alone never completes the requirement.')
add('6. The release officer reloads the distribution record. Pending onboarding blocks Released / received and transfer Sent, including direct requests; preparation and scheduling remain available. After completion, the usual delivery checks and confirmation still apply. Record only actual delivery or an external transfer already sent, with its required evidence. No money is sent by this platform.')
figure('recipient-onboarding-part4-local-20261118/provider-distribution-onboarding-hold.png', 'Required-path local preview | account shown onboarding.preview.manager | distribution hold before completion. Release-only staff see the responsible role without an inaccessible onboarding action.')
add('7. The applicant checks the actual benefit, uploads their receipt proof and confirms receipt or reports a problem. Provider completion and provider distribution evidence cannot make this response on their behalf. Existing receipt confirmations and issue follow-ups remain available even for older distributions.')
add('Expected result: intention confirmation, final-agreement completion, distribution and applicant receipt are separate records. The requirement is tied to the frozen offer version; a completion from an older offer cannot unlock a new one. Pending onboarding does not automatically cancel a scholarship. Existing renewal, closeout, independent case review and appeal workflows remain separate.')

doc.save(AREA / 'guide-updated.docx')
(AREA / 'update.json').write_text(json.dumps({'source_sha256': inventory['summary']['source_sha256'], 'updated_paragraphs': list(updates), 'new_section': 'Final provider agreement and onboarding', 'staged_file': str(AREA / 'guide-updated.docx')}, indent=2), encoding='utf-8')
print('Existing guide updated in staging; other sections and original figures retained. Canonical files await rendering checks.')
