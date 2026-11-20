from pathlib import Path

from docx import Document
from docx.oxml.ns import qn
from docx.shared import Inches, Pt, RGBColor


HERE = Path(__file__).resolve().parent
doc = Document()
section = doc.sections[0]
section.page_width, section.page_height = Inches(8.5), Inches(11)
section.top_margin, section.bottom_margin = Inches(0.75), Inches(0.75)
section.left_margin, section.right_margin = Inches(0.85), Inches(0.85)
normal = doc.styles['Normal']
normal.font.name, normal.font.size = 'Calibri', Pt(11)
normal.paragraph_format.space_after = Pt(6)
for name, size in [('Title', 24), ('Heading 1', 16), ('Heading 2', 12)]:
    style = doc.styles[name]
    style.font.name, style.font.size = 'Calibri', Pt(size)
    style.font.color.rgb = RGBColor(0, 0, 0)
    style.font.bold = True
title_properties = doc.styles['Title'].element.get_or_add_pPr()
border = title_properties.find(qn('w:pBdr'))
if border is not None:
    title_properties.remove(border)


def p(text):
    doc.add_paragraph(text)


def step(number, heading, *instructions):
    doc.add_heading(f'{number} {heading}', level=2)
    for index, instruction in enumerate(instructions, 1):
        p(f'{index}. {instruction}')


def page(heading):
    doc.add_page_break()
    doc.add_heading(heading, level=1)


doc.add_paragraph('FindScholar Local Demo Guide', style='Title')
p('Use this walkthrough to show how a learner finds a scholarship, how staff make the decision, and what happens after selection. Each program below starts at the right point in the process, so you can switch accounts and show the next action without waiting for dates to pass.')
doc.add_heading('Open the demo', level=1)
p('Go to http://127.0.0.1:8010/login. All listed accounts use password123 in this local demo. Use the same browser with sign-out between accounts, or separate browser profiles. Do not use the hosted site for these accounts.')
p(r'If the demo server is stopped, open PowerShell in D:\XAMPP\htdocs\scholarship_web and run:')
p(r'powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\start-walkthrough-demo.ps1')
p('The walkthrough uses findscholar-demo-20261120.sqlite. Your normal local database and hosted database are separate. Saving an outcome changes the demo record, so stop before confirming if you want to reuse that starting point.')
step(1, 'Show the applicant profile',
     'Sign in as learner.velune and open Profile. Show Personal and contact and Supporting evidence. This profile has not submitted evidence and cannot apply yet.',
     'Show the Identity and birth record slot. The profile fields record the name and birthdate; the uploaded record supports those details.')
step(2, 'Verify an applicant profile',
     'Sign in as arv.demo.reviews. Open Applicant verification and select Applicant Weyaro. This profile already has submitted evidence.',
     'Open and review each required file. Under Academic results, compare the grade and scale against the uploaded record and record the verified result as needed.',
     'Use the final profile verification step to approve the profile. Sign in as learner.weyaro to show the verified status. Profile approval is not a scholarship decision.')
step(3, 'Show the provider and program checks',
     'As arvessia.programs, open Programs > Drafts & changes and edit Arvessia Digital Access Grant. Show the eligibility, application and benefits sections. Leave the draft unchanged if you want to reuse it.',
     'As arv.demo.reviews, use Provider verification to open the verified Arvessia Learning Trust. Show the registration, representative ID, authority letter and recorded outcomes.',
     'Open Program review and select Arvessia College Entry Award. View the program details in the modal and choose Publish if you want to demonstrate the approval. Its application opening date is still separate from its publication date.')

page('Application and Selection')
step(4, 'Find and submit a scholarship application',
     'Sign in as learner.nimori. Open Scholarships > Find scholarships and search Arvessia Learning Access Grant. This is the open intake; it closes December 4, 2026.',
     'Open View details. Show Eligibility and How to apply. Select Apply, work through the eligibility rules and use the prepared documents already saved in My Documents.',
     'Review the submission and confirm. Open Applications to show what the applicant sees while waiting for pre-screening.')
step(5, 'Review scholarship files and pass screening',
     'Sign in as arvessia.reviewer. In Application review > My reviews, open Applicant Nimori for Arvessia Study Bridge Scholarship. Use this already assigned example rather than the application just submitted in Step 4.',
     'Open Application files > Open and review. Open each required file before choosing Accepted, then save and confirm. Under Profile, evidence is for reference: the admin has already verified the profile.',
     'Open Result. Record the screening evaluation and choose Pass screening. Use learner.nimori > Applications to show the new status and instructions.')
step(6, 'Show formal application and scheduling',
     'Open Applicant Orvessa in Arvessia Study Bridge Scholarship as arvessia.selection. Formal application is the provider handoff to check originals. Show the instructions and record the result only after that check.',
     'In Selection activities > Unassigned applicants, find Applicant Orvessa for Arvessia Discovery Scholars Award and assign an eligible exam session. For an already published example, sign in as learner.savilo and open the schedule for that award to acknowledge the exam.')
step(7, 'Record session attendance proof and evaluation',
     'As arvessia.selection, open Sessions and find the exam for Applicant Temariq. Attendance is already Present. Complete the session first, then upload session-proof.pdf from the sample upload folder listed at the end.',
     'Go to Results to record. Use Applicant Nimori in Arvessia Discovery Scholars Award for the exam and in Arvessia Community Leaders Grant for the interview. Both examples have a completed session and uploaded proof.',
     'Open the stage evaluation, enter the scores on the displayed scales, and apply. Choose an outcome and confirm. Attendance alone does not pass the applicant.')

