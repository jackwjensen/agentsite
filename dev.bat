@echo off
setlocal

if "%1"=="" goto up
if "%1"=="up" goto up
if "%1"=="down" goto down
if "%1"=="stop" goto down
if "%1"=="reset" goto reset
if "%1"=="logs" goto logs
if "%1"=="shell" goto shell
if "%1"=="module" goto module
if "%1"=="check" goto check
echo Unknown command: %1
echo Usage: dev.bat [up^|down^|reset^|logs^|shell^|check^|module ...]
goto end

:up
echo Starting agentsite...
docker compose up --build -d
echo.
echo Site: http://localhost:8080
echo.
goto end

:down
docker compose down
goto end

:reset
docker compose down
docker compose up --build -d
echo.
echo Rebuild complete: http://localhost:8080
goto end

:logs
docker compose logs -f web
goto end

:shell
docker compose exec web sh
goto end

:module
rem The installer writes files across the whole repo (docker-compose.override.yml,
rem .env), so it runs in a throwaway container with the repo root mounted rather
rem than in the web container, which only mounts the site's own directories.
docker run --rm -v "%CD%:/repo" -w /repo php:8.3-cli-alpine php scripts/module.php %2 %3 %4 %5
goto end

:check
rem The same gates CI runs.
docker run --rm -v "%CD%:/repo" -w /repo php:8.3-cli-alpine sh -c "sh scripts/lint-php.sh && php scripts/validate-content.php && php scripts/check-image-budget.php"
goto end

:end
endlocal
