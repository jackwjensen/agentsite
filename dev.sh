#!/bin/sh
# Development helper — mirror of dev.bat for macOS/Linux.
set -e

cmd="${1:-up}"

case "$cmd" in
  up)
    echo "Starting agentsite..."
    docker compose up --build -d
    echo
    echo "Site: http://localhost:8080"
    ;;
  down|stop)
    docker compose down
    ;;
  reset)
    docker compose down
    docker compose up --build -d
    echo
    echo "Rebuild complete: http://localhost:8080"
    ;;
  logs)
    docker compose logs -f web
    ;;
  shell)
    docker compose exec web sh
    ;;
  module)
    # The installer writes files across the whole repo
    # (docker-compose.override.yml, .env), so it runs in a throwaway container
    # with the repo root mounted rather than in the web container, which only
    # mounts the site's own directories.
    shift
    docker run --rm -v "$(pwd):/repo" -w /repo php:8.3-cli-alpine php scripts/module.php "$@"
    ;;
  check)
    # The same gates CI runs.
    docker run --rm -v "$(pwd):/repo" -w /repo php:8.3-cli-alpine sh -c \
      'sh scripts/lint-php.sh && php scripts/validate-content.php && php scripts/check-image-budget.php && php scripts/check-doc-links.php'
    ;;
  *)
    echo "Unknown command: $cmd"
    echo "Usage: ./dev.sh [up|down|reset|logs|shell|check|module ...]"
    exit 1
    ;;
esac
