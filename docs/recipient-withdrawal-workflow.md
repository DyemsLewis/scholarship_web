# Recipient Withdrawal Workflow

Date: 2026-11-01 (Asia/Manila)

## Delivery

1. Backend foundation: complete locally, with a backed-up additive migration and full regression verification.
2. Applicant and recipient-officer interfaces: complete locally and verified on 2026-11-02.
3. Coordinated hosting deployment and live verification: complete on 2026-11-02.

This handles applicant-initiated exit requests after accepting an award. It is separate from ordinary application withdrawal, declining an unaccepted offer, and provider-initiated support termination. It is not a general monitoring redesign or a determination of legal liability.

## State And Permissions

- A selected applicant with accepted, active support may submit a request with a trimmed 10-2,000-character explanation and explicit confirmation. Existing accepted terms/version/time are copied when available; current program terms are not substituted for the accepted snapshot. No legacy acceptance or contract is fabricated.
- A request begins as `pending`. It does not change the application, pause benefits, release a slot, revoke verification, or change any other scholarship.
- The applicant may cancel only their own pending request. Cancellation retains the request and history; it does not undo an already-approved exit.
- Only an active provider with `manage_recipients`, an approved organization, and access to that specific program may review it. Unassigned officers, other providers, applicants, and administrators cannot submit recipient-officer decisions through these endpoints.
- Review requires `approved` or `declined`, a trimmed 10-2,000-character explanation, explicit confirmation, and the current request revision. Declining records an administrative outcome and leaves support unchanged; it is not a legal finding that the applicant must remain in the program.
- A cancelled or declined request permits a new, separately recorded request if support remains active. Resolved requests cannot be edited, deleted, or reviewed again through the API.
- Application-first row locking, a database-unique pending-application key, and request revisions prevent duplicate pending submissions and stale review/cancel overwrites. Duplicate or stale actions return HTTP 409 without partial changes.

## Approval Effects

- Approval is effective at the recorded decision time, not a user-supplied or backdated date. The existing support-decision mechanism records `terminated` with reason `recipient_withdrew`, and the application support status becomes `benefits_terminated`.
- The original selection result, workflow stage, award amount, decision reason/time, accepted agreement, and acceptance timestamps remain intact. The exit decision, request, and status history are linked instead of rewriting the original award decision.
- Existing unfulfilled future benefit entitlements are cancelled. Approval does not invent entitlements from benefits added in a later program edit. Previously released/fulfilled benefits and open receipt disputes are preserved. Receipt confirmation for benefits delivered before exit remains available.
- New release schedules exclude ended support. Release creation and result recording recheck support under the application lock, so a stale page or direct request cannot prepare or release another benefit after exit. Existing uncompleted schedule records are retained, not falsely marked as delivered; staff may close them as withheld or missed without reactivating a cancelled entitlement.
- Existing support-outcome and legacy benefit-termination endpoints require the pending request to be reviewed before recording a separate closure. An approval rechecks current support and refuses to overwrite an already-closed record. The officer may decline an obsolete request with an explanation to close its queue item.
- No account suspension, blacklist, financial penalty, repayment obligation, automatic replacement award, or legal action is created. Other awards, profile verification, historical releases, and unrelated monitoring records are not reset.
- Existing human-reviewed support-reconsideration mechanisms are not removed. The withdrawal record itself remains final history even if a separate later support decision is deliberately recorded through those mechanisms.

## API Contract

- `GET /dashboard/applications/{application}/recipient-withdrawals`: owning applicant's paginated history and current `can_request` signal.
- `POST /dashboard/applications/{application}/recipient-withdrawals`: `reason`, `confirmed`; returns HTTP 201 with the new request.
- `PATCH /dashboard/recipient-withdrawals/{withdrawal}/cancel`: `expected_revision`, `confirmed`.
- `GET /provider/workspaces/recipients/withdrawals/data`: recipient-officer queue, restricted to accessible programs. Defaults to pending; supports `status=pending|approved|declined|cancelled|all` and validated positive page numbers.
- `GET /provider/applications/{application}/recipient-withdrawals`: authorized recipient officer's per-application request history.
- `PATCH /provider/recipient-withdrawals/{withdrawal}/review`: `outcome`, `review_note`, `expected_revision`, `confirmed`.

Histories/queues use server pagination with 20 requests per page. Explicit payloads provide readable labels, relevant agreement context, permitted action URLs, and request events. They do not return storage paths, acceptance IP/user-agent data, internal pending keys, actor IDs, or account credentials. Existing opaque URL resolution, authentication, CSRF, and throttling remain in force. Opaque links do not replace authorization.

## History And Notifications

Every transition records the revision, old/new status, actor identity snapshot, explanation, and timestamp, plus an activity-log entry. Event models reject normal updates/deletes. This is application-level append-only behavior, not protection against a privileged direct database operator; account/application deletion still follows existing cascading retention behavior.

