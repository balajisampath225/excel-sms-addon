@echo off
echo Pushing working Dockerfile to GitHub...

git add .
git commit -m "Add working Dockerfile for PHP deployment on Render"
git push origin main

echo Dockerfile updated! Now deploy on Render with Docker.
pause