@echo off
rem Builds "RMS Website - For Developer" next to this folder: the files to upload
rem to the web host (one ZIP, with the owner's settings inside) and the go-live guide.
rem Give that folder to the developer, privately: it holds the admin password and chat keys.
title RMS - make upload package
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0make-upload-package.ps1"
pause
