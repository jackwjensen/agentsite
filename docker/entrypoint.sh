#!/bin/sh
# Runs as root before supervisord starts. Ensures the runtime-writable
# _logs/ directory exists with www-data ownership — bind mounts on a fresh
# host come up root-owned, which would block PHP-FPM (running as www-data)
# from creating subdirectories in it. Modules create their own subdirs
# (mail/, ratelimit/, admin/…) at runtime once the root is writable.
# Idempotent — safe to re-run on every container start.
set -e

mkdir -p /var/www/html/_logs
chown -R www-data:www-data /var/www/html/_logs

exec "$@"
