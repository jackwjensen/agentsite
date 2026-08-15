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
  *)
    echo "Unknown command: $cmd"
    echo "Usage: ./dev.sh [up|down|reset|logs|shell]"
    exit 1
    ;;
esac