page('Decision and Continuing Support')
step(8, 'Compare applicants and record a decision',
     'Sign in as arvessia.decisions. Open Final decisions > Pending decisions and filter to Arvessia Future Pathways Scholarship.',
     'Open the decision-support breakdown. Nimori, Orvessa and Savilo have completed stage evaluations. Temariq is missing an interview evaluation. Explain that an incomplete evaluation is not a score of zero and the provider, not the system, chooses the result.',
     'Open a ready record and choose Selected or Waitlisted. Save the decision only when you are ready to change the example.')
step(9, 'Confirm intention and show final onboarding',
     'As learner.nimori, open the application for Arvessia First Term Partnership Grant. In Decision, review the offer and confirm intention to proceed. This is not signing the provider contract.',
     'As arvessia.recipients, open the matching record in Recipients and go to Onboarding. Publish the instructions. The applicant can read them in the application record under Requirements.',
     'Explain that signing can happen at orientation or first collection. Record completion as the provider after that happens. The first benefit cannot be distributed while the required final agreement is pending.')
step(10, 'Show an in person check in',
     'As learner.savilo, open Scholarship Support and select Arvessia Education Continuity Grant. Open Check-ins and show the upcoming appointment, original documents to bring and map.',
     'As arvessia.monitoring, go to Monitoring > Needs review. Open Applicant Savilo for Arvessia Continuing Studies Award. This is a different appointment that was already attended. Record the checks after reviewing the originals.',
     'Open Applicant Temariq for the same grant for a case ready to close. Explain that the applicant brings originals; a file upload is not a substitute for attendance.')
step(11, 'Schedule and confirm a benefit',
     'As arvessia.releases, use Benefit releases > Schedule release. Select Applicant Orvessa under Arvessia Continuing Studies Award to show the first allowance schedule. For an e-wallet example, open Applicant Temariq under Arvessia Education Continuity Grant and review the fictional payout details. Do not send money.',
     'As learner.nimori, open Scholarship Support > Benefits & Events for Arvessia Education Continuity Grant. Open the released allowance, confirm receipt and attach receipt-proof.pdf. The portal records delivery and receipt; it does not perform the transfer.')

page('One Time Award and Other Workspaces')
step(12, 'Record and finish a one time award',
     'As arvessia.releases, open Arvessia School Kit Award in Record distribution. Applicant Orvessa has a due appointment. Record the distribution with distribution-proof.pdf. Applicant Savilo already has a released benefit awaiting receipt confirmation.',
     'As learner.savilo, confirm receipt in Scholarship Support > Benefits & Events and upload receipt-proof.pdf. There is no recurring check-in for this award.',
     'As arvessia.recipients, open Applicant Temariq on the same award and review Support status. Benefits are already settled in that example, so you can record completion. For a recurring program, use Applicant Orvessa under Arvessia Education Continuity Grant to show the settlement view before completing or renewing support.')
step(13, 'Show team access support and services',
     'As arvessia.team, open Team access > Active accounts or Setup required. Show the roles and program assignments. arvessia.newstaff is intentionally waiting for its first password change.',
     'As arvessia.support, open Support cases > Needs response. As arv.demo.support, open the separate platform report. Explain who owns each request.',
     'As arvessia.billing, open Platform services. Compare with arv.demo.billing on the admin side. Show the existing requests and delivery examples without making a live payment.')
step(14, 'Show the manager and super admin last',
     'arvessia.manager opens the provider overview and can switch across provider workspaces. arvessia is the organization owner account, not the program coordinator.',
     'arv.demo.manager opens the admin Portal manager workspace for oversight. admin is the root Super admin account. Use arv.demo.reviews, not admin, for day-to-day profile verification and program publication.')
doc.add_heading('Sample upload folder', level=2)
p(r'From the project folder: storage\app\private\arvessia-walkthrough-demo-20261120\walkthrough-uploads. It contains the three fictional PDFs named in this guide.')

page('Demo Account Index')
p('Password for all listed local accounts: password123. These accounts are for rehearsal and must not be used on hosting.')
doc.add_heading('Applicants', level=2)
p('learner.nimori: new application, screening, exam and interview outcomes, onboarding and benefit receipt.')
p('learner.orvessa: formal application, exam assignment, first release scheduling and support settlement.')
p('learner.savilo: schedule acknowledgment, check-in review and one-time receipt confirmation.')
p('learner.temariq: session completion, missing interview evaluation, payout review and one-time completion.')
p('learner.velune: profile with evidence not yet submitted. learner.weyaro: profile awaiting admin verification.')
doc.add_heading('Provider team', level=2)
p('arvessia: organization representative. arvessia.profile: organization profile and verification documents.')
p('arvessia.programs: program creation and editing. arvessia.reviewer: application screening and document review.')
p('arvessia.selection: sessions, attendance, proof and stage outcomes. arvessia.decisions: final decisions and waitlist.')
p('arvessia.recipients: onboarding and recipient lifecycle. arvessia.monitoring: check-ins. arvessia.releases: distribution and payout details.')
p('arvessia.team: accounts and program assignments. arvessia.support: provider cases. arvessia.billing: service requests.')
p('arvessia.manager: manager workspace. arvessia.newstaff: practice first-login password setup.')
doc.add_heading('Admin team', level=2)
p('admin: Super admin. arv.demo.manager: Portal manager.')
p('arv.demo.reviews: applicant, provider and program reviews. arv.demo.accounts: account management.')
p('arv.demo.support: platform support reports. arv.demo.billing: service and billing workflow.')
p('arv.demo.finance: finance views. arv.demo.records: audit records and exports.')

doc.save(HERE / 'FindScholar Local Demo Guide.docx')
