@echo off
echo Removing Docker files and updating repository...

git add .
git commit -m "Remove Docker files, use native PHP deployment"
git push origin main

echo Repository updated! Now redeploy on Render.
pause