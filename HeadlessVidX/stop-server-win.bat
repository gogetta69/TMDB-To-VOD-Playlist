@echo off
REM Change to the directory of the script
cd /d %~dp0

REM Stop the server with PM2
pm2 stop HeadlessVidX

if %errorlevel% neq 0 (
    echo Failed to stop the Node.js server with PM2. Exiting.
    pause
    exit /b 1
)

echo Server stopped successfully.
pause