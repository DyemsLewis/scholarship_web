# Recipient agreement accountability

## Delivery status

This is a six-part implementation. Parts 1 through 5 are available locally and
on hosting after the Part 6 rollout on November 3, 2026 (Asia/Manila).
Notices/responses alone do not make a finding. A separate admin review may record
an evidence-backed outcome, but support decisions and benefit receipt remain separate.

1. Case foundation: complete locally; verification results are recorded below.
2. Applicant agreement concerns: complete locally; provider notices, applicant responses, and browser checks are verified.
3. Missing/incomplete benefits: complete locally; applicant reports, provider correction/proof responses, and applicant-confirmed delivery resolution.
4. Review, outcomes, and appeals: complete locally; independent admin review, reasoned outcomes, appeals, and support-decision guards.
5. Disclosure/privacy safeguards: complete locally; internal assessments, scoped access history, and retention controls. External disclosure itself remains disabled pending independent policy/legal review and a separate implementation.
6. End-to-end testing, demonstration, and hosted rollout: implemented and verified. The hPanel cron invocation remains an external operational check; schedule definitions and command dry-runs pass.

## Scope and invariants

- Two case types: `agreement_concern` and `benefit_delivery`.
- Workflow status, assessed finding, support outcome, and disclosure status are separate fields.
- Agreement concerns begin as private drafts; delivery reports are explicitly submitted. Both start with `finding=not_assessed` and `disclosure_status=not_shared`.
- Opening, editing, adding evidence to, or cancelling a draft does not change the
  application, account, profile verification, DSS, selection, agreement, slots,
  support decisions, monitoring, benefits, release records, or other scholarships.
- No automatic penalty, account suspension, blacklist, eligibility score, or cross-provider warning exists.
- No old decisions or monitoring records are converted into adverse findings.
- Voluntary withdrawal remains a separate existing workflow, not a misconduct case.

Existing support-decision and receipt-resolution endpoints are unchanged in Part 1.
Mandatory notice/evidence/response enforcement and recipient confirmation of a
delivery resolution are later integration work, not safeguards claimed complete here.

## Storage

`2026_11_02_000001_create_recipient_agreement_cases` adds four tables without
altering or backfilling an existing table:

- `recipient_agreement_cases`: award link, author, type, versioned draft,
  accepted agreement/source snapshots, notice/response deadlines, reserved finding,
  outcome, support-decision link, and disclosure review fields.
- `recipient_agreement_case_responses`: append-only statements, author snapshots,
  notice revision, and case revision. Submission is reserved for later parts.
- `recipient_agreement_case_evidence`: private file metadata and content hash.
- `recipient_agreement_case_events`: one append-only event per case revision,
  actor identity snapshot, action, status change, and relevant draft content.

Normal Eloquent updates/deletes of evidence, responses, and events are rejected.
This is application-level append-only history, not protection against a database
administrator or direct SQL. Existing application deletion cascades for retention;
deleting an actor nulls their foreign key but preserves event/response author snapshots.
Physical evidence retention/cleanup policy will be addressed before public upload routes ship.

## Internal service boundary

In Part 1, `RecipientAgreementCaseService` introduced only:

- `createDraft`: accepted-agreement validation, authoritative source snapshots,
  permission/program checks, and idempotent request keys.
- `reviseDraft`: subject/details only, with an expected revision and preserved edit history.
- `cancelDraft`: closes an unissued draft without finding fault or ending support.
- `attachDraftEvidence`: author-only draft evidence, PDF/JPG/PNG, up to 5 MB each,
  at most five files, randomized names under the private local disk, SHA-256 metadata.
- `evidenceResponse`: fresh case authorization and private/no-store file responses.

Part 1 introduced **no routes, UI pages, notifications, scheduled jobs, final-decision
commands, or generic arbitrary status-transition endpoints**. The
catalog documents allowed future transitions; implementing them requires the
notice, evidence, response, and review guards in the relevant later part.

Mutations lock the application before the case, use optimistic revisions, and write
the event within the same transaction. A failed audit write rolls back the draft;
a failed evidence transaction also removes the newly stored file. Every upload
attempt uses a fresh UUID filename, including retries of the same upload object,
so failure cleanup cannot remove a previous valid attachment. Repeating the
same create request returns its original case; reusing its key for different input
is a conflict, not another allegation. Editing an existing draft never changes its
accepted source; changing the underlying obligation or benefit requires a different draft.

## Accepted obligations and benefit sources

