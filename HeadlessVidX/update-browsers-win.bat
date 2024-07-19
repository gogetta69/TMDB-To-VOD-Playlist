@echo off
echo Updating Playwright and browsers...
npm install playwright@latest
npx playwright install

echo Playwright and browsers updated successfully.
pause