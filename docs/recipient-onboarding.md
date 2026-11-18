# Recipient Onboarding

Part 1 adds backend records only. It does not add a contract-signing calendar,
store signed contracts, claim independent authentication, or enforce release
blocking. Provider screens, applicant guidance, activity integration, and final
release checks are subsequent parts.

Part 2 adds the provider workflow described below. Part 3 adds applicant
disclosure and guidance. Part 4 adds the distribution safeguards described below.
Part 5 completes the demonstration guide, end-to-end checks, and hosting
deployment. Existing local and hosted records are preserved. With explicit user
approval, one separate hosted demo intake was added; the local dataset was not
reset or reseeded.
The complete workflow was deployed on November 18 2026 with the additive schema
migration and all frontend/backend changes together.

## Program Policy

`scholarships.onboarding_policy` is nullable for backward compatibility. Program
create/edit APIs accept a JSON object or JSON-encoded object containing:

```json
{
  "version": 1,
  "final_agreement_required": true,
  "instructions": "Complete the final agreement with the provider before distribution."
}
```

Required agreements need nonblank instructions. Unknown keys, contract files,
completion flags, invalid booleans, and unsupported versions are rejected. A
disabled requirement has null instructions. Omitted policy fields preserve the
saved configuration. Material changes require a new intake after the first
application. Changes to published program policy use existing publication review.

## Snapshot and Initialization

The policy is exposed in program data and frozen in the selected applicant's
existing offer snapshot under `onboarding`. Refreshing an offer preserves this
part of the snapshot, including its absence in legacy offers.

Confirming intention creates one separate onboarding record, atomically with the
offer response. It starts as `pending` or `not_required`, never `completed`.
Repeated initialization is idempotent. Legacy offers are not backfilled and never
inherit requirements from the current program configuration.

## Provider Actions

`RecipientOnboardingService` provides `publishInstructions` and
`confirmCompletion`. These actions
require an active, approved organization, `manage_recipients`, and access to the
specific program. The applicant must already have confirmed the selected offer.
Release officers and applicants cannot confirm onboarding for themselves.

Supplementary provider instructions do not replace the originally disclosed
policy. Explicit confirmation and a completion date are required; attendance is
not confirmation. Dates cannot be future-dated or earlier than intention
confirmation. Ended support is read-only. Existing completion cannot be silently
overwritten. Optimistic revisions reject stale actions.

Each action writes an audit event in the same transaction, retaining the actor's
name and recording time. Private confirmation notes and audit events are not
included in applicant summaries.

## Provider Workflow (Part 2)

- Program create/edit, Selection: configure Final provider agreement. Only newly
  configured policies are submitted; unrelated edits preserve null legacy policy.
  The setting is disabled after the first application and included in program review.
- Recipients, open a record, Onboarding: view the frozen requirement, publish
  practical instructions, and record the date of actual completion. New pending
  requirements become the recipient record's default next action.
- Both writes use modal forms and the shared confirmation dialog. Cancel discards
  the local modal draft. Stale revisions require reloading, not silent retries.
- GET `/provider/applications/{application}/onboarding` is authorized by provider
  approval, organization, program assignment, and recipient/monitoring/release role.
- POST the same path plus `/instructions` or `/complete` requires
  `manage_recipients`, an explicit confirmation, and the current revision.
- Monitoring/release readers see status but not private notes or audit history.
- Instruction publication and completion notify the applicant atomically with the
  recorded action. No contract file, new appointment, or benefit delivery is created.
- Onboarding history is collapsed by default, ordered newest first, and preserves
  the original instruction and completion revisions.
- Browser verification uses an isolated SQLite copy and temporary preview users;
  its successful completion is not a signing record in the regular demo database.

## Applicant Guidance (Part 3)

- The public scholarship's Benefits and recipient terms disclose a configured
  final-agreement requirement. The frozen offer repeats it visibly above the
  summary tabs, before the applicant confirms intention.
- The confirmation dialog explains that intention is not signing and points to
  Scholarship Support > Requirements for the next step.
- Requirements contains a read-only Final agreement card with the original
  offer instructions, separately published provider instructions, and completion
  status. Actual contracts stay outside the platform. There is no applicant
  completion, contract upload, or attendance-based completion action.
- Overview and the support landing share the same next-action decision. Pending
  onboarding links directly to the requirement after the page data loads.
  Urgent record corrections, benefit follow-up replies, receipt confirmations,
  support outcome responses, payout details, and existing requirement appointments
  retain their prior priority. A compact Overview link keeps onboarding visible
  when another action takes priority.
- One-time awards retain Requirements whenever the frozen offer requires a final
  agreement, including after completion. Legacy and explicitly not-required
  offers retain the existing navigation and empty states.
- Completed requirements show the provider-recorded date, not an independently
  authenticated contract. Private staff notes and audit history are not disclosed.
  Ended or declined support requests no new onboarding; mismatched intention
  versions direct the applicant to seek provider review.
- Contact links use validated email/phone values from the recorded offer.
  Existing events remain in Benefits & Events; no signing-only event is created.
- Tests cover the applicant data contract, ownership, provider-only writes,
  legacy compatibility, read-only states, and action priority. Browser previews
  use an isolated SQLite copy, not the regular demonstration data.

