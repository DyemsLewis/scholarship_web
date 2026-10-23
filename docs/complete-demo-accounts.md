# Complete Demo Accounts

All organizations and people in this dataset are fictional. Every applicant uses the same neutral silhouette instead of a real person's photograph. Local accounts use `password123` unless the demo password environment variables are set; hosted passwords are intentionally not stored here.

## Main Accounts

| Role | Username | Email |
| --- | --- | --- |
| Super admin | `superadmin` | `superadmin@findscholarship.test` |
| AralBridge owner | `aralbridge` | `owner@aralbridge.test` |
| FutureWeave owner | `futureweave` | `owner@futureweave.test` |

## Provider Teams

Both providers have a complete role team. Replace `{provider}` with `aralbridge` or `futureweave`.

| Workspace | Username | Email |
| --- | --- | --- |
| Full-access manager | `{provider}.manager` | `{provider}.manager@scholarship.test` |
| Program coordinator | `{provider}.programs` | `{provider}.programs@scholarship.test` |
| Application reviewer | `{provider}.reviewer` | `{provider}.reviewer@scholarship.test` |
| Selection officer | `{provider}.selection` | `{provider}.selection@scholarship.test` |
| Decision officer | `{provider}.decisions` | `{provider}.decisions@scholarship.test` |
| Recipient officer | `{provider}.recipients` | `{provider}.recipients@scholarship.test` |
| Monitoring officer | `{provider}.monitoring` | `{provider}.monitoring@scholarship.test` |
| Benefit release officer | `{provider}.releases` | `{provider}.releases@scholarship.test` |
| Organization profile manager | `{provider}.profile` | `{provider}.profile@scholarship.test` |
| Team administrator | `{provider}.team` | `{provider}.team@scholarship.test` |
| Support staff | `{provider}.support` | `{provider}.support@scholarship.test` |
| Billing staff | `{provider}.billing` | `{provider}.billing@scholarship.test` |

The AralBridge team owns the complete stage-by-stage application demonstration, active monitoring review, completed recipient lifecycle, resolved support history, and completed optional-service purchase. The FutureWeave team demonstrates a separate organization, role scope, and program directory.

Program access is intentionally divided for the demo. Each provider's full-access manager, organization profile manager, and billing staff can work across the organization. The program coordinator is assigned only to the provider's second published scholarship. The reviewer, selection, decision, recipient, monitoring, benefit release, team administration, and support accounts are assigned only to the provider's primary published scholarship. Provider owners still have access to every scholarship they own.

## Admin Team

| Workspace | Username | Email |
| --- | --- | --- |
| Account manager | `admin.accounts` | `admin.accounts@scholarship.test` |
| Review officer | `admin.reviews` | `admin.reviews@scholarship.test` |
| Support officer | `admin.support` | `admin.support@scholarship.test` |
| Billing officer | `admin.billing` | `admin.billing@scholarship.test` |
| Finance officer | `admin.finance` | `admin.finance@scholarship.test` |
| Records officer | `admin.records` | `admin.records@scholarship.test` |
| Portal manager | `admin.manager` | `admin.manager@scholarship.test` |

## Applicant Stages

| Demonstration | Username | Email |
| --- | --- | --- |
| Pre-screening review ready | `miracelesteandrada` | `mira-celesteandrada@demo.test` |
| Formal application ready | `noeladrianpangan` | `noel-adrianpangan@demo.test` |
| Exam scheduled | `talabeatrizsoriano` | `tala-beatrizsoriano@demo.test` |
| Exam result ready to record | `ivomiguellacuesta` | `ivo-miguellacuesta@demo.test` |
| Interview scheduled | `amihanclairerobles` | `amihan-clairerobles@demo.test` |
| Interview result ready to record | `enzorafaelmarasigan` | `enzo-rafaelmarasigan@demo.test` |
| Final decision ready to record | `liramaesarmiento` | `lira-maesarmiento@demo.test` |
| Recipient agreement ready to accept | `kianpaulovillareal` | `kian-paulovillareal@demo.test` |
| Monitoring submission ready to review | `nayaelisemanalo` | `naya-elisemanalo@demo.test` |
| Completed monitoring and support | `theogabrielalonzo` | `theo-gabrielalonzo@demo.test` |

## Programs

| Provider | Program | State |
| --- | --- | --- |
| AralBridge | AralBridge Scholars Pathway | Published with all application stages and recipient support |
| AralBridge | AralBridge College Start Grant | Published one-time award |
| AralBridge | AralBridge Learning Essentials 2025-2026 | Closed prior cycle |
| FutureWeave | FutureWeave STEM Discovery Grant | Published full-stage scholarship |
| FutureWeave | FutureWeave School Essentials Award | Published one-time award |

## Reset Command

Run only against a database intentionally prepared for this demonstration:

```bash
php artisan migrate:fresh --seeder=CompleteDemoSeeder --force
```

The seeder intentionally creates the super admin as user ID `1`, the AralBridge owner as user ID `2`, and AralBridge Scholars Pathway as program ID `1`. It retains one positive applicant record at every meaningful stage and excludes failed, rejected, correction, overdue, and issue scenarios.
