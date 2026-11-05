# Completed Qualification Support

## Delivery

1. Profile and backend foundation: complete locally, with the additive migration applied.
2. Provider eligibility controls and explicit qualification review: complete locally, including the additive program-policy migration.
3. Coordinated hosting deployment and final live verification: complete on 2026-11-01.

Income rules and monitoring are outside this change. Recording an old-curriculum diploma or ALS credential does not assert equivalence to Senior High School, make an eligibility match, or change provider decisions.

## Part 1 Contract

- Four nullable fields record completed qualification, awarding institution, completion year, and the name of an "Other" qualification. No existing profile is backfilled or made incomplete simply by installation.
- Current education, course/strand, year level, school, and academic result remain separate. An applicant who is not currently enrolled receives most-recent-studies guidance rather than an instruction to invent a strand or Grade 12.
- The academic-year selector also offers direct entry for earlier school years. A former student with a declared completed qualification is asked for completion evidence rather than mandatory proof of current enrollment. Existing enrollment-proof requirements remain unchanged for enrolled students and profiles without this declaration.
- The Learning modal keeps the optional education-history group collapsed until used. A declared qualification requires its awarding institution; "Other" requires a credential name. Completion year is optional but must be an integer from 1900 through the current year. Selecting no qualification clears dependent values.
- The web academic-section PATCH and mobile profile PATCH use the same normalization and validation. Partial edits retain saved qualification details; older clients omitting these fields leave them unchanged. Other web profile sections cannot update them.
- A separate `qualification_record` supports a declared completed qualification. It uses existing private upload, preview, revision, explicit-review, history, and document-library mechanisms. It does not invoke academic OCR or overwrite a current academic result.
- Existing profiles with no qualification declaration retain their earlier evidence requirements. Declaring a qualification adds its required evidence and reopens verification. Uploading or individually accepting the record is not overall profile approval.
- Qualification-claim changes invalidate only qualification evidence and overall verification, not unrelated current academic evidence or existing application stages. Mobile saves use the same targeted model observer rather than blanket-resetting unrelated accepted files.
- Saved applicant details and authorized reviewer records show readable qualification labels, the awarding institution, and the recorded year. No new provider eligibility controls are enabled in this part.

## Verification

- All 145 focused backend regressions passed (1,076 assertions), covering qualification validation, private uploads, individual review, targeted invalidation, older mobile clients, provider review, DSS, and existing required/preferred eligibility behavior.
- All 657 frontend tests passed. The production build, PHP formatting checks, and whitespace checks passed.
- Headless Chrome checks passed at 1440x1000 and 390x844. They exercised earlier academic-year entry, future completion-year rejection, optional completion years, required institutions, conditional credential names, cleared dependent fields, unchanged current studies, bounded dialogs, stable backgrounds, and visible footer actions. Form edits were discarded, not submitted.
- The local SQLite database was backed up before migration. A pre-browser preservation audit confirmed existing rows in all 60 business/runtime tables were unchanged and all four new columns were null. Only the migration ledger gained a migration entry. Subsequent browser sign-ins may update normal authentication/session records; they did not submit profile changes or review decisions.
- Private backups, fingerprints, test logs, and screenshots are retained with local QA artifacts. The temporary local server was stopped after verification.

Parts 1 and 2 were verified locally before the coordinated Part 3 hosting release. The Part 2 rules below do not backfill policies for existing programs.

## Part 2 Rules And Presentation

- Programs can optionally configure accepted completed qualifications and accepted enrollment statuses. Current-study level, course, and year filters remain separate; a completed credential does not silently satisfy them. No credential hierarchy or statutory equivalency is assumed.
- The new completed-qualification criterion supports the same Required/Preferred behavior as the existing criteria. An explicitly listed, recognized credential matches the list; a recognized unlisted credential either needs review or is a mismatch according to provider configuration. A declaration of no completed qualification does not become a degree in progress.
- Custom "Other", missing, and unclassifiable qualifications need review rather than an invented match. Unclear records remain review cases even with the stricter unlisted-credential setting; the form and summaries explain this distinction.
- Older readable education labels are recognized without weakening clear course or year discrepancies. Genuinely unfamiliar study descriptions are review cases. Active-enrollment requirements do not automatically treat admission, transfer, or returning-enrollment requests as enrolled.
- Incoming, transfer-pending, returning-pending, and not-currently-enrolled profiles need not invent an unconfirmed course or year. Supplied study values are retained, and current enrollment requirements resume when the enrollment status changes back. Older enrollment labels remain available when editing saved profiles.
- Pass/fail and unfamiliar grading systems are explicitly reviewed without inventing numeric averages or percentage labels. Existing same-scale numeric comparisons and reference-only numeric scale comparisons remain intact.
- Backend validation rejects invalid policy keys, invalid or duplicate credential choices, and contradictory active-enrollment/pending-status rules, including draft saves. Older clients omitting the new policy retain saved settings. Policy changes participate in the existing publication re-review mechanism.
- The optional controls sit behind one disclosure in the existing program Eligibility step. The builder review, provider record, both active admin review screens, applicant comparison, and provider review use readable labels and appropriate review signals. Validation uses the existing alert popup and returns server errors to the Eligibility step.
- Web submission, mobile submission, and DSS use the same eligibility service. DSS input snapshots include the policy and completed-qualification fields. Applications submitted with a stored submission snapshot retain that original comparison when a policy changes; current differences are shown separately, and workflow stages are not reset. Older pre-snapshot applications retain the existing compatibility behavior. Legacy comparisons do not gain false changes merely from additive metadata.
- Existing profile-verification, document, application-date, capacity, and permission gates remain authoritative. These rules do not automatically accept an application, grant an award, authenticate an issuer, or change monitoring decisions.

