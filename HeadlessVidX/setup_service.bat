@echo off
setlocal enabledelayedexpansion

:: Check for administrative privileges
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo This script needs Administrator privileges.
    echo Please run as Administrator or the script will attempt to elevate...
    powershell -Command "Start-Process '%~f0' -Verb RunAs"
    exit /b
)

set PORT=3202
set SERVICE_NAME=HeadlessVidX
set SERVICE_DISPLAY_NAME="HeadlessVidX"
set EXE_PATH="%~dp0HeadlessVidX.exe"
set IPAddress=localhost
set GatewayIP=

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

:: ─────────────────────────────────────────────────────────────
::  REQUIRE USER ACCOUNT + CHROME  ➜  prompt + validation block
:: ─────────────────────────────────────────────────────────────

echo.
echo ==========================================================
echo  HeadlessVidX Service Setup
echo ----------------------------------------------------------
echo Installing HeadlessVidX as a service requires a Windows account
echo so that Playwright (the browser automation tool) can locate an
echo installed version of Google Chrome. Please enter your Windows credentials.
echo ----------------------------------------------------------
echo.

:: ----------------------------------------------------------
::  prompt for credentials
:: ----------------------------------------------------------
set /p SERVICE_USER=Enter your Windows Username: 
set /p SERVICE_PASS=Enter your Windows Password: 

echo.
echo Installing/Updating the service to run as "%SERVICE_USER%"

:: ----------------------------------------------------------
::  service-exists check  – run BEFORE credential assignment
:: ----------------------------------------------------------
sc query "%SERVICE_NAME%" >nul 2>&1
if %errorlevel%==0 (
    echo.
    echo WARNING: A "%SERVICE_NAME%" service is already installed.
    echo Please run  remove_service.bat  to uninstall it first.
    echo If the error still persists after removal, reboot Windows to
    echo clear any lingering "marked for deletion" state, then rerun
    echo this setup script.
    pause
    exit /b 1
)

:: ----------------------------------------------------------
::  install service if missing
:: ----------------------------------------------------------
if not exist "%EXE_PATH%" (
    echo ERROR: HeadlessVidX.exe not found at %EXE_PATH%
    pause
    exit /b 1
)

"%NSSM_PATH%" install %SERVICE_NAME% %EXE_PATH%   >nul 2>&1

:: ----------------------------------------------------------
::  try LOCAL account first  --> .\username
:: ----------------------------------------------------------
set "FULL_USER=.\%SERVICE_USER%"
echo Attempting local account %FULL_USER% ...
"%NSSM_PATH%" set "%SERVICE_NAME%" ObjectName "%FULL_USER%" "%SERVICE_PASS%" >nul 2>&1

if %errorlevel% neq 0 (
    echo Local account failed.
    choice /m "Is this a domain account"
    if errorlevel 2 (
        echo ERROR: Could not configure service with local account.
        echo Make sure the account exists and has 'Log on as a service'.
        pause
        exit /b 1
    )

    :: -------- get domain and retry --------
    set /p DOMAIN=Enter your DOMAIN name: 
    set "FULL_USER=%DOMAIN%\%SERVICE_USER%"
    echo Attempting domain account %FULL_USER% ...
    "%NSSM_PATH%" set %SERVICE_NAME% ObjectName "%FULL_USER%" "%SERVICE_PASS%" >nul 2>&1

    if %errorlevel% neq 0 (
        echo ERROR: Could not configure service with %FULL_USER%.
        echo Make sure the account exists and has 'Log on as a service'.
        pause
        exit /b 1
    )
)

echo Service credentials assigned to %FULL_USER%.

echo Chrome detected – continuing …

:: Loop through all network interfaces and their default gateways
for /f "tokens=2 delims=:" %%A in ('ipconfig ^| findstr /i "Default Gateway"') do (
    set GatewayIP=%%A
    for /f "tokens=* delims= " %%B in ("!GatewayIP!") do set GatewayIP=%%B
    
    :: If the gateway is valid (not empty)
    if not "!GatewayIP!"=="" (
        rem Extract the first three octets of the gateway IP
        for /f "tokens=1-3 delims=." %%A in ("!GatewayIP!") do (
            set GatewayRange=%%A.%%B.%%C.
        )

        rem Find the IP address that matches the gateway range
        for /f "tokens=2 delims=:" %%A in ('ipconfig ^| findstr /i "IPv4"') do (
            set TempIP=%%A
            for /f "tokens=* delims= " %%B in ("!TempIP!") do set TempIP=%%B
            
            rem Extract the first three octets of the current IP
            for /f "tokens=1-3 delims=." %%C in ("!TempIP!") do (
                set CurrentRange=%%C.%%D.%%E.
                
                rem Compare it with the gateway range
                if "!CurrentRange!"=="!GatewayRange!" (
                    set IPAddress=!TempIP!
                )
            )
        )
    )
)

rem If no matching IP is found, fallback to localhost
if "!IPAddress!"=="localhost" (
    echo No matching IP found, falling back to localhost.
) else (
    echo Using local IP address: !IPAddress!
)

:: Add the port to Windows Firewall
echo Adding port %PORT% to Windows Firewall...
netsh advfirewall firewall add rule name="%SERVICE_NAME% %PORT%" protocol=TCP dir=in localport=%PORT% action=allow

if %errorlevel%==0 (
    echo Port %PORT% added to Windows Firewall successfully.
) else (
    echo Failed to add port %PORT% to Windows Firewall.
    exit /b
)

:: Set the service to start automatically on boot
"%NSSM_PATH%" set %SERVICE_NAME% Start SERVICE_AUTO_START

:: Start the service
echo Starting the service...
"%NSSM_PATH%" start %SERVICE_NAME%

:: Add a delay to give the service time to transition from START_PENDING to RUNNING
timeout /t 5 /nobreak >nul

:: Check the service status
sc query %SERVICE_NAME% | findstr /I /C:"RUNNING" >nul
if %errorlevel%==0 (
    echo Service %SERVICE_NAME% started successfully.
) else (
    echo Failed to start service %SERVICE_NAME%. Ensure the executable exists at %EXE_PATH%.
    pause
    exit /b
)

:: Display the correct URL based on the IP address found or fallback
echo The server is running at: http://!IPAddress!:%PORT%

:: Open the URL in the default browser
start http://!IPAddress!:%PORT%

pause
