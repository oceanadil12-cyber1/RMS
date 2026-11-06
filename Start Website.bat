@echo off
rem Opens the RMS website on this computer.
rem Uses PHP (XAMPP) when available so the menu, ordering and forms work;
rem otherwise falls back to a static preview with Node.js (npx serve).
title RMS - local website
cd /d "%~dp0"

set "PORT=3456"
set "PHP="
if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
if not defined PHP (
  where php >nul 2>nul && set "PHP=php"
)

rem Orders, messages and chats are kept in MySQL: start the local one (if installed
rem and not already running) in its own minimized window, so it outlives this one.
set "MYSQLD=D:\Adil\MySQL\mysql-8.4.11-winx64\bin\mysqld.exe"
if exist "%MYSQLD%" (
  netstat -an | find "127.0.0.1:3306" | find "LISTENING" >nul || start "MySQL" /min "%MYSQLD%" --defaults-file="D:\Adil\MySQL\my.ini"
)

echo.
echo  RMS website: http://localhost:%PORT%
echo  Keep this window open while you use the site. Close it to stop.
echo.

if defined PHP (
  echo  Mode: PHP server - live menu, ordering and forms enabled.
  echo  Admin panel: http://localhost:%PORT%/admin/
  echo.
  start "" http://localhost:%PORT%
  "%PHP%" -S localhost:%PORT% -t . .router.php
) else (
  echo  Mode: static preview - PHP not found, so the menu uses its saved
  echo  snapshot and the forms will not send. Install XAMPP for full mode.
  echo.
  start "" http://localhost:%PORT%
  npx --yes serve . -l %PORT% --no-clipboard
)
pause