Agreement concerns require an active, selected, accepted award. The term must be
a nonempty string in the saved accepted snapshot, not a newly edited program rule.
Recorded accepted/current snapshot version mismatches require record review.
The legacy saved agreement version is accepted when the separate response version
was not recorded. Consequence/exception/contact clauses are not accepted as the
reported obligation. Draft concerns do not yet send notice or assert a breach.

Benefit drafts require the recipient's own accepted award and an entitlement or
published release record belonging to that same award. Private, unpublished release
details cannot be retrieved by supplying their identifier. Selecting both requires a real release
allocation connecting them. They may reference a benefit before it is scheduled
or recorded as released, unlike the older receipt-response endpoint. The original
release/benefit snapshot is retained when the source later changes or is removed.
An ended award or suspended provider does not prevent the recipient documenting
an undelivered prior benefit. Public reporting eligibility, timeliness, and issue
resolution are Part 3, not implied by this draft-storage capability.

## Access and disclosure

- Only an active approved provider's assigned `manage_recipients` operator can
  create an agreement-concern draft.
- Only the applicant owning the award can create a benefit-delivery draft.
- Unissued drafts, including cancelled ones, are visible only to their author;
  not the other party, other organization staff, or an administrator.
- The scoped reader is ready for later issued notices: award owner applicants,
  assigned provider operators with the permission for that case type, and active
  admin users with `manage_reviews`. Admin support-only access is not implied.
- Permissions, current organization approval/account status, application ownership,
  and assigned program access are rechecked rather than trusting caller-supplied models.
- Case evidence has the same scope as its case; no public storage URL is created.
- `canShareWithOtherProviders()` is always false, even if database fields say
  `confirmed` and `approved`. Another provider's applications never grant access
  to cases from the originating organization.

