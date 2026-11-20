from pathlib import Path

from docx import Document
from docx.oxml.ns import qn
from docx.shared import Inches, Pt, RGBColor


HERE = Path(__file__).resolve().parent
SHOTS = HERE.parent / 'storage/framework/cache/mirevalo-walkthrough-shots'
OUTPUT = HERE / 'FindScholar Hosted Demo Walkthrough.docx'

pages = [
    ('01 Applicant profile verification', 'admin.reviews', 'Review officer', 'profile-check',
     ['Open Applicant verification and choose Applicant Mavero (learner.mavero).',
      'Open each evidence file and record the review. Compare and record the grade under Academic results before completing the final profile decision.',
      'Sign in as learner.mavero to show the result. Profile approval lets the learner apply; it does not select them for a scholarship.']),
    ('02 Program preparation and publication', 'admin.reviews', 'Review officer', 'program-review',
     ['Before this screen, sign in as mirevalo.programs and open Programs > Drafts & changes > Mirevalo Digital Study Grant. Show the eligibility, application and benefits setup.',
      'Switch to admin.reviews. Open Program review > Mirevalo College Readiness Award. Open the program details modal and inspect the requirements, schedule and benefits.',
      'Choose Publish if you want to change the example. Publication and the application opening date are separate.']),
    ('03 Find a scholarship and apply', 'learner.ziavon', 'Applicant', 'find-scholarship',
     ['Open Scholarships > Find scholarships and search for Mirevalo Open Study Grant. Open its details.',
      'Show Eligibility and How to apply. Start the application, use the prepared files in My Documents, then review and confirm the submission.',
      'Go to Applications and show the next step. It waits for provider pre-screening. Use the separate ready record on the next page for a faster demo.']),
    ('04 Review application files and screening', 'mirevalo.reviewer', 'Application Reviewer', 'application-files',
     ['Open Application review > My reviews > Applicant Ziavon in Mirevalo Study Access Award.',
      'Open Application files. View each scholarship file before accepting it. Admin-verified profile evidence is only a reference.',
      'Open Result, enter the screening evaluation and review the pass or not-pass decision. For the formal handoff, use Applicant Cavelo in the same award and show where originals are checked.']),
    ('05 Sessions and stage evaluation', 'mirevalo.selection', 'Selection Officer', 'stage-results',
     ['Open Selection activities > Sessions. Applicant Yavik in Mirevalo Applied Learning Award already has attendance. Complete the session, then upload the sample session proof.',
      'Open Results to record. Use Applicant Ziavon in Applied Learning Award for the exam and Civic Scholars Program for the interview.',
      'Enter the scores in the evaluation modal and review the outcome. Attendance is not the stage result. Applicant Cavelo can also be assigned an exam; learner.novema has an exam to acknowledge.']),
    ('06 Compare candidates and record the final decision', 'mirevalo.decisions', 'Decision Officer', 'final-decisions',
     ['Open Final decisions > Pending decisions and filter to Mirevalo Future Scholars Award.',
      'Compare the candidates. Ziavon, Cavelo and Novema have completed evaluations; Yavik is missing the interview score. Missing scores are not treated as zero.',
      'Open a ready record and choose Selected or Waitlisted if you want to advance it. The provider decides; the system shows supporting scores.']),
    ('07 Confirm interest and handle onboarding', 'learner.ziavon', 'Applicant', 'offer',
     ['Open Applications > Mirevalo Orientation Support Grant > Decision. Review the offer and confirm intention to proceed.',
      'Switch to mirevalo.recipients, open the same applicant under Recipients, and publish the Onboarding instructions. The learner sees the final agreement instructions in the application record.',
      'Record completion after the provider handles the actual agreement. Confirmation of interest does not sign a contract, and a required agreement blocks first distribution until complete.']),
    ('08 Review an in person check in', 'mirevalo.monitoring', 'Monitoring Officer', 'check-in',
     ['Open Monitoring > Needs review and choose the check-in for Mirevalo Learning Continuity Grant. Review Applicant Novema after the original documents are presented.',
      'Applicant Yavik is ready for closure in the same round. For the applicant view, use learner.novema > Scholarship Support > Mirevalo Term Progress Award to show an upcoming handoff.',
      'The learner brings originals to the scheduled appointment. The provider records what was checked.']),
    ('09 In person benefit collection', 'mirevalo.releases', 'Benefit Release Officer', 'release-collection',
     ['Open Benefit releases > Record distribution. Find Applicant Cavelo in Mirevalo School Start Kit.',
      'Review the collection details. After simulated handover, record distribution with the sample distribution proof.',
      'To show the recipient step without changing this case, use learner.novema in School Start Kit. Open Benefits & Events, confirm receipt and attach the sample receipt proof. This award has no recurring check-in.']),
    ('10 E wallet payout verification', 'mirevalo.releases', 'Benefit Release Officer', 'release-wallet',
     ['Open the e-wallet release for Applicant Yavik in Mirevalo Term Progress Award. The fictional payout destination has already been submitted.',
      'Review the destination and approve it or request a correction. Learners submit and correct payout details through Scholarship Support > Benefits & Events.',
      'This does not move money. In a real workflow the provider sends it separately, then records it with proof. No real transfer is needed for the demonstration.']),
    ('11 Bank transfer with approved payout details', 'mirevalo.releases', 'Benefit Release Officer', 'release-bank',
     ['Open the bank-transfer release for Applicant Cavelo in Mirevalo Learning Continuity Grant. This example has a verified fictional destination and a due release.',
      'Under Distribution result, choose Sent to show the date and proof fields. Record transfer as sent is for transfers made outside the platform. Do not send money during the demo.',
      'The recipient separately confirms receipt or reports non-receipt. For an already released recurring allowance, use learner.ziavon in Mirevalo Term Progress Award.']),
    ('12 Manager workspaces and root admin', 'admin', 'Super admin', 'superadmin',
     ['Show mirevalo.manager for the provider workspaces, then admin.manager for admin oversight. Use mirevalo.team to demonstrate program assignments and first-login setup.',
      'Use mirevalo.support and admin.support to show separate case queues. Use mirevalo.billing and admin.billing to explain services without making a payment.',
      'Finish with admin. This is the root Super admin account. Use admin.reviews, not the Super admin dashboard, for day-to-day applicant and program reviews.']),
]

