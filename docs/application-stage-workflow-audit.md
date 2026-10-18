# Application Stage Workflow Audit

Date: October 14, 2026

Scope: Part 1 baseline only. No production workflow or UI behavior is changed here.

## Current authority model

The submitted program configuration is normalized into an ordered stage list and copied into the application submission snapshot. Every application always includes `screening`, `formal_application`, and `decision`; `exam` and `interview` are optional and retain the provider's configured order.

Three provider permissions divide decision authority:

| Responsibility | Permission | Current stages |
| --- | --- | --- |
| Application verification | `verify_applications` | Pre-screening |
| Selection activities | `manage_selection_activities` | Formal application, exam, interview, schedules |
| Final selection | `record_final_decisions` | Selected, waitlisted, not selected |

The canonical application position is held by `application_state`, `workflow_stage`, `final_outcome`, and `application_stage_progresses`. The older `status` column remains as a compatibility value used by existing pages and reports.

## Current transition contract

| Action | Application result | Stage progress result |
| --- | --- | --- |
| Start application | `submitted` at `screening` | Screening is current; later stages are pending |
| Pass a review stage | Advance to the next configured stage | Current stage passes; next stage becomes current |
| Fail pre-screening | Closed as `rejected` | Screening does not pass; later stages are skipped |
| Fail formal application | Closed as `not_awarded` | Formal application does not pass; later stages are skipped |
| Fail exam | Closed as `exam_failed` | Exam does not pass; later stages are skipped |
| Fail interview | Closed as `interview_failed` | Interview does not pass; later stages are skipped |
| Waitlist at final decision | Remain at `decision` and await another decision | Decision stays current with a waitlisted result |
| Select at final decision | Closed as `awarded` | Decision passes with a selected result |
| Do not select | Closed as `not_awarded` | Decision does not pass |
| Withdraw | Closed as withdrawn | Every unresolved stage is skipped |
| Request a correction | Stay at the same stage in `needs_correction` | Stage result is not changed |
| Reopen latest decision | Return to the latest decided stage | That stage becomes current; later progress is reset |

Schedules are informational records. Publishing, updating, cancelling, or completing a schedule does not move the application. Exam and interview results are currently accepted through HTTP only after a completed schedule exists. A formal application result may be recorded without a schedule when no formal appointment was published.

## Side effects that must remain reliable

- A stage or final decision writes an application status-history record.
- Applicant notifications are created by the HTTP workflow action, not by the domain service.
- Decision-support snapshots and scholarship-event synchronization run after HTTP decisions.
- Final selection creates a recipient-agreement snapshot.
- Reopening retains history, resets later stage progress, and cancels later schedules that are still scheduled.
- A selected decision cannot be reopened after agreement, monitoring, benefit-release, or recipient-support activity begins.
- Program capacity is locked and checked when recording a selected outcome.

## Gaps found for later parts

1. Intermediate stages support only `passed` and `not_passed`, so absence, rescheduling, waiver, correction, and recommendation are not first-class outcomes.
2. There is no provider stage-evidence record. Uploaded applicant documents and monitoring evidence solve different problems and should not be reused.
3. `application_schedules` allows only one row per applicant and stage, while `scholarship_events` allows only one row per program and stage. Updating a schedule overwrites the prior arrangement, so multiple dates, locations, rooms, or preserved reschedules are not supported.
4. Attendance is not currently modeled as a real result. Schedule tracking resets it to `not_required`.
5. The completed-activity prerequisite is enforced in the HTTP controller rather than `ApplicationWorkflowService`; direct service callers can record an exam or interview result without that guard.
6. Applicant notifications, funnel events, and decision-support synchronization are outside the transaction that records the canonical decision.
7. Correction actions use activity logs but do not create the same status-history trail as stage decisions.
8. The compatibility `status` field and canonical workflow fields must be kept synchronized until all old consumers are migrated.

## Protected redesign boundaries

- Uploading proof, completing a session, or marking attendance must never advance an applicant.
- One explicit stage outcome remains the only operation that can advance or close an application.
- Submitted stage order remains stable even if the provider later edits the program.
- Provider organization and permission boundaries continue to apply to every action and file.
- Reopening preserves previous decisions, evidence, schedules, assignments, and responsible staff in the audit trail.
- Monitoring and benefit-release records remain separate and cannot be modified by application-stage actions.
- New session, assignment, attendance, and evidence records should be introduced alongside the current tables before any compatibility data is retired.

## Regression baseline

`ApplicationStageTransitionContractTest` protects the full configured path, the failure behavior of every reviewable stage, rejection of out-of-order and premature decisions without partial writes, and the rule that completing a schedule does not advance the application. Existing lifecycle, permission, scheduling, correction, waitlist, reopening, capacity, and document-lock tests remain part of the baseline.

Validation on October 14, 2026: `php artisan test` passed 444 tests with 4,343 assertions.

## Part 6 migration checkpoint

The provider selection workspace, applicant web experience, workflow readiness checks, and admin selection oversight now use normalized stage sessions. Admin oversight is intentionally read-only and never calls provider operation services.

The remaining legacy consumers are isolated to mobile schedule acknowledgment, historical applicant schedule payloads, program-level event configuration, compatibility tests, and the workflow fallback for records created before normalized sessions. These are retained deliberately; deleting their routes or tables before those consumers are migrated would break existing records and mobile clients.
