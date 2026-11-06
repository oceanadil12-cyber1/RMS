@echo off
rem Saves all changes in this folder to the private GitHub repository.
rem Double-click after editing the website yourself.
title Save RMS website to GitHub
cd /d "%~dp0"

git add -A
rem Never upload secrets or customer data (also covered by .gitignore).
git reset -q -- api/config.php api/data api/cache 2>nul

git diff --cached --quiet
if errorlevel 1 (
  git commit -q -m "Update website (manual save)"
  echo  Changes committed.
) else (
  echo  No new changes to save.
)

git push -q origin main
if errorlevel 1 (
  echo.
  echo  Push to GitHub FAILED - check your internet connection or GitHub login.
) else (
  echo  Saved to GitHub: https://github.com/oceanadil12-cyber1/rms
)
echo.
pause
