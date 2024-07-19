@echo off

REM Check for administrative privileges
openfiles >nul 2>nul
if %errorlevel% neq 0 (
    echo This script requires administrative privileges. Please run as Administrator.
    echo Press any key to exit...
    pause >nul
    exit /b 1
)

REM Define the URLs and script paths
set "NVM_SETUP_URL=https://github.com/coreybutler/nvm-windows/releases/download/1.1.9/nvm-setup.exe"
set "NVM_SETUP_EXE=%TEMP%\nvm-setup.exe"
set "INDEX_JS_PATH=%~dp0index.js"
set "PACKAGE_JSON_PATH=%~dp0package.json"

REM Function to check if a command is available
where curl >nul 2>nul
if %errorlevel% neq 0 (
    echo curl is not installed. Please install curl before proceeding.
    exit /b 1
)

REM Download and install NVM for Windows
echo Downloading NVM for Windows installer...
curl -Lo %NVM_SETUP_EXE% %NVM_SETUP_URL%
if %errorlevel% neq 0 (
    echo Failed to download NVM installer. Exiting.
    exit /b 1
)
echo Installing NVM for Windows...
start /wait %NVM_SETUP_EXE%
if %errorlevel% neq 0 (
    echo Failed to install NVM. Exiting.
    exit /b 1
)
del %NVM_SETUP_EXE%

REM Set environment variables for NVM and Node.js
setx NVM_HOME "%APPDATA%\nvm"
setx NVM_SYMLINK "%ProgramFiles%\nodejs"
set "NVM_HOME=%APPDATA%\nvm"
set "NVM_SYMLINK=%ProgramFiles%\nodejs"
set "PATH=%NVM_HOME%;%NVM_SYMLINK%;%PATH%"

REM Verify nvm installation
nvm -v >nul 2>nul
if %errorlevel% neq 0 (
    echo nvm installation not found after loading. Exiting.
    exit /b 1
)

REM Install the required Node.js version
echo Installing Node.js v20.13.1...
call nvm install 20.13.1
if %errorlevel% neq 0 (
    echo Failed to install Node.js v20.13.1. Exiting.
    exit /b 1
)
call nvm use 20.13.1
call nvm alias default 20.13.1

REM Verify node installation
call node -v >nul 2>nul
if %errorlevel% neq 0 (
    echo Node.js installation not found after updating PATH. Exiting.
    exit /b 1
)

REM Install dependencies from package.json
if exist "%PACKAGE_JSON_PATH%" (
    echo Installing dependencies from package.json...
    call npm install
    if %errorlevel% neq 0 (
        echo Failed to install dependencies from package.json. Exiting.
        echo Press any key to exit...
        pause >nul
        exit /b 1
    )
    echo Dependencies installed successfully.
) else (
    echo package.json not found in the script directory. Exiting.
    echo Press any key to exit...
    pause >nul
    exit /b 1
)

REM Install Playwright
echo Installing Playwright...
call npm install -g playwright
if %errorlevel% neq 0 (
    echo Failed to install Playwright. Exiting.
    exit /b 1
)

REM Install Playwright dependencies
echo Installing Playwright dependencies...
call npm install playwright install-deps
if %errorlevel% neq 0 (
    echo Failed to install Playwright dependencies. Exiting.
    exit /b 1
)

REM Install Playwright browsers
echo Installing Playwright browsers...
call npx playwright install
if %errorlevel% neq 0 (
    echo Failed to install Playwright browsers. Exiting.
    exit /b 1
)

REM Install PM2 globally
echo Installing PM2 globally...
call npm install -g pm2
if %errorlevel% neq 0 (
    echo Failed to install PM2. Exiting.
    exit /b 1
)

REM Install pm2-windows-startup
echo Installing pm2-windows-startup...
call npm install -g pm2-windows-startup
if %errorlevel% neq 0 (
    echo Failed to install pm2-windows-startup. Exiting.
    exit /b 1
)

REM Set up PM2 startup for Windows
echo Setting up PM2 startup for Windows...
call pm2-startup install
if %errorlevel% neq 0 (
    echo Failed to set up PM2 startup for Windows. Exiting.
    exit /b 1
)

REM Start your Node.js server with PM2 and a custom name
echo Starting your Node.js server with PM2...
call pm2 start index.js --name HeadlessVidX
if %errorlevel% neq 0 (
    echo Failed to start the Node.js server with PM2. Exiting.
    exit /b 1
)

REM Save the PM2 process list
echo Saving the PM2 process list...
call pm2 save
if %errorlevel% neq 0 (
    echo Failed to save the PM2 process list. Exiting.
    exit /b 1
)

REM Set up PM2 to restart on reboot using Windows Task Scheduler
echo Setting up PM2 to restart on reboot using Task Scheduler...
SCHTASKS /CREATE /SC ONSTART /TN "PM2 Startup" /TR "\"%NVM_SYMLINK%\pm2.cmd\" resurrect" /RU SYSTEM
if %errorlevel% neq 0 (
    echo Failed to create Task Scheduler job for PM2. Exiting.
    exit /b 1
)

echo Installation and PM2 setup completed successfully.
pause
