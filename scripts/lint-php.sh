#!/bin/sh
# Lint every PHP file (except vendored Parsedown) with php -l.
# Exit 1 if any file fails. Used locally and by CI.
set -e
cd "$(dirname "$0")/.."

status=0
for f in $(find lib templates config public scripts modules -name '*.php' 2>/dev/null | grep -v 'Parsedown.php' | sort); do
  if ! php -l "$f" > /dev/null 2>&1; then
    echo "SYNTAX ERROR: $f"
    php -l "$f" 2>&1 | head -3
    status=1
  fi
done

if [ "$status" -eq 0 ]; then
  echo "php -l: all files clean"
fi
exit $status
