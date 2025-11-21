@echo off
echo Removing Docker files from GitHub repository...

REM Remove Docker files from git tracking
git rm --cached Dockerfile docker-compose.yml 2>nul

REM Add all current files
git add .

REM Commit the changes
git commit -m "Remove Docker files completely, use PHP native deployment"

REM Push to GitHub
git push origin main

echo Docker files removed from GitHub!
echo Now create a new Web Service on Render.
pause