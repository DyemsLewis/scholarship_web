# Individual Profile Evidence Review

Date: 2026-11-01 (Asia/Manila)

## Delivery Parts

1. Backend foundation: implemented locally in this change.
2. Reviewer workspace: implemented locally, using the contracts below.
3. Applicant feedback and replacement experience: implemented locally.
4. Complete: integrated regression, coordinated hosting release, additive migration, live browser checks, and preservation audits passed.

Monitoring, scholarship selection decisions, provider payment processing, and issuer/PSA authentication are outside this scope. An accepted supporting document is a recorded human review decision, not a claim that an issuing authority authenticated it. Opening a preview does not create acceptance.

## Review Contract

- `PATCH /admin/applicant-verification-documents/{document}/review` requires the existing administrator `manage_reviews` permission. Providers, applicants, and the read-only Portal Manager cannot submit individual profile evidence decisions.
- Body: `status` is `approved` or `rejected`; `expected_revision` is required; `review_notes` is a trimmed reason required for replacement requests, with a 1,500-character limit.
- The service locks the applicant and current document, checks the revision, and returns HTTP 409 for an outdated decision. A replacement request may be recorded for a missing file; accepting a missing file is forbidden.
- Successful responses include `document` with the existing status/notes and additive revision, reviewer, reviewed-at, current-acceptance, and legacy-approval fields. The response includes the refreshed evidence summary and public applicant state so document decisions update counts and profile status together without overwriting the reviewer's draft final decision.
- Existing admin document payloads include the review URL, and applicant review payloads include the history URL. Applicant and authorized provider proof payloads receive read-only current-review metadata, not administrative history links.
- `GET /admin/applicants/{applicant}/evidence-review-history` uses the same review permission and returns an explicitly selected, paginated history (25 events per page). No storage paths or document contents are exposed by history.

## Approval Rules

The existing overall profile-verification endpoint remains a separate decision. All required evidence must have current individual acceptance, and each required file must still exist. Existing missing-evidence, school-ID, academic-result, OCR/manual-correction, permission, and note rules remain in force.

Neither administrator nor provider overall verification can blanket-approve documents. The provider's existing verification endpoint must pass the same evidence gate and still cannot override an administrator's reasoned reopening. Overall rejection does not manufacture document-specific rejections. Reopening a verified profile clears current document review metadata and records the reopening reason in immutable history rather than copying it into every document's individual feedback.

Accepting the final required document does not automatically verify the profile or pass an application. Application stages, existing awards, and monitoring records are not reset. The verification-before-new-application gate remains unchanged.

## Revisions And History

- New uploads, file replacements, individual decisions, relevant claim edits, and administrator reopenings have revisioned history. Removal preserves prior reviewer decisions with a null current-document reference. History retains original filename/type, actor identity snapshot, status transition, reason, and changed field names, but not deleted file contents.
- A file replacement clears only that document's current review. Relevant profile changes invalidate affected evidence types even when the profile is already pending. Contact-only changes and name casing/outer whitespace do not invalidate supporting evidence. The field-to-evidence mapping is centralized in `ApplicantEvidenceReviewService::CLAIMS`.
- Parent-account locking serializes verification, applicant file replacement/removal, and profile updates. Delayed OCR responses are discarded when the document revision or file path changed during scanning; OCR extraction is never an acceptance decision.
- History has no editing/deletion endpoint and its model rejects normal updates/deletes. This is application-level append-only behavior, not protection from a privileged direct database operator. Account deletion can still cascade according to existing retention behavior.

## Compatibility And Rollout

The additive migration adds revision/reviewer fields and the history table. Existing rows and overall approvals are retained; it does not invent earlier individual decisions. Existing approved documents without individual review metadata are explicitly identified as legacy. A reopened or changed profile must satisfy the new gate before a new overall approval. Existing verified profiles are not mass-revoked merely by installing the migration.

`user.evidence_review` reports required/accepted counts, missing/replacement/blocking types, `required_evidence_accepted`, legacy-profile approval, and an assurance notice. Evidence readiness is not a replacement for the final verification endpoint's other validations.

The migration is applied to the local development database. A private SQLite copy was used to rehearse rollback/reapplication and verify that existing business rows/approvals remain unchanged and that no review history is fabricated. This rehearsal did not roll back the actual development database. Private snapshots and logs remain under ignored `storage/deployment/`.

The reviewer UI now exposes individual decisions. The new migration, backend, and built frontend were deployed together after the applicant feedback and integrated regression parts passed. Historical sections below describe the earlier local-only milestones.

## Part 2 Reviewer Workflow