Cross-provider sharing needs a separately reviewed lawful basis, limited purpose,
notices, relevance rules, appeal/correction propagation, access logging, and retention
policy before activation. An administrator's finding or a consent checkbox alone
does not establish lawful sharing. Reference: [NPC Data Privacy Act](https://privacy.gov.ph/data-privacy-act/).

## Deadlines

Drafts do not start response clocks. `responseDeadlinePassed()` requires an issued
notice, the awaiting-response state, a due timestamp, and no recorded response.
It is a read-only signal. It does not mutate status, establish fault, resolve a
delivery dispute, publish information, or trigger a support decision. Late responses
and extensions will be handled explicitly rather than treating silence as guilt.

## Verification

Tests use an isolated in-memory SQLite database, never the local/hosted demo data.
Focused tests cover snapshots, forged fields, unaccepted agreements, roles/programs,
private drafts, retries/stale actions, immutable history, private evidence/cleanup,
deadline neutrality, unchanged benefits/accounts/other awards, and hard-disabled sharing.
MySQL DDL is compiled without a network connection to verify identifier lengths
and forward/reverse schema definitions.

Verified on November 2, 2026:

- Full backend regression: 916 tests passed, 9,175 assertions.
- Final focused foundation regression, including the additional upload-object retry
  safeguard: 43 tests passed, 880 assertions.
- Scoped PHP formatting and whitespace checks passed.
- The additive local migration completed after a private SQLite backup. All 62
  existing table fingerprints and prior migration history were preserved; the
  four new tables were created empty. No real cases or adverse findings were seeded.
- No frontend changes or new public routes were introduced, so no frontend build
  or visual redesign is included in this part.

Private local verification artifacts (not shipped to hosting):

- `storage/deployment/agreement-case-part1-full-backend-20261102.log`
- `storage/deployment/agreement-case-part1-focused-final-20261102.log`
- `storage/deployment/agreement-case-part1-local-preservation-20261102.log`
- `storage/deployment/agreement-case-part1-baseline-20261102.json`
- `storage/deployment/local-before-agreement-case-part1-20261102.sqlite`

Hosting is not updated by Parts 1 or 2; rollout belongs to Part 6.

## Part 2: notices and applicant responses

### Entry points

- Provider: Recipients > Active recipients > open a recipient > Agreement concerns > New concern.
- Provider queue: Recipients > Agreement concerns.
- Applicant: Applications > the application's Decision section > Agreement concerns.
- Issuing a notice adds a portal notification and the application detail next-action
  guide links directly to the concern panel. Private drafts never affect that guide.
- A historical issued concern remains readable/respondable if another application
  workflow later changes the current agreement fields. The concern retains the
  originally accepted version and quote; no unrelated workflow is rewritten.

### Provider interaction

One bounded modal has three short steps: accepted term/facts, supporting evidence,
then notice review. Continuing from the first step saves an author-only draft;
closing does not send it. Drafts can be resumed, revised, or explicitly cancelled.
Evidence previews open in a modal. Removing an attachment appends an exclusion
record rather than editing its original metadata; the excluded file cannot be
downloaded through the concern endpoints and is never included in the notice.
Non-authors see neither draft events nor earlier draft explanations.

Issuing requires at least one existing, hash-matching supporting file, an explicit
confirmation, and a response window of 3 to 30 calendar days (default UI choice: 7).
This is a product response window, not a claim about a statutory deadline. It is
calculated from the actual issuance timestamp. The accepted source and current
active support are rechecked under the application-first lock. A unique nullable
open-obligation key prevents simultaneous issued cases for the same award/term;
later closure integration must release this key explicitly. Sending creates the
notice event and portal notification in the same transaction, not a finding.

### Applicant interaction

Applicants first see the notice, accepted quote, provider evidence, and due date.
They can provide an explanation, request a correction, or request an exception/more
time, with up to three optional PDF/JPG/PNG files of 5 MB each. They are not required
to prove a negative. A correction request records a statement; it does not silently
edit the profile, evidence, or agreement.

Submission requires an explicit confirmation, a current revision, and an idempotent
request key. The response and optional files are stored atomically and append-only,
the case moves to `under_review` (displayed as Response received), and authorized,
assigned recipient officers receive a notification. Identical retries do not add
another response, proof, event, or notification. Reusing a key for different content
or posting another response to the same notice is a conflict. Late replies are
accepted while the case awaits a response, including after the requested date.
Passing a deadline does not establish fault or change scholarship support.

### Safety boundaries and maintenance

All new endpoints retain authentication, existing opaque URL handling, security
headers, CSRF protection, mutation rate limits, fresh role checks, organization/
program assignment checks, and file ownership. Applicant endpoints do not accept
provider sessions, and provider endpoints require recipient-management permission
and an approved organization. There are no final-decision, penalty, global warning,
cross-provider disclosure, or new benefit-delivery report endpoints in this part.
The older support-decision/receipt-resolution endpoints remain unchanged until
their planned Part 4 integration; these new notices must not be represented as
already enforcing a mandatory termination process.

Drafts/errors survive retries; stale actions require an authoritative reload and
fresh confirmation. The modal uses the existing viewport scroll lock, keyboard
trap, explicit discard prompt, restored focus after async actions, and pinned footer.
Applicant layouts are checked at desktop and phone sizes; provider queues retain
the established desktop table workspace and real private photo/neutral fallback.

`2026_11_02_000002_add_agreement_concern_notice_controls` is additive. It adds
response idempotency/type fields, the open-obligation uniqueness key, and the
append-only evidence-exclusion table, without migrating historical decisions.
A private local SQLite backup and fingerprint audit preserved all original fields
in 66 existing tables. No real notices, applicant responses, or adverse findings
were seeded by implementation or browser testing.

`agreement-cases:prune-files` (daily at 03:30, alongside existing maintenance)
removes only unreferenced files under this feature's private UUID evidence paths
after a 24-hour safety window. `--dry-run` makes no changes. Referenced files,
including excluded draft evidence, are retained under the case retention boundary.
Parent-record cascades can therefore leave temporary private orphans, but do not
cause permanent untracked files or delete unrelated document categories. No
evidence lifecycle is tied to the response deadline.

### Part 2 verification

Verified locally on November 2, 2026:

- Full backend regression: 935 tests passed, 9,429 assertions.
- Focused foundation/concern workflow: 61 tests passed, 1,128 assertions.
- Frontend regression: 692 tests passed; production Vite build passed.
- MySQL migration/rollback statements compile without contacting hosting; actual
  hosted MySQL concurrency/runtime checks remain part of the rollout work.
- Scoped PHP style, changed PHP syntax, and whitespace checks passed.
- Authenticated local browser checks passed for the existing recipient officer
  and accepted-award applicant, using separate browser contexts. Verified provider
  desktop sizes 1440x1000 and 1280x720, and applicant phone size 390x844.
- Verified three-step reporting, evidence listing, mandatory confirmation, stale
  reload/reconfirmation, applicant response choices, error/draft preservation,
  focus after validation/success, fixed footer, no horizontal modal overflow,
  unchanged background geometry, and private applicant-photo rendering.
- All six browser mutation attempts were intercepted. A post-browser database
  audit confirms no real cases, responses, proof rows, exclusions, or case events
  were added. Normal sign-in/activity and existing read-side DSS caches are not
  described as business mutations by these checks.
- No real email delivery was tested; portal notification transactions and recipient
  scoping were exercised in isolated backend tests. No legal breach was adjudicated.
- The temporary local browser/profile and HTTP test server were stopped/cleaned up.

Private verification artifacts:

- `storage/deployment/agreement-concern-part2-full-backend-20261102.log`
- `storage/deployment/agreement-concern-part2-focused-final-20261102.log`
- `storage/deployment/agreement-concern-part2-frontend-final-20261102.log`
- `storage/deployment/agreement-concern-part2-build-final-20261102.log`
- `storage/deployment/agreement-concern-part2-browser-20261102.json`
- `storage/deployment/agreement-concern-part2-local-preservation-20261102.log`
- `storage/deployment/agreement-concern-part2-baseline-20261102.json`
- `storage/deployment/local-before-agreement-concern-part2-20261102.sqlite`

Eight browser screenshots are under
`C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/agreement-concern-part2`.
They use browser-only concern fixtures, not saved demo notices.

## Part 3: Benefit delivery reports

Available locally only. This part does not establish a breach finding, impose a
support penalty, or disclose applicant/provider allegations to other organizations.

### Entry points

- Applicant: **Monitoring > Benefit releases > Benefit delivery reports**. This
  works for one-time awards as well as ongoing support, including before the first
  release is scheduled. A pending provider proposal also becomes the application's
  next-action guide, linking directly to the reports panel.
- Provider: **Benefit releases > Delivery reports**, accessible to the assigned
  benefit-release officer and authorized manager. Reported receipt issues also open
  the same modal from the distribution record. Unscheduled reports appear in the
  delivery queue and can become the release officer dashboard's primary action.
- Earlier receipt issues are listed separately. Opening one explicitly imports it;
  GET requests do not create reports or rewrite old resolution decisions. Original
  recipient text, private proof, and any earlier provider response are preserved.

### Process

1. The applicant selects an assigned entitlement or a published release, describes
   the delivery issue, and explicitly confirms the statement. Up to three private
   PDF/JPG/PNG attachments, 5 MB each, are optional. Unscheduled recurring benefits
   require a named period. The interface never invents a release deadline. A future
   scheduled release may be queried but cannot yet be reported as not received.
2. The report is **Awaiting provider**. The provider can plan a correction with a
   follow-up date, provide information, or submit proof against an actual released
   record covering the reported benefit. A follow-up date does not create a
   distribution schedule or payment. Delivery proposals require proof and a date.
3. Delivery proof changes the report to **Awaiting applicant confirmation**, not
   fulfilled. The applicant can confirm actual receipt with a received date or say
   it is still unresolved. An unresolved response returns it to the provider.
4. Only applicant-confirmed receipt closes a delivery claim and updates the affected
   fulfillment state. An information-only question can be closed as clarified,
   but this never confirms receipt or fulfills an entitlement. There is no automatic
   closure, adverse finding, account flag, penalty, or deadline-based forfeiture.

Normal successful receipt confirmation is unchanged when no report is open.
The old provider-only resolution endpoint now rejects unilateral closure with a
link/payload for the delivery report. `syncIssueResolution` cannot fulfill a benefit
without an actual applicant-confirmed report resolution. Application-first locking
serializes report commands with ordinary receipt confirmation and distribution
recording; stale revisions and conflicting request keys are rejected.

### Benefit isolation and history

- One-time entitlements are not reset to ready just because a provider plans a
  correction. Reporting an unscheduled entitlement does not prevent the first
  legitimate release from being scheduled. Existing duplicate-release guards stay.
- A genuinely missed appointment can be followed by a separately recorded
  replacement release containing the original benefits. The original missed result
  is retained. This process does not itself create a second payment.
- A release cannot be reused as delivery proof for different recurring-period
  reports. Unrelated period reports do not automatically dispute later releases.
- A later recurring period can be reported after an earlier period has been
  confirmed, even when the later period has no release schedule yet. Earlier
  confirmed receipts remain immutable and are not valid proof for the new report.
- Confirmation affects only the reported benefit(s). Other open reports in the
  same release are not silently marked received. Full receipt is projected only
  after the relevant release allocations have been confirmed.
- Reports remain available for previously cancelled entitlements without
  reactivating support. Old agreement acceptance dates/versions may be unknown;
  nullable legacy metadata is used instead of fabricated acceptance.
- Provider distribution records and original proof are never overwritten by this
  report flow. Case statements, proof hashes, and revision history remain append-only
  at the application level. Proof is private and role/program/ownership-scoped.

### Local schema and verification

`2026_11_02_000003_add_benefit_delivery_reports` adds delivery-source uniqueness,
issue/period/follow-up fields, explicit proposal/delivery links, typed response dates,
and a recipient confirmation timestamp on the legacy receipt projection. Existing
agreement acceptance fields become nullable for imported legacy reports only;
agreement concerns still require real accepted terms through their service.

The additive local migration was applied after creating
`storage/deployment/local-before-benefit-delivery-part3-20261102.sqlite`.
Fingerprints of the original columns in all **67 existing business/system tables**
matched immediately afterward. No sample reports or negative findings were seeded.
The post-browser audit also confirms unchanged original business records and no
real case/reply/proof writes. Authentication/activity changes are excluded from
that post-sign-in audit; they are not described as report mutations.

Verified behavior includes unscheduled/one-time/recurring reports, explicit receipt
confirmation, missing/tampered proof, foreign/unpublished releases, future dates,
retry idempotency, stale responses, owner/program/role access, legacy history,
rollback/file cleanup, missed-release replacements, and multi-benefit isolation.
The migration compiles against MySQL grammar without contacting hosting.

Verification results: the full backend regression run passed **952 tests / 9,683
assertions**. After the final later-period regression was added, the focused
recipient/delivery/concern suite passed **96 tests / 1,612 assertions** on the final
source. All **698 frontend tests** pass, the Vite production build passes (376
modules), PHP syntax checks pass, and `git diff --check` is clean.

Local Chrome checks use intercepted fixtures for all seven report writes, not
saved demo records. Applicant and provider modals were checked at 1440x1000,
1280x720, and applicant phone 390x844, covering focus, containment, pinned footer,
discard prompts, stale reload/reconfirmation, retained validation text, and exact
background scroll/viewport restoration. Eight screenshots are saved under:

`C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/benefit-delivery-part3`

The temporary local browser/profile and HTTP test server were stopped/cleaned up.

Private verification files are prefixed `benefit-delivery-part3-*20261102` under
`storage/deployment`: backend and frontend logs, Vite build log, browser JSON, and
pre-migration table fingerprints. These are not published web artifacts.

Portal notification transactions and recipient scoping are tested locally. Live
email delivery, an actual financial transfer, and a real legal finding are not
claimed as verified. Hosting has **not** been updated in this part. Deployment must
include Parts 1-3 migrations before the new controllers/assets are activated.

## Part 4: Independent Review, Outcomes, and Appeals

Complete locally only. No hosted data, accounts, or files were changed in this part.

### Workspaces and Presentation

- Review officer: **Case reviews** in the sidebar, at
  `/admin/workspaces/reviews/recipient-cases`. The queue contains explicitly
  escalated cases, with separate filters for case type, pending information,
  appeals, and recorded outcomes. Applicant photos use the existing private photo
  route with the neutral fallback. One case opens in a modal, not a new record page.
- Provider/applicant: open an issued agreement concern or benefit delivery report
  and choose **Case review and appeals**. Escalated cases also appear in the
  award's review panel. Private drafts cannot be escalated or viewed by admins.
- The modal separates the original source, the latest review, collapsed statements
  and proof, and earlier history. There is no default finding or recommendation.
  Forms retain text on validation/stale failures, require reconfirmation after
  reload, and use the established modal focus and viewport-lock system.
- Existing local review account: `platform.reviews`. An appeal outcome needs another
  authorized reviewer. The system does not create a second real demo reviewer
  automatically; isolated tests use separate factory-created reviewers.

### Process and Safeguards

1. An authorized party requests an independent review. An agreement concern must
   already have an applicant response or a passed recorded response deadline.
   Silence is not treated as proof. Delivery reports can be escalated while open.
2. A reviewer may request more information. Either party can append an explanation
   and up to three private PDF/JPG/PNG files, 5 MB each. Review requests and
   information exchanges do not change support, selection, or benefit receipt.
3. The admin records `not_established`, `excused`, or `confirmed`, a separate
   recommendation, and a rationale of at least 30 characters. Cited proof must
   belong to the case, remain available, and match its saved SHA-256. The reviewer
   explicitly confirms that the accepted source and responses were considered.
4. An outcome creates an immutable review record: reviewer snapshot, source
   revision, cited evidence/statement IDs, reasons, recommendation, and appeal date.
   Case statements and audit events remain append-only. A failed audit write rolls
   back the review, statement, notification, and new private files.
5. Either party can appeal the current outcome. The normal opportunity is 14 days;
   late appeals are accepted with an explanation of at least 30 characters. Pending
   appeals are shown as under review, while earlier outcomes remain readable.
   The original reviewer cannot decide that appeal or bypass independence by
   requesting information. Another authorized reviewer must record its outcome.
   An involved party also cannot decide its own case after an account role change.
6. Review findings and recommendations do not automatically apply a penalty,
   reverse a support decision, confirm payment, create debt/recovery obligations,
   suspend an account, change profile verification/DSS, or warn another provider.

### Support Decisions and Restoration

- New term-related early termination, including an `other` reason, requires a
  same-award, current independent review with a supported finding, a recorded
  consequence in the actual accepted terms, and the recommendation to consider a
  provider decision. The 14-day appeal opportunity must have passed and no appeal
  may be pending. Changed accepted terms, missing/changed proof, foreign reviews,
  replaced outcomes, or already-used review links are rejected under the award lock.
- The provider still records a separate reason, notice, effective date, and explicit
  support decision. The linked review ID and a case event preserve provenance.
  The review is not an automatic termination authorization detached from that step.
- Normal completion, successful releases, funding-ended decisions, and voluntary
  withdrawal remain separate. Existing historical decisions are not backfilled as
  misconduct findings. A new neutral review cannot authorize an adverse decision.
- Appeals of case-linked decisions use the independent review process, not the old
  provider-only reconsideration route. A provider cannot uphold or close its own
  case appeal. Legacy unrelated reconsideration workflows remain available.
- If support already ended, an appeal leaves that recorded state unchanged. A
  favorable independent outcome exposes the existing explicit reinstatement form;
  only then may the provider restore support with dates, terms, and a recorded
  resolution. Original applicant statements are not overwritten by this projection.
- Benefit-delivery reviews allow only no-support-change or delivery-follow-up
  recommendations. They cannot penalize the applicant, replace delivery proof,
  or mark a benefit received. A receipt confirmed while review is pending retains
  its original delivery outcome and fulfillment when the admin later records review.

### Storage and Local Verification

`2026_11_03_000001_add_recipient_case_reviews` adds `recipient_case_reviews`,
nullable case-review state/current-review fields, and a nullable support-decision
review link. New review rows reject ordinary model update/delete operations.
This is application-level append-only behavior, not protection from a database
administrator. Existing deletion/retention foreign-key behavior remains intact.

The local migration was applied after creating the private backup
`storage/deployment/local-before-case-review-part4-20261103.sqlite`.
Original-column fingerprints for all **67 pre-existing tables** matched after the
migration. The browser audit also confirms no changed original business records
or real saved reviews. Normal authentication/activity changes are excluded from
that post-sign-in business audit. No adverse demo cases, accounts, or findings
were seeded to exercise the feature.

Verification artifacts use the `recipient-case-part4-*20261103` prefix under
`storage/deployment`, plus `case-review-part4-baseline-20261103.json`. Browser writes
are intercepted fixtures, not real review commands. Eight screenshots are under:

`C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/recipient-case-review-part4`

Checks include the provider's nested request modal, admin queue and evidence-backed
outcome, a 1280x720 desktop, applicant 390x844 phone layout, stale reload and complete
reconfirmation, draft retention/discard, read-only original-reviewer appeal handling,
and restoration of the parent modal's viewport lock. No live email delivery, legal
adjudication, or financial transfer is claimed as verified.

Verification: the full regression run passed **965 backend tests / 9,853 assertions**.
After final validation and notification refinements, the focused recipient suite
passed **136 tests / 1,971 assertions**; the final case-review suite also passes,
including the account-role-change independence guard. All **705 frontend tests**
pass, and the Vite production build passes with **380 modules**. Scoped Pint/PHP
syntax checks and `git diff --check` are clean. The local Chrome run intercepted
all five review writes, with no unexpected writes or browser runtime errors.
The temporary HTTP server and browser/profile were stopped/cleaned up.

Benefit-release-only officers receive notifications pointing to their authorized
delivery workspace with all report states visible, not a restricted applicant
record page. Existing demo usernames and passwords were not changed.

**Hosting is not updated.** The final rollout must apply Parts 1-4 migrations before
activating the new controllers/assets. Cross-provider disclosure remains disabled.
The next step at the end of Part 4 was privacy safeguards; those are described below.

## Part 5: Privacy and Disclosure Safeguards

### What This Does Not Enable

Cross-provider disclosure remains hard-disabled in the model and every access path.
Another organization cannot read the case, files, statements, privacy notes, or access
history, even if the same applicant applies there or a database field says approved.
No profile warning, public/search flag, applicant blacklist, export, eligibility/DSS
penalty, automatic rejection, or new sharing grant is introduced.

The design separates case findings from a lawful reason to disclose. The
[National Privacy Commission's Data Privacy Act](https://privacy.gov.ph/data-privacy-act/)
addresses purpose, proportionality, accuracy, retention, lawful processing, sensitive
information, and data-subject rights in Sections 11-13 and 16. This implementation
is not a legal opinion or certification. A consent checkbox, confirmed finding, or
policy citation is not treated as sufficient authorization by this software.

Any future disclosure feature needs a separately reviewed lawful basis and policy,
limited audiences and fields, relevance/expiry rules, appropriate notices, access
and correction procedures, appeal/correction propagation to recipients, and a new
reviewed implementation. There is no configuration switch to bypass that work.
Benefit-delivery reports must never become adverse applicant warnings.

### Privacy Assessments

- The existing case-review modal has a collapsed **Privacy & access** section.
  It loads on demand rather than adding another dashboard, page, or prominent card.
- Authorized review admins can record an internal assessment: do not disclose,
  further policy review needed, or disclosure not necessary. None approves access.
- A purpose, reasons, recheck date within one year, and explicit acknowledgment
  are required. A policy/lawful-basis reference is optional and not an authorization.
- The row snapshots existing blockers, source revision, author, and date. Requests
  use idempotency keys and optimistic revisions under the application/case locks;
  a failed case-history write rolls back the assessment and revision together.
- Case status, finding, support decision, receipt, and other awards are unchanged.
  Any later case revision, including an appeal, supersedes the assessment. A missed
  recheck date also makes it outdated. Neither state can leave external access active.
- Detailed purpose, rationale, reference, and recorder identity are admin-only.
  The applicant and authorized originating provider see the non-sharing status,
  assessment summary, retention status, and scoped authorized-access history.
- The parent modal guards unsaved assessment edits and blocks conflicting review
  actions while editing. Stale reload preserves reasons but clears confirmation;
  a reviewer must acknowledge the updated record before saving again.

### Access Metadata and Retention

Authenticated case-record, evidence, and privacy-detail GET endpoints are audited.
Original concern and delivery-report read routes are covered, not just the review
modal's routes. Only case/file/actor references, role, named route, result, dedupe
key, and time are recorded. No document contents, paths, URL queries, IP addresses,
user agents, or browser fingerprints are stored in this log.

Repeated requests are coalesced per actor/case/route/file/result in five-minute
windows. This records access authorization, not proof of reading a document.
Successful private responses fail closed with 503 if the audit write fails;
an existing denial is never converted into a success. This is not a claim that all
platform listings, unauthenticated requests, or upstream routing failures are audited.

History is paginated: eight access rows and five assessment rows per page. Applicant
and provider views show authorized entries with staff team labels, not denied actors
or staff account names. Authorized admins can also review blocked/unavailable entries
and account names. No global searchable applicant reputation ledger is created.

`case-privacy:prune-access` removes access metadata older than **90 days**, with
`--dry-run` available. This is a project default, not a statutory retention mandate.
The scheduled job runs daily at 04:00 when the Laravel scheduler is operating.
The latest explicit case audit-retention hold prevents pruning even if the assessment
is superseded or its recheck expires; a later explicit assessment must release it.
Retention removes no case, finding, event, statement, review, or supporting file.
Privacy reviews/access rows reject normal model edits/deletes; the narrow retention
command intentionally deletes eligible metadata directly. This does not prevent
database-administrator access or override existing parent-record cascade policies.

### Local Verification and Rollout

`2026_11_03_000002_add_recipient_case_privacy_controls` adds two tables:
`recipient_case_privacy_reviews` and `recipient_case_access_logs`. No existing table
is altered or backfilled. The local database was backed up first to
`storage/deployment/local-before-case-privacy-part5-20261103.sqlite`.
Original-column fingerprints for all **68 pre-existing tables** matched immediately
after migration. Browser verification also preserved original business records,
excluding normal authentication/activity metadata as documented by the checker.
No real assessments, warnings, findings, cases, applicants, or providers were seeded.

Part 5 browser verification uses `CASE_REVIEW_SMOKE_PRIVACY=1` with
`tests/Browser/recipientCaseReviewSmoke.mjs`. Every case/assessment write is mocked;
the run checks provider read-only status, admin internal assessment, stale reload,
unsaved-change guards, keyboard focus, narrow desktop containment, applicant phone
layout, and superseded assessment status after appeal. Twelve screenshots are in:

`C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/case-privacy-part5`

Verification artifacts use `case-privacy-part5-*20261103` under `storage/deployment`.
The additive schema also compiles for MySQL without opening a hosting connection.
Final checks pass: **981 backend tests / 10,014 assertions**, **709 frontend tests**,
the production Vite build, scoped Pint, and `git diff --check`. The final Chrome run
intercepted all seven case/assessment writes with no unexpected writes or runtime
errors. Temporary browser profiles and the local test server were cleaned up.
Hosting was not updated at the end of Part 5. The subsequent Part 6 rollout is
recorded below.

## Part 6: Integrated Verification and Hosted Rollout

### Integrated Workflows

`RecipientAccountabilityEndToEndTest` exercises actual HTTP endpoints in an isolated
test database with fake private storage. Its first journey creates a private draft,
uploads evidence, issues notice, records an applicant response, escalates to an
independent reviewer, records an outcome and privacy assessment, enforces the appeal
opportunity, requires a different appeal reviewer, and supersedes the old assessment.
The award remains unchanged and another organization cannot read the case/proof.

The second journey reports a missing benefit, obtains a delivery-follow-up review,
links real test distribution records and proof, and confirms receipt only through
the applicant's explicit response. Neither the admin finding nor provider proof
alone fulfills the benefit. No financial transfer or real mail delivery is tested.

Final regression results: **983 backend tests / 10,053 assertions**, **709 frontend
tests**, production Vite build, scoped Pint, and `git diff --check` pass.

### Deployment and Preservation

- The prior hosted runtime matched recognized previous releases before replacement.
  The release excludes `.env`, uploads, private documents, vendor dependencies,
  database contents, seeders, tests, and local deployment artifacts.
- Private database, environment, and runtime backups were taken under maintenance
  mode before applying the five case migrations in order. No reset, truncation,
  schema rollback, case backfill, password rotation, or demo seeding was performed.
- Original-column fingerprints preserve existing business records across additive
  schema changes. Post-login checks also preserve substantive account/password and
  application fields, allowing only controlled derived DSS metadata for tested awards
  and normal authentication/operational metadata.
- All deployed runtime/asset hashes, the complete Vite dependency graph, named routes,
  migration ledger, and cleanup schedule definitions were verified on hosting.
  Production/debug/HTTPS/storage/readiness checks pass. Configured SMTP is not proof
  of renewed mailbox service or external email delivery.
- Hashed chunks from previous releases remain available to existing open sessions.
  Environment/private uploads and every original decision remain preserved.
- The initial runtime backup contained duplicate tar entries from overlapping inputs.
  The original remains untouched; a deduplicated private rollback archive retains
  every first-occurrence file with matching hashes and passes actual Linux extraction.
  The backup input overlap is fixed for future releases. No live rollback was run.
- The explicit recovery script requires `--restore-runtime`, refuses a silent return
  to pre-case code if real case history exists, and does not reset the database.
  Its checksum and shell syntax pass; a full disaster-recovery drill is not claimed.

Private release metadata and verification artifacts use the
`accountability-part6-*20261103` prefix under `storage/deployment`. The metadata records
the server-side backup directory and verified rollback archive without publishing
database dumps or credentials. `docs/recipient-accountability-demo.md` contains the
tester-facing handoff and is linked from the existing fresh-workflow demo guide.

### Hosted Browser Verification

`tests/Browser/recipientAccountabilityHostedSmoke.mjs` checks the actual hosted UI
and APIs as eight existing users: applicant accepted-award and benefit-report roles,
recipient officer, release officer, provider manager, a different organization,
admin review officer, and a second independent admin reviewer.

Checks cover security headers, role/program boundaries, empty case queues, available
accepted obligations/benefits, actual unsaved create modals, applicant phone layout,
desktop containment, and the availability of a second reviewer. No runtime errors,
5xx responses, or attempted business writes occurred. Nine screenshots are under:

`C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/accountability-hosted-part6`

Hosted positive case mutations are intentionally not submitted: those are exercised
in isolated integration tests, leaving every real demo decision to the tester.
The post-browser audit confirms no real case, statement, finding, privacy assessment,
or case-access row was invented by these checks. Existing demo passwords are unchanged.
Temporary Chrome processes/profiles were cleaned up.

### Scheduler Visibility and Remaining Boundary

Both cleanup commands pass hosted dry-runs and are registered at 03:30 and 04:00
in the application's configured scheduling timezone. Hostinger's SSH shell does not
expose `crontab`, so this rollout does not claim that the hPanel `schedule:run`
configuration was inspected or changed. Backup storage has a recent entry, but that
alone does not prove invocation of these new jobs. The site owner should confirm
the existing Laravel scheduler cron remains enabled in hPanel; no duplicate scheduler
daemon or new recurring job was started to bypass that visibility limitation.

Cross-provider disclosure remains disabled. A separate reviewed policy and future
implementation, not a checkbox or confirmed concern, would be required to activate it.
