#!/bin/sh
set -eu
umask 0002

cd "${1:?Pass the Laravel application directory}"
php_binary=${2:-php}
command -v "$php_binary" >/dev/null
command -v timeout >/dev/null
worker_pid=''
stopping=0
stop_worker() {
    stopping=1
    if [ -n "$worker_pid" ]; then
        kill -TERM "$worker_pid" 2>/dev/null || true
    fi
}
trap stop_worker TERM INT

while [ "$stopping" -eq 0 ]; do
    timeout --kill-after=15s 615s "$php_binary" artisan queue:work database \
        --queue=sheets --once --timeout=600 --tries=3 --backoff=60 \
        --sleep=0 --no-interaction &
    worker_pid=$!
    wait "$worker_pid" || true
    if [ "$stopping" -ne 0 ]; then
        wait "$worker_pid" 2>/dev/null || true
        exit 0
    fi
    worker_pid=''
    sleep 3 &
    worker_pid=$!
    wait "$worker_pid" || true
    worker_pid=''
done