Open the Review officer workspace, choose Applicants, and open an applicant record. The local demonstration account is `platform.reviews`; `learner.verification` is the submitted profile ready for individual review.

1. The profile summary is visible immediately. Full profile details and photo review are collapsed to keep the evidence queue in focus.
2. The queue shows each required record, including records not uploaded. It distinguishes current acceptance, missing files, replacement requests, pending reviews, and earlier approvals without individual history. Unrelated absent optional records do not clutter the queue.
3. Review opens one document in a bounded desktop dialog. It pairs the file with the relevant self-declared claims, using the backend's central field mapping. Larger preview and download options remain available; OCR details stay collapsed and are explicitly unverified.
4. Accepting requires an explicit outcome and a comparison acknowledgment. Requesting replacement requires a specific, trimmed note. No document is accepted merely by opening it. Busy controls prevent overlapping saves and closing mid-save.
5. The returned revision, decision metadata, evidence counts, and profile state update together. Draft final notes and academic corrections are kept. A stale revision blocks another save until the current record is reloaded.
6. Final verification remains a separate step. Required evidence readiness and a valid academic result gate approval. A reasoned profile correction/reopening is still available while evidence is incomplete. Only approval submits reviewed academic values; requesting corrections does not overwrite academic results.
7. Review history loads only when expanded and supports server pagination. Read-only oversight cannot submit document, photo, or final decisions through this page. The older account-edit view links to the review workspace and also gates approval on current evidence readiness.

## Part 3 Applicant Workflow

The existing profile modal and aligned document list are retained, with focused guidance rather than another page or nested cards.

- Current acceptance, awaiting review, replacement requests, unavailable files, earlier approvals, and re-review after claim changes have distinct labels. Only current acceptance counts toward individual-review progress. Earlier overall verification remains valid; legacy metadata is not presented as a newly recorded review.
- A requested correction points to the affected record, not automatically to the academic record. Notification links include the evidence type; the modal scrolls to and focuses that row. Required corrections lead before missing uploads, while accepted notes and examples remain collapsed. The same feedback is not repeated as both an overall note and a document note.
- All required documents being accepted is explicitly separate from overall profile verification. Applicants are told to wait for the final profile decision rather than upload accepted files again.
- Replacing accepted evidence asks for confirmation and explains the impact on new applications. Existing application stages are not restarted. Consent, file type, and size are checked before upload; server validation remains authoritative.
- The applicant UI sends `expected_revision` for upload/replacement, removal, and rescan. Zero means a first upload. Stale operations return 409 without changing current evidence; failed replacement storage is cleaned up. The revision field remains optional for compatibility with older clients, but the current UI always supplies it for existing records.
- A conflict keeps current files and academic drafts, disables more file changes, and offers a focused refresh. Uploads/removals/rescans and confirmation prompts cannot overlap. Replacement feedback clears only after the new file is saved; earlier decisions remain in reviewer history.
- Required-file replacement preserves other outstanding correction notes and unrelated acceptances. Relevant profile edits return refreshed evidence states immediately. Non-academic file operations and evidence refresh preserve an unsaved academic result; an academic replacement or requested rescan explicitly updates the saved result.
- Applicants receive their own document availability and latest event context, without administrative review/history links, storage paths, actor IDs, or changed personal values. Retired/unlisted proof remains secondary reference material.

The local applicant demonstration account remains `learner.verification`. Browser-only examples show correction, current-acceptance, and legacy states without making demo decisions. Monitoring, scholarship selection, and hosting remain unchanged.

## Part 1 Verification

- All 15 new individual-review tests pass (97 assertions), including permissions, stale decisions, replacements, claim changes, preserved history, missing files, legacy approvals, and delayed OCR.
- The focused backend run passed 132 tests (1,024 assertions). A subsequent isolated regression run passed 56 tests (722 assertions), including profile verification and both updated end-to-end demo workflow test classes.
- The full backend sweep ran 786 tests and initially found two demo tests that still expected blanket approval. Those fixtures now make real individual review decisions through the new API, and both test classes passed in the subsequent regression run. The entire 786-test suite was not rerun afterward.
- All 617 frontend tests pass. No frontend components or styles changed in this part.
- The local migration preservation check confirms that existing business records and approvals are unchanged and no historical review decisions were invented.

Backend test processes should run sequentially or use distinct `TEST_TOKEN` values: Laravel's shared fake-storage directory can otherwise make concurrent test runs delete one another's files. Hosting has not been changed.

## Part 2 Verification

