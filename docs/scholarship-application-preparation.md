# Scholarship Application Preparation Contract

## Scope

Part 1 adds `detail.preparation` to every applicant scholarship-detail response.
The existing detail version (3), decision, navigation, and section content remain
compatible. The preparation contract has its own version (1). No database tables,
persisted checklist progress, application stages, or submission permissions change.

`ApplicantScholarshipPreparationService` presents the existing profile-readiness,
eligibility, document, and detail-decision results. It does not decide eligibility
again or turn a preference, manual grading review, or missing file into a new
start blocker. Required verification-evidence types are taken from the existing
profile-evidence model so partial uploads are not mislabeled as only awaiting
review. This display detail does not add a new verification gate.

## Fields For The UI

- `mode`: `live` or `preview`.
- `state`: the existing detail decision state, including unavailable intakes and
  existing applications.
- `can_start_application`: mirrors `decision.can_apply` for applicants; always
  false in previews. This is not permission to submit a completed application.
- `has_applied`: whether the applicant already has a submitted application.
- `steps`: four ordered rows (`profile`, `eligibility`, `requirements`, `submit`).
  Each supplies a readable title, status label, tone, explanation, action, compact
  details, and its actual `blocks_start`/`required_before` semantics.
- `next_step`: row to highlight, or null when the intake is unavailable.
- `next_action`: the recommended action. Profile and eligibility gates come
  first; otherwise missing files point to application requirements, followed by
  email verification or the existing application form. Existing applications
  always point to their application record. Closed, full, and upcoming intakes
  keep their original save/view-saved action.
- `submission`: flags email verification and required question/consent work,
  with an explicit reminder that the server rechecks all submission requirements.

The UI must not render machine `status` keys directly, calculate eligibility from
a completion percentage, or mark review/consent/answers complete just because the
applicant opened a tab. No `can_submit_application` field is provided: the actual
submission endpoint validates the completed form and current intake state.

## Saved Files And Questions

Initial file requirements come from the existing eligibility service. A saved
file counts as reusable only when it belongs to this account, matches the
requirement name used by submission, and still exists on the local storage disk.
Duplicate required names count once, as they do during submission. The existing
detail requirements section uses this same availability snapshot.

`Files saved` is not `Attached`, `Accepted`, or `Approved`. Profile-verification
evidence is not silently treated as a reusable application document. Optional and
post-qualification files do not become initial required files. Profile-review
programs require no initial files, but their valid required questions still need
answers in the form. Questions are counted using the submission handler's
existing ID/prompt/required conventions; no answer content is included here.

## Provider Previews

The guidance is replaced with synthetic preview data, never the provider's live
profile or files. General previews show applicant-specific states rather than
fake progress; eligible/ineligible examples are illustrative. Profile, file
management, and application actions are disabled. Links to eligibility and
requirements stay within the authorized provider preview. These responses create
no applications or checklist records.

## Overview Guide (Part 2)

`ScholarshipApplicationGuide.vue` replaces the old readiness brief and appears
before the program overview, outside the scholarship header. It renders one
full-width numbered list, with the server's next step highlighted and aligned
statuses/actions on desktop. Mobile keeps the same step order in stacked rows.
It does not calculate eligibility, persist progress, or submit an application.

Missing profile/evidence lists and submission-check explanations start collapsed.
File and question counts remain compact, and saved files are not labeled as
approved. Existing applicants see a direct record handoff instead of a new
preparation checklist. Unavailable intakes keep the save/view-saved action;
bookmark changes update both the header and the guide's action.

Embedded provider previews forward section navigation within the preview modal.
Only read-only preview eligibility/requirements links remain usable; applicant
profile, document management, and start actions stay disabled even if a preview
receives a stale live action. Missing contract data displays a safe explanation
rather than inventing application permission.

## Requirements And Handoff (Part 3)

`ScholarshipApplicationRequirements.vue` separates initial required files,
optional supporting files, and documents requested after qualification. Required
files show aligned `Saved file`/`Upload needed` labels, never an approval status.
File guidance, provider question previews, and secondary file groups start
collapsed. Questions are answered only in the existing application form.
Profile-review programs do not gain mandatory initial files, but their questions
still apply. The original decision controls the start/save/profile action.

Profile/document links carry `preparing_scholarship` and a known `return_section`.
Their data endpoints resolve these into a compact `preparation_context` only for
an authenticated applicant and a publicly visible program. Unrelated visits and
invalid, private, or non-public programs get null context. A closed public intake
can still be reviewed, but the context cannot bypass availability checks. Return
URLs are built from known routes, never accepted from arbitrary query strings.

