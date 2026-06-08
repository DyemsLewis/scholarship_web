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
```

## Demo Admin Account

The seeder creates one admin account using these default local values:

```text
Email: admin@scholarship.test
Username: admin
Password: password123
```

Change `ADMIN_EMAIL`, `ADMIN_USERNAME`, and `ADMIN_PASSWORD` before seeding a hosted/production database.

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
