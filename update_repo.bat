@echo off
echo Updating GitHub repository with fixed files...

git add .
git commit -m "Fix Dockerfile and render config for proper deployment"
git push origin main

echo Repository updated successfully!
pause