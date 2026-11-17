#!/bin/sh
# Starts everything the app needs in one container: migrations, the queue
# worker, the scheduler and the web server. The two background loops restart
# their process if it ever exits.
set -e

export SERVER_NAME=":${PORT:-10000}"

php artisan migrate --force --no-interaction
# Creates or updates the operators' account when ADMIN_EMAIL and ADMIN_PASSWORD are set.
php artisan pixelforge:admin || true
php artisan optimize

( while true; do php artisan queue:work --sleep=1 --tries=3 --max-time=3600 || true; sleep 1; done ) &
( while true; do php artisan schedule:work || true; sleep 1; done ) &

exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