## Part 2 Verification

- Full backend suite: 837 tests passed, with 7,987 assertions. This includes the broader credential matrix, both submission channels, snapshots, explicit/unclear mismatches, missing numeric grades, and existing workflow regressions.
- Full frontend suite: 667 tests passed. The production build, PHP formatting checks, and whitespace checks passed.
- Local migration was preceded by a private SQLite backup. Fingerprints confirmed existing rows in all 60 business/runtime tables were preserved and existing programs received no invented policy values.
- Headless Chrome checks passed for the provider controls, missing-selection alert, Required/Preferred selectors, readable builder review summary, and full-width desktop layout. Program mutations were blocked and none were attempted. An outdated browser-test navigation selector was corrected; application navigation itself did not require a change.
- Applicant browser checks passed at 1440x1000 and 390x844, including pending-study optional fields, retained course/year values, earlier academic-year entry, completion-year validation, stable modal backgrounds, and visible actions. Draft edits were restored/discarded without saving profile data or review decisions.
- Authentication/session records can change during normal browser sign-ins. No demo program policy, applicant education history, application decision, or monitoring record was saved by the browser probes.

Private test logs, backup fingerprints, and screenshots are retained under local QA storage. Both qualification migrations and their matching backend/frontend were deployed together in Part 3.

## Part 3 Hosting Verification

- Deployed to `https://findscholarship.online` on 2026-11-01 using the existing authorized SSH connection. No Git push, key replacement, dependency reinstall, database reset, or demo password change was needed.
- Private database, runtime, and environment backups were taken before the runtime overlay. Only the two additive qualification migrations were run. Existing uploaded files and environment configuration were fingerprinted and preserved; old hashed frontend chunks were retained for open browser sessions.
- All shipped runtime hashes and the production build dependency graph matched the prepared release. PHP lint, cache rebuild, and the strict platform-readiness audit passed. Readiness checks confirm configuration, not actual SMTP delivery or a payment transaction.
- Thirteen synthetic education-policy cases passed on the hosted backend without database queries or writes. These cover explicitly accepted credentials, in-progress degrees, unlisted and custom credentials, enrollment-pending rules, legacy course mismatches, and unknown/nonnumeric grades.
- The hosted provider browser check passed for qualification choices, missing-selection alerts, Required/Preferred modes, accepted enrollment statuses, readable review summaries, and a full-width desktop layout. Program mutations were blocked and none were attempted.
- Hosted applicant checks passed at 1440x1000 and 390x844. They covered conditional qualification fields, completion-year validation, pending-study guidance, retained current-study values, bounded editors, stable modal backgrounds, visible footer actions, and private evidence previews. All draft edits were discarded.
- Read-only browser checks passed for the review officer, pending-verification applicant, previously verified applicant, portal oversight manager, and account manager. New fields are present without invented qualification data or new evidence requirements for undeclared qualifications. Legacy verification remains valid, private evidence is readable only through its authorized route, oversight cannot review evidence, and the account role is denied review access.
- Live HTTPS/security headers were retained. No unexpected browser runtime or HTTP failures were recorded in these checks.
- The final post-login preservation audit confirmed existing business rows, decisions, substantive account fields, password hashes, and migration history were preserved, apart from the two expected migrations. No profile change, review decision, application submission, award, or monitoring action was saved by live QA.

Release metadata, the private rollback location, deployment and preservation logs, browser reports, and desktop/mobile screenshots are retained in local QA storage. This release does not change income rules or monitoring.
