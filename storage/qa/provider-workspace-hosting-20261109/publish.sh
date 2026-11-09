#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

app=/home/u908761863/domains/findscholarship.online/public_html
stage=${1:?Pass the private release directory}
[[ "$stage" =~ ^/home/u908761863/deployments/provider-workspace-20261109-[a-zA-Z0-9]{6}$ ]]
[[ "$(realpath "$stage")" == "$stage" && "$(realpath "$app")" == "$app" ]]
cd "$app"
export DEPLOY_APP_ROOT="$app"

manifest=public/build/manifest.json
archive="$stage/runtime.tar.gz"
[[ ! -e storage/framework/down ]]
tar -tzf "$archive" > "$stage/files.txt"
tar -tvzf "$archive" > "$stage/modes.txt"
if grep -Eq '^[lh]' "$stage/modes.txt"; then exit 1; fi
php -r '
    $stage = $argv[1];
    $release = json_decode(file_get_contents("$stage/release.json"), true, 512, JSON_THROW_ON_ERROR);
    if ($release["migrations"] !== [] || hash_file("sha256", "$stage/runtime.tar.gz") !== $release["archive_sha256"]) exit(1);
    $files = file("$stage/files.txt", FILE_IGNORE_NEW_LINES);
    if (count($files) !== count(array_unique($files))
        || array_diff($files, array_keys($release["file_hashes"]))
        || array_diff(array_keys($release["file_hashes"]), $files)) exit(1);
    $valid = static fn ($file) => preg_match("~^(app/|resources/js/|routes/web\\.php$|public/build/(manifest\\.json$|assets/))~", $file)
        && !preg_match("~(^|/)\\.\\.(/|$)~", $file);
    foreach ($files as $file) if (!$valid($file)) exit(1);
    foreach ($release["before_hashes"] + $release["reused_build"] as $file => $expected) {
        if (!$valid($file) || is_link($file)) exit(1);
        $actual = is_file($file) ? hash_file("sha256", $file) : null;
        if ($actual !== $expected) throw new RuntimeException("Hosted baseline changed: $file");
    }
' "$stage"

mkdir "$stage/candidate"
tar -xzf "$archive" -C "$stage/candidate" --no-same-owner
php -r '
    $stage = $argv[1];
    $release = json_decode(file_get_contents("$stage/release.json"), true, 512, JSON_THROW_ON_ERROR);
    foreach ($release["file_hashes"] as $file => $expected) {
        if (hash_file("sha256", "$stage/candidate/$file") !== $expected) throw new RuntimeException("Candidate mismatch: $file");
    }
' "$stage"

: > "$stage/existing.txt"
: > "$stage/new.txt"
while IFS= read -r file; do
    [[ "$(realpath -m "$app/$file")" == "$app/"* && ! -L "$file" ]]
    case "$file" in
        public/build/assets/*)
            if [[ -f "$file" ]]; then cmp -s "$file" "$stage/candidate/$file"; fi
            ;;
        *)
            if [[ -f "$file" ]]; then
                printf '%s\n' "$file" >> "$stage/existing.txt"
            else
                printf '%s\n' "$file" >> "$stage/new.txt"
            fi
            if [[ "$file" == *.php ]]; then php -l "$stage/candidate/$file" > /dev/null; fi
            ;;
    esac
done < "$stage/files.txt"

env_before=$(sha256sum .env)
maintenance=0
started=0
cleanup() {
    status=$?
    trap - EXIT
    if (( status != 0 && started )); then
        echo 'Release failed; restoring the previous source and manifest.' >&2
        tar -xzf "$stage/runtime-before.tar.gz" --no-same-owner
        while IFS= read -r file; do
            [[ "$(realpath -m "$app/$file")" == "$app/"* && ! -L "$file" ]]
            rm -f -- "$file"
        done < "$stage/new.txt"
        php artisan route:cache --no-ansi
    fi
    if (( maintenance )); then php artisan up --no-ansi || status=1; fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM HUP

while IFS= read -r file; do
    if [[ "$file" == public/build/assets/* && ! -e "$file" ]]; then
        install -m 644 -- "$stage/candidate/$file" "$file"
    fi
done < "$stage/files.txt"

php artisan down --retry=45 --no-ansi
maintenance=1
php "$stage/verify.php" baseline
tar -czf "$stage/runtime-before.tar.gz" -T "$stage/existing.txt"
gzip -t "$stage/runtime-before.tar.gz"
started=1
while IFS= read -r file; do
    if [[ "$file" != "$manifest" && "$file" != public/build/assets/* ]]; then
        install -m 644 -- "$stage/candidate/$file" "$file"
    fi
done < "$stage/files.txt"
install -m 644 -- "$stage/candidate/$manifest" "$manifest.next"
mv -Tf -- "$manifest.next" "$manifest"
php artisan route:cache --no-ansi
php artisan platform:readiness --strict --no-ansi
php "$stage/verify.php" build
php "$stage/verify.php" verify
[[ "$env_before" == "$(sha256sum .env)" ]]
echo 'PROVIDER_WORKSPACES=published; SCHEMA_AND_BUSINESS_RECORDS=unchanged'
