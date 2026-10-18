# Application Stage Backend Foundation

Date: October 14, 2026

Part 2 adds storage and domain definitions only. The current application workflow remains authoritative until later parts explicitly connect these records to provider actions.

## Records

| Record | Responsibility |
| --- | --- |
| `application_stage_progresses` | Existing canonical per-applicant stage record, extended with optional operational status, outcome, and proof-verification fields |
| `application_stage_sessions` | Multiple dated exam, interview, or formal-application sessions for one program and stage |
| `application_stage_assignments` | Applicant assignment and rescheduling chain for a session |
| `application_stage_attendances` | One attendance result for an assignment |
| `application_stage_evidence` | Provider proof for either a whole session or one applicant |
| `application_stage_audit_events` | Durable stage history that survives session or assignment removal |

## Isolation guarantees

- Sessions have no uniqueness restriction on program and stage, allowing multiple dates and locations.
- Online meeting links use Laravel's encrypted model cast and are ciphertext at rest.
- Assignment, attendance, evidence, and audit writes do not call `ApplicationWorkflowService`.
- None of the new tables modifies legacy schedules, application decisions, monitoring, or benefit releases.
- Session and assignment deletion removes their operational children, while audit events retain their program and application context.
- Evidence remains private to the provider unless its visibility is explicitly changed to `applicant_shared` in a later workflow.
- Exam and interview proof are marked required by default in the domain catalog; enforcement begins only when the new provider workflow is connected.

## Deferred integration

Later parts will add authorized services and endpoints for publishing sessions, assigning applicants, recording attendance, uploading proof, and selecting stage-specific outcomes. Those actions will use transactions and audit events, but only the explicit outcome action will be allowed to advance an application.

## Verification

- The migration was applied successfully to the local database on October 14, 2026.
- The complete automated suite passed: 449 tests and 4,383 assertions.
