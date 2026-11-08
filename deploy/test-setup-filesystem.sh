#!/bin/sh
# Local temporary-directory regression checks; never provisions system services.
set -eu
repo=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
# shellcheck source=deploy/setup-filesystem.sh
. "$repo/deploy/setup-filesystem.sh"
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT HUP INT TERM
mkdir -p "$fixture/app/storage/app/public" "$fixture/app/bootstrap/cache" "$fixture/app/public"
chmod 2770 "$fixture/app/storage/app/public"
ln -s ../storage/app/public "$fixture/app/public/storage"
printf 'test\n' > "$fixture/app/public/index.php"
printf 'secret\n' > "$fixture/app/.env"
chmod 0600 "$fixture/app/.env"
bulls_harden_code "$fixture/app" "$(id -u):$(id -g)"
[ "$(stat -c %a "$fixture/app/storage/app/public")" = 2770 ]
[ "$(stat -c %a "$fixture/app/.env")" = 600 ]
[ "$(stat -c %a "$fixture/app/public/index.php")" = 640 ]
bulls_check_enabled_site "$fixture/enabled" /etc/nginx/sites-available/bulls
ln -s /etc/nginx/sites-available/other "$fixture/enabled"
if bulls_check_enabled_site "$fixture/enabled" /etc/nginx/sites-available/bulls; then
    echo 'Must reject unrelated nginx symlink' >&2; exit 1
fi
rm "$fixture/enabled"
ln -s /etc/nginx/sites-available/bulls "$fixture/enabled"
bulls_check_enabled_site "$fixture/enabled" /etc/nginx/sites-available/bulls
rm "$fixture/enabled"
printf 'unrelated site\n' > "$fixture/enabled"
if bulls_check_enabled_site "$fixture/enabled" /etc/nginx/sites-available/bulls; then
    echo 'Must reject regular nginx enabled file' >&2; exit 1
fi
# Child-shell checks must expand only after env cleanup.
# shellcheck disable=SC2016
DB_URL=untrusted APP_KEY=untrusted APP_ENV=untrusted ADMIN_PASSWORD=untrusted bulls_clean_exec /bin/sh -c 'test -z "${DB_URL+x}" && test -z "${APP_KEY+x}" && test -z "${APP_ENV+x}" && test -z "${ADMIN_PASSWORD+x}" && test "$PATH" = /usr/local/bin:/usr/bin:/bin'
printf 'Setup filesystem/environment regressions passed.\n'
