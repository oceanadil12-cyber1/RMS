@echo off
rem Serves this project folder (the folder above .claude) on port 3000.
for %%I in ("%~dp0..") do set "ROOT=%%~fI"
"C:\Users\20607\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe" -S 0.0.0.0:3000 -t "%ROOT%" "%ROOT%\.router.php"
