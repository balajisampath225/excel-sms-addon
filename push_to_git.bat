@echo off
echo Installing Git and pushing code to GitHub...
echo.

REM Check if git is installed
git --version >nul 2>&1
if %errorlevel% neq 0 (
    echo Git is not installed. Please install Git first:
    echo 1. Go to https://git-scm.com/download/win
    echo 2. Download and install Git for Windows
    echo 3. Run this script again
    pause
    exit /b 1
)

echo Git is installed. Proceeding with push...
echo.

REM Initialize git repository
git init

REM Add remote origin
git remote add origin https://github.com/balajisampath225/excel-sms-addon.git

REM Add all files
git add .

REM Commit files
git commit -m "Initial commit - Excel SMS Add-in"

REM Push to GitHub
git branch -M main
git push -u origin main

echo.
echo Code pushed successfully to GitHub!
echo Repository: https://github.com/balajisampath225/excel-sms-addon
pause