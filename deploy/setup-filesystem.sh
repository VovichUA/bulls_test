#!/bin/sh
# Small shared helpers: no top-level filesystem or service changes.
bulls_check_enabled_site() {
    if [ -e "$1" ] || [ -L "$1" ]; then
        [ -L "$1" ] && [ "$(readlink "$1")" = "$2" ] || return 1
    fi
}
bulls_harden_code() {
    app_path=$1
    code_owner=$2
    # Type predicates intentionally exclude symlinks, including public/storage.
    find "$app_path" -path "$app_path/storage" -prune -o -path "$app_path/bootstrap/cache" -prune -o -name .env -prune -o \
        \( -type f -o -type d \) -exec chown "$code_owner" {} + -exec chmod u=rwX,g=rX,o= {} +
}
bulls_clean_exec() {
    env -i PATH=/usr/local/bin:/usr/bin:/bin HOME=/var/lib/bulls USER=bulls LOGNAME=bulls LC_ALL=C.UTF-8 "$@"
}
bulls_wait_http() {
    # nginx reload is asynchronous; require HTTP 200 after bounded retries.
    http_status=$(curl --fail --silent --show-error --connect-timeout 2 --max-time 5 \
        --retry 5 --retry-delay 1 --retry-max-time 30 --retry-all-errors \
        -o /dev/null -w '%{http_code}' -H "Host: $1" "$2") || return 1
    [ "$http_status" = 200 ]
}
