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
- Back up the database and private `storage/app` documents outside the web root.

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

## Build And Test

Run these before pushing or deploying:

```bash
npm run build
php artisan test
```