The return banner keeps the program choice, not a saved application draft. Its
manual link reloads scholarship readiness. Existing profile unsaved-change
protection remains intact; the application form now warns before navigation with
unsent answers, notes, or application consent and blocks departure during an
upload/submission. Uploaded reusable files remain saved independently. No draft
table, automatic submission, reserved place, or client-side answer storage is
introduced.

The process section labels program dates as general schedules, not assigned
appointments. Counts of published active stage sessions distinguish multiple
sessions without disclosing participant rosters, private meeting links, or session
instructions. Draft/cancelled/completed sessions do not become upcoming public
dates. Actual assignments remain in the existing application record/workflow.

## Final Verification (Part 4)

Verified locally on 2026-10-29. All four implementation parts are complete;
the runtime update was deployed to hosting on the same date.

- Full PHP suite: 711 tests passed with 7,044 assertions.
- Frontend suite: 314 tests passed; production Vite build passed.
- Browser checks: the six affected applicant pages at 390, 1024, and 1440 pixels,
  plus read-only provider previews and embedded modal navigation.
- Fourteen browser-only response fixtures at desktop and mobile widths cover
  incomplete profiles, missing evidence, pending review, requested changes,
  required mismatches, preferences, manual review, missing/no initial files,
  email verification, upcoming/closed/full intakes, and existing applications.
  Bookmark feedback is mocked; these fixtures do not change demo records.
- The actual local preparation journey verifies document/profile return links,
  the selected scholarship form, and cancelling unsent-answer navigation without
  losing the typed answer or submitting an application.

Final review also corrected two presentation inconsistencies: eligibility uses
the actual profile-step explanation rather than assuming evidence is pending,
and preview eligibility rows now use published rules plus explicitly illustrative
values, not the provider account's student information. Programs with no Required
restrictions do not receive fabricated Required-rule failures in previews.

Screenshots include all six affected applicant pages on desktop and mobile,
provider previews, expanded requirement groups, and the unsent-answer warning.

## Hosting Verification

Deployed to `https://findscholarship.online` on 2026-10-29 using a runtime-only
overlay. The private deployment directory is
`/home/u908761863/deployments/scholarship-guidance-20261029-043746`; it contains
the previous runtime, a compressed database backup, and preservation checks.
No migrations, seeders, password changes, or database synchronization were run.

- All strict platform readiness checks passed and maintenance mode was removed.
- All 53 business tables matched the pre-deployment baseline immediately after
  deployment; uploaded-file metadata fingerprints were unchanged.
- The initial deployment manifest matched the local production build:
  `b5e7dbfe7087c50631235d2bddf37289da4df9ddba6f01fdb4ffa395c43e519a`.
- The homepage, login page, and the new main and scholarship-detail assets
  returned HTTP 200 over HTTPS.
- All six affected applicant pages passed live browser checks at 1440 and 390
  pixels; guide and eligibility alignment/progressive-disclosure audits passed.
- The actual document/profile return journey, selected scholarship form, and
  unsent-answer cancellation passed without submitting or saving profile edits.
- The embedded provider preview passed general/eligible/ineligible scenario and
  requirement-group checks with applicant-only actions disabled.

The browser mutation guard excludes only Hostinger's exact POST
`/hcdn-cgi/jschallenge-validate` security request in addition to the existing
login/application-start analytics exceptions. Business writes remain prohibited
during the read-only handoff test.

## Application Instructions Correction

The original `How to apply` heading described a readiness checklist rather than
the actual actions needed to send an application. Corrected on 2026-10-29:

- Show explicit instructions for Start pre-screening, Program and Eligibility,
  Requirements, and Confirm / Submit application using the actual form labels.
- Provide an authorized start link even when files still need preparation;
  closed/full/upcoming intakes and blocked or preview actions remain disabled.
- Explain upload consent, saved-file checks, required provider answers, final
  application consent, and tracking the submitted record in Applications.
- Adapt instructions when no initial files or questions are required.
- Move readiness checks into a secondary Before you start disclosure. Required
  profile, eligibility, or email checks automatically expand it; previews do not.
- Explain that later formal applications, exams, and interviews require the
  provider to advance the applicant and issue instructions.

No backend decision rules or database schema were changed. All 318 frontend tests,
the production build, desktop/mobile guide checks, 14 isolated browser state
fixtures, the real start/return/unsaved-answer journey, and provider preview checks
passed locally. Hosted desktop/mobile and real navigation checks also passed.

The focused component/build update was deployed with rollback backups in
`/home/u908761863/deployments/scholarship-guidance-20261029-045113`.
Strict readiness checks passed; all 53 business tables and upload metadata
fingerprints were unchanged by deployment. The corrected production manifest is
`eb3a3d68ab1bf1b1f81bfb1cebc210d0fdf174a0bc53dd74d6c76a35ed45cc13`.