- All 61 focused backend regressions passed (759 assertions), including 18 individual-review tests, profile evidence/verification, reviewer refinements, and both demo workflow test classes.
- All 634 frontend tests passed. New coverage includes current/legacy/missing-file states, comparison and reason requirements, permission/revision guards, duplicate submissions, conflict recovery, draft preservation, and final-decision readiness.
- The production frontend build passed. PHP formatting and whitespace checks passed.
- Local browser checks cover the queue, explicit outcomes, busy-state protection, conflict/reload feedback, keyboard focus restoration, lazy history pagination, and dialog bounds at 1280x800, 1920x1080, and 1366x650. Browser decision/history scenarios use mocked responses; backend feature tests exercise the actual API. Browser screenshots/logs are stored privately with the local QA artifacts.
- The business-record preservation check still passes. No demonstration decisions were made and no hosting files or database records were changed.

## Part 3 Verification

- All 92 focused backend regressions passed (969 assertions), covering the individual evidence review, applicant profile/evidence workflows, verification gate, and demo workflows. Five additional API tests cover private applicant feedback, stale uploads/removals/scans, outstanding corrections after replacement, and immediate re-review metadata after claim edits.
- All 651 frontend tests passed. Coverage includes legacy/current acceptance, specific next actions, file policy checks, confirmation cancellation, revision submission, concurrent-operation guards, conflict refresh, preserved academic drafts, and async notification focus without repeated scroll jumps.
- The production build, PHP formatting, and whitespace checks passed.
- Desktop (1440x1000) and mobile (390x844) browser checks passed, including targeted feedback, duplicate-note suppression, consent, busy controls, accepted-file replacement confirmation, conflict recovery, final-verification guidance, and retained legacy verification. State-changing responses were mocked in the browser; actual endpoint behavior is covered by the backend tests.
- Local business records and existing approvals remain unchanged. No hosted files, hosted database records, or demonstration decisions were changed. Part 4 remains the integrated rollout and hosting deployment.

## Part 4 Verification And Deployment

- The full backend suite passed all 794 tests (7,706 assertions). An additional combined integration test passed (26 assertions), exercising a correction request, applicant replacement, individual acceptance, final verification, web and mobile application submission, and another replacement that blocks new applications without restarting existing stages.
- The integration fixture refreshes the authenticated model between requests, matching actual HTTP request lifecycles rather than retaining an old verified model inside the test guard.
- The current frontend still has its Part 3 production build and 651-test pass. No frontend code changed in Part 4. A scoped runtime archive is prepared privately under `storage/deployment/`; it excludes environment files, databases, uploads, credentials, dependencies, and seeders.
- Initial plain-SSH attempts could not unlock the authorized private key. Inspection of the earlier Python deployment helper found the existing saved authentication settings; that connection worked without asking the owner to unlock anything. No new key, authorized-key change, passphrase change, or SSH-agent change was needed. The earlier unlock-blocker diagnosis was incomplete.
- A fresh private-copy migration rehearsal passed the new preservation verifier before release.
- Deployed on November 1, 2026 (Asia/Manila), using private release directory `/home/u908761863/deployments/evidence-review-20261031-210051` (directory timestamp is UTC). Verified database/runtime rollback archives and checksums are retained there. Only the evidence-review migration was applied; no database reset, seeding, dependency installation, or password change occurred.
- Hosted strict readiness passed all checks. The build graph and new classes were verified, and the hosted manifest matches local SHA-256 `e400120407eadaf2c10040821cdb733b236fa7c2a08346258cec1567270d63b1`. Original business rows/approvals, uploads, and environment bytes match their pre-release baselines. Review history was not fabricated. Old hashed chunks remain available to already-open tabs; queue workers received a restart signal.
- Hosted browser checks passed with normal sign-ins for six existing demo accounts: `platform.reviews`, `learner.verification`, `learner.apply`, `marilag.manager`, `platform.manager`, and `platform.accounts`. The reviewer comparison/decision controls, separate final approval gate, applicant document statuses, private PDF endpoint/modal, 390px mobile layout, retained earlier verification, provider dashboard, read-only oversight, and account-role denial were checked. No unexpected HTTP/runtime errors or business-data-changing requests occurred.
- The strict pre-release business-data comparison passed before the site reopened. After browser sign-ins, a second audit verified the original SQL backup against that baseline and confirmed exactly one normal remember-token update. All other account fields, password hashes, profiles, approvals, application/monitoring/service data, and required evidence history remained unchanged; no demonstration decisions or uploads were issued.
- The site is live and local/hosted build hashes match. Screenshots and audit logs are retained with private local deployment artifacts. Readiness validates configuration, not actual SMTP delivery, issuer authenticity, or payment-gateway transactions; shared demo passwords remain unchanged by the owner's earlier request.
