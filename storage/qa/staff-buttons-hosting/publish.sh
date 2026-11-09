#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

app=/home/u908761863/domains/findscholarship.online/public_html
stage=${1:?Pass the private release directory}
[[ "$stage" =~ ^/home/u908761863/deployments/staff-buttons-20261109-[a-zA-Z0-9]{6}$ ]]
[[ "$(realpath "$stage")" == "$stage" && "$(realpath "$app")" == "$app" ]]
cd "$app"

manifest=public/build/manifest.json
source=resources/css/app.css
old=public/build/assets/app-8JcmqBPU.css
asset=app-staff-buttons-e12a3061aeea.css
new=public/build/assets/$asset
sha() { sha256sum -- "$1" | cut -d' ' -f1; }
before_manifest=f8dc9020b0e29209490c14e3d3b4206f0461f195b2fb0b6570084fafcc1658df
before_source=8c2a3f50f94d3628c010b192e3cd51d7b81a02fb8996c867675ff980e20d8149
after_manifest=c78bc29648627a9e4e0335ee54155e4deedd605907cd62c4ef08da5374dc1cae
after_source=d0fe5106337cf905a7ac41b7b5a2d83f11dd8ec711171a3695c35dc90fb6d654
after_asset=e12a3061aeea6eb878958ac1ceeb4f0dc3343e349a9918996104cd301f4a019e
[[ "$(sha "$manifest")" == "$before_manifest" && "$(sha "$source")" == "$before_source" ]]
[[ "$(sha "$old")" == 75855414b401b19556b78dc952746768de09cfa6a51308f612d44a2586c7a889 ]]
[[ "$(sha "$stage/manifest.json")" == "$after_manifest" && "$(sha "$stage/app.css")" == "$after_source" ]]
[[ "$(sha "$stage/$asset")" == "$after_asset" && ! -e "$new" && ! -L "$new" ]]

cp -p -- "$manifest" "$stage/manifest-before.json"
cp -p -- "$source" "$stage/app-before.css"
manifest_next=$(mktemp "$manifest.next.XXXXXX")
source_next=$(mktemp "$source.next.XXXXXX")
asset_next=$(mktemp "$new.next.XXXXXX")
source_swapped=0
manifest_swapped=0
cleanup() {
    status=$?
    trap - EXIT
    if (( status != 0 )); then
        if (( manifest_swapped )); then
            install -m 644 -- "$stage/manifest-before.json" "$manifest_next"
            mv -Tf -- "$manifest_next" "$manifest"
        fi
        if (( source_swapped )); then
            install -m 644 -- "$stage/app-before.css" "$source_next"
            mv -Tf -- "$source_next" "$source"
        fi
    fi
    rm -f -- "$manifest_next" "$source_next" "$asset_next"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM HUP

install -m 644 -- "$stage/$asset" "$asset_next"
install -m 644 -- "$stage/manifest.json" "$manifest_next"
install -m 644 -- "$stage/app.css" "$source_next"
[[ "$(sha "$manifest")" == "$before_manifest" && "$(sha "$source")" == "$before_source" ]]
mv -T -- "$asset_next" "$new"
source_swapped=1
mv -Tf -- "$source_next" "$source"
manifest_swapped=1
mv -Tf -- "$manifest_next" "$manifest"
[[ "$(sha "$new")" == "$after_asset" && "$(sha "$source")" == "$after_source" && "$(sha "$manifest")" == "$after_manifest" ]]
printf 'Published %s; saved the previous manifest and source in the private release directory.\n' "$asset"
