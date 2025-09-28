@echo off
REM ─────────────────────────────────────────────────────────────
REM  Uninstall "HeadlessVidX" service (NSSM)
REM ─────────────────────────────────────────────────────────────
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo This script needs Administrator privileges.
    echo Please run as Administrator or the script will attempt to elevate...
    powershell -Command "Start-Process '%~f0' -Verb RunAs"
    exit /b
)

set PORT=3202
set SERVICE_NAME=HeadlessVidX

:: Detect system architecture and set the correct nssm path
if "%PROCESSOR_ARCHITECTURE%"=="AMD64" (
    set NSSM_PATH=%~dp0nssm\win64\nssm.exe
) else (
    set NSSM_PATH=%~dp0nssm\win32\nssm.exe
)

:: Double-check that nssm.exe exists
if not exist "%NSSM_PATH%" (
    echo ERROR: nssm.exe not found in %NSSM_PATH%.
    exit /b
)

:: Stop the service if it's running using nssm
echo Stopping %SERVICE_NAME% service...
"%NSSM_PATH%" stop %SERVICE_NAME% >nul 2>&1

:: Remove the service using nssm
echo Removing %SERVICE_NAME% service...
"%NSSM_PATH%" remove %SERVICE_NAME% confirm

:: Check if the service was removed
if %errorlevel%==0 (
    echo Service %SERVICE_NAME% removed successfully.
) else (
    echo Failed to remove %SERVICE_NAME% service.
)

:: Remove the port from Windows Firewall
echo Removing firewall rule for port %PORT%...
netsh advfirewall firewall delete rule name="%SERVICE_NAME% %PORT%" protocol=TCP localport=%PORT%

:: Check if the firewall rule was removed
if %errorlevel%==0 (
    echo Firewall rule for port %PORT% removed successfully.
) else (
    echo Failed to remove firewall rule for port %PORT%.
)

pause