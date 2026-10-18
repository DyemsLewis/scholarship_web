# Application Stage Operations API

Date: October 14, 2026

Part 3 connects the normalized stage records to authorized provider actions. The existing application decision endpoint remains the only operation that can advance or close an application.

## Responsibility boundary

- Selection officers with `manage_selection_activities` can manage exam, interview, and formal-application sessions for programs assigned to them.
- Every endpoint also requires an approved provider organization.
- The service repeats organization, program, session, assignment, and application checks inside the transaction instead of trusting route middleware alone.
- A session can be drafted, assigned, published, rescheduled, tracked, evidenced, completed, or cancelled without changing the application's canonical stage.
- The explicit stage-result endpoint recognizes a completed normalized session as readiness evidence while retaining the legacy schedule fallback.

## Operations

| Operation | Result |
| --- | --- |
| Create or edit session | Saves one dated location or online meeting as a draft |
| Assign applicants | Adds only applicants currently waiting at the matching stage |
| Publish | Locks in a scheduled session and notifies assigned applicants |
| Reschedule | Preserves the previous assignment and links the replacement |
| Record attendance | Stores attendance without deciding pass or fail |
| Upload proof | Stores private versioned evidence for a session or applicant |
| Complete | Requires attendance and required session proof, then marks records ready for review |
| Cancel | Cancels active assignments, notifies applicants, and returns them to waiting for a schedule |

## Safeguards

- Capacity is checked while the session row is locked.
- Cross-provider, cross-program, cross-session, and wrong-stage records are rejected.
- Meeting links remain encrypted at rest.
- Replacement proof supersedes the prior record without deleting its file or history.
- Evidence files are private and served only through an authorized provider endpoint.
- Completing a session does not set a stage result, final outcome, monitoring record, or benefit release.

## Admin oversight

Review officers now have read-only selection-activity oversight. The queue identifies overdue attendance, completed sessions with pending attendance, missing required proof, and cancelled sessions that still have active assignments. Its record view exposes the schedule, applicant acknowledgment and attendance state, current proof, and audit history, but it does not expose online meeting links or any operation that can modify the provider record.

Provider-private proof is available to authorized review officers through a separate `manage_reviews` file endpoint with private, no-store response headers. Applicant and unrelated admin roles cannot open it.

## Compatibility status

Normalized sessions are authoritative for the new provider selection workspace, applicant stage-activity view, workflow readiness checks, and admin selection oversight. The legacy `application_schedules` and `scholarship_events` records remain available only for existing mobile clients, historical records, program event configuration, and the explicit compatibility fallback in `ApplicationWorkflowService`.

Legacy tables and routes must not be removed until mobile acknowledgment, historical schedule rendering, and program-event migration are completed. New admin oversight does not read or write either legacy table.

## Verification

- The dedicated operations suite passes 7 tests and 82 assertions.
- The complete application suite passes 456 tests and 4,465 assertions.
- Part 6 admin oversight verification on October 15, 2026 passes 463 Laravel tests with 4,551 assertions, 22 frontend contract tests, and the production Vite build.
