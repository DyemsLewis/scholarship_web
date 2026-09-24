#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

app_root="$HOME/domains/findscholarship.online/public_html"
credentials="$HOME/.scholarship-mysql.cnf"
backup_root="$HOME/backups/scholarship-portal"
stamp="$(date +%Y%m%d-%H%M%S)"
working_dir="$(mktemp -d "$HOME/.scholarship-backup-XXXXXX")"
archive="$backup_root/scholarship-backup-$stamp.tar.gz"

cleanup() {
    rm -rf "$working_dir"
}

trap cleanup EXIT

if [[ ! -f "$credentials" ]]; then
    echo "Missing protected MySQL credentials: $credentials" >&2
    exit 1
fi

mkdir -p "$backup_root" "$working_dir/files"

database_name="$(sed -n 's/^DB_DATABASE=//p' "$app_root/.env" | head -n 1 | tr -d '\r')"
database_name="${database_name#\"}"
database_name="${database_name%\"}"

if [[ -z "$database_name" ]]; then
    echo "DB_DATABASE is missing from the production environment." >&2
    exit 1
fi

mysqldump \
    --defaults-extra-file="$credentials" \
    --single-transaction \
    --quick \
    --skip-lock-tables \
    --no-tablespaces \
    --routines \
    --triggers \
    --default-character-set=utf8mb4 \
    --result-file="$working_dir/database.sql" \
    "$database_name"

for relative_path in storage/app/private storage/app/public public/uploads; do
    if [[ -d "$app_root/$relative_path" ]]; then
        mkdir -p "$working_dir/files/$(dirname "$relative_path")"
        cp -a "$app_root/$relative_path" "$working_dir/files/$relative_path"
    fi
done

printf 'created_at=%s\napplication_path=%s\n' \
    "$(date --iso-8601=seconds)" \
    "$app_root" \
    > "$working_dir/manifest.txt"

tar -czf "$archive" -C "$working_dir" database.sql manifest.txt files
sha256sum "$archive" > "$archive.sha256"

find "$backup_root" -maxdepth 1 -type f \
    \( -name 'scholarship-backup-*.tar.gz' -o -name 'scholarship-backup-*.tar.gz.sha256' \) \
    -mtime +14 -delete

echo "BACKUP_CREATED=$archive"
