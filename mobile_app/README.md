# Scholarship Portal Mobile

Flutter applicant-only mobile app for the Scholarship Portal.

The mobile app uses the same Laravel/MySQL backend through API endpoints under:

```text
/api/mobile
```

Provider and admin accounts are web-only. The mobile API only allows applicant accounts.

## Run Locally

Start the Laravel backend from the project root:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Run the Flutter app:

```bash
cd mobile_app
flutter run
```

Android emulator default API URL:

```text
http://10.0.2.2:8000/api/mobile
```

For a physical phone, run Laravel on your computer LAN IP and pass the API URL:

```bash
php artisan serve --host=0.0.0.0 --port=8000
flutter run --dart-define=API_BASE_URL=http://YOUR_COMPUTER_IP:8000/api/mobile
```

## Mobile API

```text
POST /api/mobile/register
POST /api/mobile/login
GET  /api/mobile/profile
POST /api/mobile/logout
```
