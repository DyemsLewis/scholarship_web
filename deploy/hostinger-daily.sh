#!/usr/bin/env bash

set -u

app_root="$HOME/domains/findscholarship.online/public_html"
status=0
started_at="$(date --iso-8601=seconds)"

bash "$app_root/deploy/hostinger-backup.sh" || status=1

cd "$app_root" || exit 1
/usr/bin/php artisan scholarships:send-reminders || status=1
/usr/bin/php artisan platform:prune-data || status=1

result="success"

if [[ "$status" -ne 0 ]]; then
    result="failed"
fi

printf 'started_at=%s\nfinished_at=%s\nstatus=%s\n' \
    "$started_at" \
    "$(date --iso-8601=seconds)" \
    "$result" \
    > storage/logs/daily-cron.last-run

echo "DAILY_CRON_STATUS=$result"
exit "$status"
