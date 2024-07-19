@echo off
REM Change to the directory of the script
cd /d %~dp0

REM Start the server with PM2
pm2 start index.js --name HeadlessVidX

if %errorlevel% neq 0 (
    echo Failed to start the Node.js server with PM2. Exiting.
    pause
    exit /b 1
)

REM Wait a few seconds to allow the server to start and log the IP and port
timeout /t 5 /nobreak >nul

REM Open a new command prompt window to display the logs and keep it open
start "PM2 Logs" cmd /k "pm2 logs HeadlessVidX --lines 10"

echo Server started successfully.
pause
