# Complete Demo Accounts

The complete demo uses fictional identities and synthetic applicant portraits. Local tests use the fallback password only when no demo credential is configured. Hosted demonstrations require strong `DEMO_ADMIN_PASSWORD` and `DEMO_PASSWORD` values supplied only for the seed command.

## Main Accounts

| Role | Username | Email |
| --- | --- | --- |
| Super admin | `superadmin` | `superadmin@findscholarship.test` |
| Provider owner | `harborlight` | `programs@harborlight.test` |

## Provider Team Accounts

| Workspace | Username | Email |
| --- | --- | --- |
| Full-access manager | `harborlight.manager` | `harborlight.manager@scholarship.test` |
| Program coordinator | `harborlight.programs` | `harborlight.programs@scholarship.test` |
| Application reviewer | `harborlight.reviewer` | `harborlight.reviewer@scholarship.test` |
| Selection officer | `harborlight.selection` | `harborlight.selection@scholarship.test` |
| Decision officer | `harborlight.decisions` | `harborlight.decisions@scholarship.test` |
| Recipient officer | `harborlight.recipients` | `harborlight.recipients@scholarship.test` |
| Monitoring officer | `harborlight.monitoring` | `harborlight.monitoring@scholarship.test` |
| Benefit release officer | `harborlight.releases` | `harborlight.releases@scholarship.test` |
| Organization profile manager | `harborlight.profile` | `harborlight.profile@scholarship.test` |
| Team administrator | `harborlight.team` | `harborlight.team@scholarship.test` |
| Support staff | `harborlight.support` | `harborlight.support@scholarship.test` |
| Billing staff | `harborlight.billing` | `harborlight.billing@scholarship.test` |

Program-scoped staff are assigned to the Harborlight Senior Scholars Program. Their workspaces share the stage-by-stage applications below, an active monitoring review, one completed recipient lifecycle, resolved support history, and a completed optional-service purchase.

## Admin Team Accounts

| Workspace | Username | Email |
| --- | --- | --- |
| Account manager | `admin.accounts` | `admin.accounts@scholarship.test` |
| Review officer | `admin.reviews` | `admin.reviews@scholarship.test` |
| Support officer | `admin.support` | `admin.support@scholarship.test` |
| Billing officer | `admin.billing` | `admin.billing@scholarship.test` |
| Finance officer | `admin.finance` | `admin.finance@scholarship.test` |
| Records officer | `admin.records` | `admin.records@scholarship.test` |
| Portal manager | `admin.manager` | `admin.manager@scholarship.test` |

## Applicant Workflow Accounts

| Demonstration | Username | Email |
| --- | --- | --- |
| Pre-screening review ready | `alyssamaereyes` | `alyssa-maereyes@demo.test` |
| Formal application ready | `biancalouisenavarro` | `bianca-louisenavarro@demo.test` |
| Exam scheduled | `markangelodelacruz` | `mark-angelodela-cruz@demo.test` |
| Exam result ready to record | `carlojaviermendoza` | `carlo-javiermendoza@demo.test` |
| Interview scheduled | `sofiamarievillanueva` | `sofia-marievillanueva@demo.test` |
| Interview result ready to record | `gabrielluisramos` | `gabriel-luisramos@demo.test` |
| Final decision ready to record | `trishaannegarcia` | `trisha-annegarcia@demo.test` |
| Recipient agreement ready to accept | `danielpaoloaquino` | `daniel-paoloaquino@demo.test` |
| Monitoring submission ready to review | `nicoleandreabautista` | `nicole-andreabautista@demo.test` |
| Completed monitoring and support | `vincentrafaeltorres` | `vincent-rafaeltorres@demo.test` |

The scheduled exam and interview accounts let applicants review upcoming activity details. The completed exam and interview accounts let provider staff record the next result. The pre-screening, formal application, final decision, agreement, and monitoring records are also positioned for their next valid action. Vincent remains the read-only completed path with five passed stages, a closed monitoring case, confirmed releases, fulfilled benefits, and a completed support decision.

Local demo accounts use `password123` unless `DEMO_PASSWORD` or `DEMO_ADMIN_PASSWORD` is configured. Hosted passwords remain environment-managed and are not stored in this document.

## Program Lifecycle Examples

| Program | State |
| --- | --- |
| Harborlight Senior Scholars Program | Published with staged applications, active monitoring, and one completed recipient lifecycle |
| Ready-to-Learn Essentials Fund 2025-2026 | Closed prior cycle |
| College Launch One-Time Grant | Published one-time grant |

## Reset Command

Run only against a database intentionally prepared for the demonstration:

```bash
php artisan migrate:fresh --seeder=CompleteDemoSeeder --force
```

The seeder intentionally creates the super admin as user ID `1`, the primary provider as user ID `2`, and the Harborlight Senior Scholars Program as ID `1`. It removes failed, rejected, correction, overdue, and issue scenarios while retaining positive workflow records that demonstrate each next action.