doc = Document()
doc.core_properties.title = 'FindScholar Hosted Demo Walkthrough'
section = doc.sections[0]
section.top_margin = section.bottom_margin = Inches(0.7)
section.left_margin = section.right_margin = Inches(0.73)
section.page_width, section.page_height = Inches(8.5), Inches(11)
normal = doc.styles['Normal']
normal.font.name, normal.font.size = 'Calibri', Pt(11)
normal.paragraph_format.space_after = Pt(6)
for name, size in [('Title', 23), ('Heading 1', 17), ('Heading 2', 13)]:
    style = doc.styles[name]
    style.font.name, style.font.size = 'Calibri', Pt(size)
    style.font.color.rgb = RGBColor(0, 0, 0)
    style.font.bold = True
title_properties = doc.styles['Title'].element.get_or_add_pPr()
border = title_properties.find(qn('w:pBdr'))
if border is not None:
    title_properties.remove(border)

doc.add_paragraph('FindScholar Hosted Demo Walkthrough', 'Title')
doc.add_paragraph('Rehearsal edition. Hosting has not been switched to this database. The screenshots show the working local rehearsal with the new fictional Mirevalo data. Use http://127.0.0.1:8011/login for this rehearsal only; the local password is password123. Do not use that password on a public installation.')
doc.add_heading('Before the demonstration', level=1)
doc.add_paragraph('Start the rehearsal with the command below if it is stopped. Switch accounts by signing out, or use separate browser profiles. Each pictured step names the account shown. Some examples start mid-process so you can demonstrate the next action without changing the dates.')
doc.add_paragraph(r'powershell -NoProfile -File .\scripts\start-walkthrough-demo.ps1 -HostingRehearsal')
doc.add_paragraph(r'Fictional upload samples: storage\app\private\mirevalo-hosted-walkthrough-20261120\walkthrough-uploads. Use these for session proof, distribution proof and receipt confirmation.')
doc.add_heading('Admin accounts', level=2)
doc.add_paragraph('admin is Super admin. admin.reviews is Review officer. admin.manager is Portal manager. The other specialist accounts are admin.accounts, admin.support, admin.billing, admin.finance and admin.records.')
doc.add_heading('Provider and applicant accounts', level=2)
doc.add_paragraph('mirevalo is the organization representative. The team includes mirevalo.programs, mirevalo.reviewer, mirevalo.selection, mirevalo.decisions, mirevalo.recipients, mirevalo.monitoring, mirevalo.releases, mirevalo.profile, mirevalo.team, mirevalo.support, mirevalo.billing and mirevalo.manager.')
doc.add_paragraph('learner.ziavon, learner.cavelo, learner.novema and learner.yavik have applications across multiple stages. learner.veolin has no submitted profile evidence, and learner.mavero is ready for admin profile review.')

for title, username, role, screenshot, instructions in pages:
    doc.add_page_break()
    doc.add_heading(title, level=1)
    caption = doc.add_paragraph()
    caption.add_run(f'Account shown: {username} ({role})').bold = True
    picture = doc.add_picture(str(SHOTS / f'{screenshot}.png'), width=Inches(7))
    if picture.height > Inches(6.45):
        picture.width = int(picture.width * Inches(6.45) / picture.height)
        picture.height = Inches(6.45)
    for index, instruction in enumerate(instructions, 1):
        doc.add_paragraph(f'{index}. {instruction}')

doc.save(OUTPUT)
