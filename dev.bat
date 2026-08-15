@echo off
setlocal

if "%1"=="" goto up
if "%1"=="up" goto up
if "%1"=="down" goto down
if "%1"=="stop" goto down
if "%1"=="reset" goto reset
if "%1"=="logs" goto logs
if "%1"=="shell" goto shell
echo Unknown command: %1
echo Usage: dev.bat [up^|down^|reset^|logs^|shell]
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

:end
endlocal
