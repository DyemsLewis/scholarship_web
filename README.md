# Scholarship Portal

A Laravel, Vue, and Tailwind CSS scholarship portal for applicants, scholarship providers, and administrators.

## Local Development

1. Install PHP dependencies:

```bash
composer install
```

2. Install frontend dependencies:

```bash
npm install
```

3. Create the local environment file:

```bash
cp .env.example .env
php artisan key:generate
```

4. Configure MySQL in `.env`, then run migrations and seed the admin account:

```bash
php artisan migrate --seed
```

5. Run the app locally:

```bash
php artisan serve
npm run dev
php artisan queue:work
php artisan schedule:work
```

Run each long-running command in its own terminal. The queue sends notification emails, while the scheduler creates deadline reminders.

## Demo Accounts

The seeder creates four verified local accounts. All use `password123` unless their corresponding environment variables are changed.

| Role | Email | Username |
| --- | --- | --- |
| Admin | `admin@scholarship.test` | `admin` |
| Demo student | `student@scholarship.test` | `student` |
| Tulay Aral provider | `tulayaral@scholarship.test` | `tulayaral` |
| Bukas Kinabukasan provider | `bukasfoundation@scholarship.test` | `bukasfoundation` |

Both fictional community providers are approved and own published scholarship programs. Change the demo account environment variables before seeding a hosted/production database.

## Hosting Checklist

Use `.env.production.example` as the starting point for hosted environments.

Required production settings:

- Set `APP_ENV=production`.
- Set `APP_DEBUG=false`.
- Set `APP_URL` to the real domain.
- Generate a real `APP_KEY` with `php artisan key:generate`.
- Use real MySQL database credentials.
- Change the default admin password before running `php artisan db:seed`.
- Point the web server document root to the Laravel `public` folder.
- Run `npm run build` before deployment or during the host build step.
- Keep a queue worker running for notification email delivery.
- Run `php artisan schedule:run` every minute using the host's cron or task scheduler.
- Set `PLATFORM_BACKUP_PATH` to a protected location outside the web root, preferably on a separate mounted disk.
- Run `php artisan platform:backup` once and verify the resulting archive before opening the site publicly.

Recommended production commands:

```bash
composer install --no-dev --optimize-autoloader
npm install
npm run build
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Recommended background processes:

```bash
php artisan queue:work --tries=3 --timeout=90
php artisan schedule:run
```

On production, supervise the queue worker so it restarts automatically. Configure cron to run `schedule:run` every minute; do not launch it manually once per day.

## Backups And Retention

The scheduler creates a verified database and upload archive at 2:00 AM when `PLATFORM_BACKUP_ENABLED=true`. Each archive includes a manifest and a `.sha256` checksum. Backups older than `PLATFORM_BACKUP_RETENTION_DAYS` are removed only after a new archive passes verification.

Run and inspect a backup manually:

```bash
php artisan platform:backup
php artisan platform:restore-check
```

The default local backup folder is `storage/app/backups`, which is outside the public web root. On hosting, use a protected mounted disk or copy completed archives to separate storage so a server or disk failure cannot remove both the live system and its backups.

The 3:00 AM retention task removes expired registration codes, mobile sessions, password-reset tokens, database sessions, old read notifications, and activity logs beyond their configured period. It does not automatically remove applicants, applications, decisions, support reports, or uploaded proof.

Preview retention cleanup without deleting anything:

```bash
php artisan platform:prune-data --dry-run
```

Restore drill:

1. Compare the archive hash with its `.sha256` file and extract the archive on a staging machine.
2. Put Laravel in maintenance mode and stop the queue worker before a real restore.
3. Import `database/database.sql` into an empty MySQL database, or replace the SQLite file with `database/database.sqlite`.
4. Restore `storage/private` to `storage/app/private` and `storage/public` to `storage/app/public`.
5. Run `php artisan migrate --force`, `php artisan optimize:clear`, and `php artisan platform:readiness --strict`.
6. Restart the queue worker, open `/up`, verify a private document preview, and then run `php artisan up`.

Perform this drill on staging before deployment and periodically afterward. Never test a restore by overwriting the only production database.

If you change `.env` after caching config, run:

```bash
php artisan optimize:clear
php artisan config:cache
```

## Health Check

Laravel exposes a basic health route:

```text
/up
```

Use `https://your-domain.com/up` to confirm the hosted app responds.

## Optional Provider Payments

The portal uses PayMongo Hosted Checkout V2 for optional provider support services. Applicant registration, scholarship matching, applications, and awards remain free. Paying does not change program visibility, matching scores, or approval decisions.

1. Create PayMongo test secret and webhook keys.
2. Add these values to `.env`:

```text
PROVIDER_BILLING_ENABLED=true
PAYMONGO_SECRET_KEY=sk_test_your_key
PAYMONGO_WEBHOOK_SECRET=whsk_test_your_webhook_secret
```

3. In PayMongo, register this HTTPS webhook URL:

```text
https://your-domain.com/webhooks/paymongo
```

4. Subscribe the webhook to `checkout_session.payment.paid`.
5. Run `php artisan optimize:clear` after changing environment values.

Keep both secrets on the Laravel server. Do not place them in Vue, `VITE_*` variables, source control, or the mobile app. The signed webhook is the source of truth; returning to `/provider/billing` does not mark an order as paid.

## Optional Academic Record Scanning

The applicant profile can use OCR.space to read a clearly labeled general average, GWA/GPA, or overall pass result from an uploaded JPG, PNG, or PDF academic record. When configured, applicants cannot type or overwrite that value; an admin or the program provider must still compare it with the private file before verification.

Create an OCR.space API key, then keep it only in the Laravel `.env` file:

```text
ACADEMIC_OCR_ENABLED=true
OCR_SPACE_API_KEY=your-server-side-key
```

The default free API configuration accepts files up to 1 MB. Change `OCR_SPACE_MAX_FILE_SIZE_KB` only when the selected OCR.space plan supports a larger limit. Run `php artisan optimize:clear` after changing environment values. If the service is not configured, the existing manual grade workflow remains available for local development. The portal stores the extracted overall result and scan status, not the full OCR transcript.

## Build And Test

Run these before pushing or deploying:

```bash
npm run build
php artisan test
php artisan platform:readiness --strict
```