Request submission/cancellation notifies active recipient officers assigned to the program, not every provider employee. A provider outcome notifies the owning applicant. Portal notification creation is transactional and existing email delivery is queued after commit. A notification is not proof of delivered email or an external legal notice. The UI entry points and focused notification landing behavior are Part 2.

## Verification

- All 33 focused backend tests pass (270 assertions). Coverage includes active/pending/declined/closed awards, original-term preservation, duplicate submissions, cancellation, stale decisions, assignment/organization/role boundaries, opaque URLs, validation, historical receipt access, distribution while pending, release blocking after approval, entitlement preservation without backfilling, history immutability, and rollback on an audit-write failure.
- The final full backend suite passed all 870 tests (8,257 assertions), including the existing agreement, application, monitoring, release, profile, and role-access workflows.
- PHP formatting passed. No applicant/provider frontend was changed in Part 1, so no new screen is claimed as delivered.
- The local SQLite database was privately backed up before the migration. Fingerprints confirmed that all existing rows in 60 tables were preserved, only the new migration was added to migration history, and the new request/event tables are empty. No demo exit decision was submitted.
- A connection-free MySQL grammar audit compiled both new tables and checked identifier-length limits. Tests use an isolated in-memory SQLite database. Revision/interleaving behavior is covered, but actual simultaneous MySQL connections and hosted browser behavior are not claimed; those checks belong to the coordinated rollout.

Private rollback files, preservation fingerprints, and test logs are retained under ignored local deployment storage. Hosting is unchanged in Part 1.

## Part 2 Interfaces

- The live applicant route is the application Decision/outcome page, not the older combined detail component. Accepted selections receive a compact Scholarship withdrawal section. It loads the authoritative request capability/history separately; loading errors do not expose an enabled request action.
- A request opens a bounded, single-column dialog with one explanation field and explicit confirmation. The immediate message states that support remains unchanged while review is pending. Accepted terms and exit guidance are collapsed, are drawn from the saved acceptance rather than current program text, and explain missing legacy snapshots honestly.
- The latest request has a consistent status, timestamp, and View action. Earlier requests use a collapsed, server-paginated list. The applicant may open a pending request and explicitly confirm cancellation; closing a dialog never sends a withdrawal or cancellation.
- Recipient officers have a dedicated Withdrawal requests sidebar entry and queue. The full-width desktop table uses applicant photos where available, complete program names, readable statuses, status/program/name filters, pagination, empty states, and recoverable load errors. API filtering remains organization/assignment-scoped, including multiword applicant searches.
- Reviewing opens the same focused dialog, with no default outcome. It shows the applicant's explanation first, then an explicit Approve/Decline selection, a required explanation, and an acknowledgment of the chosen effect. Full accepted-term context and transition history stay secondary disclosures rather than additional nested cards.
- Both sides share busy-close protection, duplicate-submit prevention, a stable viewport scroll lock, keyboard focus containment/restoration, and an explicit unsaved-draft prompt. A stale request disables submission, retains the explanation, reloads the authoritative request and permissions, and requires confirmation again. Resolved requests render read-only. Validation/network errors retain drafts for deliberate retry.
- Per-request read endpoints support targeted reloads without pulling an entire queue or bypassing ownership/program permissions. Provider notifications open the dedicated request route and its review dialog. Applicant outcome notifications focus the withdrawal section rather than an unrelated application area.
- Pending requests are visible on the assigned recipient officer's dashboard. The existing recipient record also includes a compact request panel and directs the officer to review it before separately closing support. These additions do not introduce another dashboard or grant the workflow to unrelated roles.
- Successful actions refresh request/support summaries without resetting an unrelated support-outcome draft. Ended support is described as ended rather than directing the applicant to continue receiving benefits; the original Selected result and accepted agreement remain unchanged.
- Screens use the existing restrained navy/amber design and small corner radius. Applicant dialogs are checked at phone and desktop sizes; the provider queue remains desktop-focused.

Part 2 introduces no additional migration and seeds no demo requests. The existing `learner.support.decision` accepted award and `marilag.recipients` officer account can be used for deliberate tester actions after the UI is verified. Hosting deployment remains Part 3.

## Part 2 Verification

