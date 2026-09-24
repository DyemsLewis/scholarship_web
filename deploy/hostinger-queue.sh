#!/usr/bin/env bash

set -Eeuo pipefail

cd "$HOME/domains/findscholarship.online/public_html"

marker="storage/logs/queue-cron.last-run"
started_at="$(date --iso-8601=seconds)"

if /usr/bin/php artisan queue:work \
    --stop-when-empty \
    --tries=3 \
    --timeout=60 \
    --max-time=240; then
    status="success"
    exit_code=0
else
    status="failed"
    exit_code=$?
fi

printf 'started_at=%s\nfinished_at=%s\nstatus=%s\n' \
    "$started_at" \
    "$(date --iso-8601=seconds)" \
    "$status" \
    > "$marker"

echo "QUEUE_CRON_STATUS=$status"
exit "$exit_code"
