# Application Stage Demo Readiness

This local demo exercises the normalized selection workflow while retaining legacy schedule records for compatibility checks. Rebuild the full dataset with `php artisan migrate:fresh --seed --seeder=CompleteDemoSeeder`, or add only the normalized records to an existing complete demo with `php artisan db:seed --class=DemoSelectionActivitySeeder`.

## Demonstration path

1. Sign in as `harborlight.selection` and open **Selection activities**.
2. Show the two published activities under **Active**, then open either session to review its schedule, assigned applicant, acknowledgement, and pending attendance.
3. Open **Unassigned** to show the formal-application record that still needs a session.
4. Open **Results** to show the completed exam and interview records that are ready for an outcome.
5. Sign in as the applicant assigned to the morning exam and open the application. Confirm that only applicant-safe schedule details are visible and the activity is acknowledged.
6. Sign in as `admin.reviews` and open **Selection activities**. The attention queue contains one completed exam with missing required proof.
7. Open a documented completed activity to review attendance, private evidence, and immutable history without exposing the meeting link.
8. Return to the provider result queue and record the stage outcome. Session completion alone must not advance the application.

## Expected local records

- 2 scheduled sessions: one onsite exam and one online interview.
- 1 unassigned formal-application record.
- 2 completed sessions with attendance and provider-private proof.
- 1 completed historical exam with missing proof for admin oversight.
- 5 acknowledged assignments and 5 attendance records.
- 2 current evidence files.

The demo records are additive. They do not alter final outcomes, award decisions, recipient monitoring, or benefit-release data.