## Scheduling and Distribution (Part 4)

- Onboarding is not a scheduling eligibility blocker. An otherwise eligible
  recipient can be included in an existing orientation/first-collection visit
  while the agreement is pending. The candidate shows Can schedule and explains
  the requirement before distribution. Existing monitoring and support holds
  still apply to scheduling.
- Recipient instructions may describe signing during that visit. There is no
  signing-only calendar, attendance-to-signing conversion, or automatic benefit
  receipt. The agreement, actual distribution, and applicant receipt remain
  separate records and confirmations.
- The distribution form combines the existing monitoring readiness with the
  frozen offer's onboarding requirement. Pending onboarding defaults to Prepared
  and disables Released / received and transfer Sent. A hold names the recipient
  officer or manager; only authorized recipient staff get the onboarding link.
- `RecipientOnboardingService::assertDistributionAllowed` runs inside the release
  transaction after acquiring the same application lock used for onboarding
  changes. Direct requests cannot bypass it. Rejection occurs before permanent
  proof storage, delivery/allocation updates, result notifications, or audit logs.
- Physical, bank, e-wallet, and other distributions all require completion when
  the frozen offer requires it. A bank/e-wallet transfer date cannot precede
  agreement completion; the form's earliest date also respects payout approval.
- Prepared, Missed, and Withheld remain available. Completion does not distribute
  a benefit or bypass the scheduled time, original-document verification,
  monitoring requirements, payout approval, or transfer-proof checks.
- Previously recorded distributions still allow applicant receipt confirmations
  and issue follow-ups without retroactively manufacturing onboarding records.
  Legacy/not-required offers retain the existing release workflow; a completion
  for an older offer version does not unlock a new one.

## Compatibility and Remaining Integration

- `RecipientAgreement::payload` includes an additive `onboarding` summary.
- `final_contract_recorded` remains false: no signed contract is stored.
- Offer response, selection, entitlement activation, and monitoring remain intact.
- No release eligibility guard changes in Parts 1-3.
- Scheduling remains possible while onboarding is pending; actual distribution
  requires completion in Part 4. No automatic cancellation or selection change
  follows from pending onboarding.
- Hosting now has the complete backend, migration, applicant disclosure, and
  release enforcement. Configure a requirement only on a new intake or unused
  draft before applications; existing offers remain legacy records.
- No signing or receipt is fabricated, and no existing recipient is marked signed.

## Verification and Guide (Part 5)

- The end-to-end integration test follows a required one-time offer through
  applicant intention, published instructions, collection scheduling, preparation,
  rejected premature distribution, provider completion, actual distribution, and
  applicant proof/receipt. No step silently performs the next one.
- The final focused regression run passed 197 backend tests and 64 frontend
  tests. Earlier isolated desktop/mobile browser checks cover the required path,
  modal confirmations, one-time navigation, instructions and release holds.
- Hosted read-only browser checks passed for `orivanta.manager`,
  `orivanta.recipients`, `orivanta.releases`, `learner.aurora`, `learner.brio` and
  `lumenops.reviews`. They verify settings, the new endpoint and permissions,
  scheduling/distribution data, legacy offer responses, applicant support,
  mobile layout and unaffected admin review. No business decisions were submitted.
- Deployment checksummed private database/runtime backups and staged inputs,
  used maintenance mode during schema/runtime installation, verified all previous
  business table rows and columns, preserved environment/uploads, rebuilt caches,
  and restarted queues. Existing offers gained no retroactive requirement and no
  onboarding/completion rows were manufactured. The hosted readiness audit passed.
- The existing expanded process guide is updated in place, not replaced by a
  separate guide. It adds configuration, specialist account responsibilities,
  applicant instructions, provider confirmation and release safeguards. Screenshot
  captions distinguish hosted screens from isolated required-path previews.
- Shared demo passwords remain unchanged. SMTP configuration readiness is not
  proof of successful delivery of a test email; no external payment or mail test
  is claimed by these checks.

## Hosted Demonstration Starting Point

The user explicitly requested a separate intake after deployment. A fresh verified
database backup precedes this additive preparation; no previous offer was rewritten.

- Program: `Orivanta Welcome Scholars Award`, a one-time school essentials kit.
- Applicant: `learner.aurora`; its selected offer is awaiting intention confirmation.
- Program configuration: `orivanta.programs`; inspect Selection. The disclosed
  agreement requirement is already locked after the prepared application.
- Provider onboarding: `orivanta.recipients`; after the applicant confirms intention,
  publish instructions, then record actual final-agreement completion separately.
- Collection and distribution: `orivanta.releases`; schedule after intention,
  prepare while onboarding is pending, distribute only after recorded completion.
- Combined workflow: `orivanta.manager`. Limited specialist scopes were extended
  only with this new program, without replacing their previous assignments.
- Existing passwords are unchanged. No new account, signing record, appointment,
  distribution or applicant receipt was created for this intake.
- Read-only hosted browser checks passed for applicant disclosure, responsive offer
  rendering, the applicant Requirements card, the frozen program policy and both
  recipient/release role access. They submitted no business-changing action.
- The same expanded process guide now points to this hosted intake and its actual
  accounts. Later-state screenshots remain explicitly labelled isolated previews,
  rather than implying that the pending hosted offer has already been accepted.