- All 684 frontend tests passed, including 17 new withdrawal tests. They cover explicit outcomes/confirmation, trimmed notes, duplicate and busy guards, stale/revoked permissions, retained drafts, current revisions, authoritative request capability, out-of-order filters, recoverable loading, readable labels/dates, and the real page entry points.
- The full backend regression passed 874 tests (8,299 assertions). After the final search and UI-contract adjustments, the focused withdrawal suite passed all 37 tests (314 assertions). New coverage includes assigned-program queue filters, multiword names, targeted read endpoints, notification destinations, protected deep links, accepted-only applicant entry points, ended-support guidance, and dashboard queue scope. API decisions are exercised against isolated in-memory test fixtures.
- The final production build, PHP formatting checks, and whitespace checks passed. The page-render feature test now disables Vite integration so a concurrent build's temporary manifest removal cannot affect a server-rendered permission test.
- Headless Chrome checks passed against the local production build: applicant desktop 1440x1000 and phone 390x844; provider desktop 1440x1000 and short desktop 1280x720. They verify loaded applicant photos, a full-width queue, contained dialogs, visible footer actions, focus/scroll locking, unchanged background dimensions, explicit discard, busy-close prevention, pending request/cancellation, validation, stale reload/reconfirmation, and read-only resolved history.
- Browser mutation responses are simulated through request interception. All five request/cancel/review calls were intercepted, with zero unexpected business writes or runtime exceptions. Real authentication and authorized photo reads were used; no demo withdrawal or review decision was saved. Applicant/provider sign-ins use separate isolated browser sessions to avoid cross-account test-cookie interference.
- A browser check found that Keep editing could leave focus on a removed discard-prompt button. It now returns focus to the explanation field. Visual inspection also led to a neutral-silhouette fallback for unavailable applicant photos; no initials or real-person fallback images were added.
- A local preservation audit against the private Part 1 database backup confirmed original substantive business data, passwords, selections, accepted terms, profiles, release records, and support decisions remain unchanged. The new withdrawal tables are empty. Only normal account sign-in/log metadata and automatically refreshed DSS snapshots differed; those runtime/derived fields are explicitly excluded from the substantive comparison.
- Screenshots and logs are retained with local QA artifacts under `recipient-withdrawal-part2`. The temporary local server and test browsers were stopped. Hosting remains unchanged until Part 3 deploys the Part 1 migration and matching backend/frontend together.

## Part 3 Hosted Release

- Deployed to `https://findscholarship.online` on 2026-11-02 (Asia/Manila), using the existing authorized SSH connection. The private release directory is `/home/u908761863/deployments/withdrawal-20261101-205747`; its timestamp is UTC. No Git push, new key, dependency installation, account reset, or password change was needed.
- The scoped archive contains 592 runtime/source/build files and is about 1.7 MB. It excludes credentials, uploads, databases, dependencies, test fixtures, and seeders. The prior hosted backend/build was recognized before replacement. Private database, runtime, and environment rollback copies were verified before overlay, outside the public website.
- Only `2026_11_01_000004_create_recipient_withdrawal_requests` was applied. Both new tables are empty, and the one-pending-request unique index was verified. All existing business records matched their pre-release fingerprints before the site reopened. Uploaded-file fingerprints and environment bytes remained unchanged.
- PHP lint, cached routes/views/configuration, queue restart signaling, the production build graph, and the strict platform-readiness audit passed. All deployed runtime hashes match the prepared release. The hosted and local build manifest SHA-256 is `c59d90065dfb5304bb3ea294e883b29ead62168a93dc9c740bd498b945f05a21`. Old hashed chunks were retained for browsers already open during the release.
- Five read-only hosted availability cases passed. A two-connection MySQL test held the same application row lock in one transaction, confirmed the competing connection timed out, and then acquired the lock after release. Both transactions were rolled back without business writes. This verifies the application-first locking primitive, not a production request approval/rejection.
- Live browser checks passed with seven normal demo sign-ins: `learner.support.decision`, `marilag.recipients`, `marilag.manager`, `marilag.releases`, `silayan.recipients`, `learner.release.plan`, and `platform.reviews`. Separate browser sessions prevented cross-role cookie interference.
- The hosted applicant outcome page, request capability/history API, saved-term disclosure, unsaved-draft guard, focus restoration, stable background, and visible dialog footer passed at desktop 1440x1000 and phone 390x844. The typed draft was discarded; no request was submitted.
- The hosted recipient-officer queue and recipient-record panel loaded correctly at desktop and short-desktop sizes. Authorized private applicant photos returned image responses. Manager access worked, while release-only staff, another organization, another applicant, and an admin review officer were denied actions/data outside their role or ownership. The empty queue reflects the absence of demo withdrawal requests, not a missing UI.
- HTTPS/security headers were retained. No browser runtime exceptions, server errors, or business-changing requests occurred during live checks. Provider decision controls were exercised through browser-mocked local checks and real isolated backend tests in Part 2; no production approval/cancellation was created merely to populate the queue.
- The final post-login audit verified original substantive account fields, password hashes, application selections, agreement acceptances, awards, releases, profile records, support decisions, and existing DSS history. Viewing the tested application appended one normal calculated DSS snapshot; only that explicitly identified application's derived DSS fields and normal authentication/runtime metadata were permitted to differ.
- Final checks reconfirmed the database/runtime/environment backup checksums, all shipped file hashes, and unchanged uploads/environment. The site is live. No demonstration request, withdrawal event, or exit decision was saved. SMTP readiness checks validate configuration, not delivered email; no actual payment or issuer-authentication operation was tested.

The three-part withdrawal delivery is complete. Private release metadata, rollback paths, preservation logs, MySQL probe results, browser reports, and hosted screenshots are retained under local QA/deployment storage. No income-rule or broader monitoring redesign was included.
