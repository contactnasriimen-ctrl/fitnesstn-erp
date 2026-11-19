@echo off
rem Prépare l'ERP pour l'hébergement OVH dans D:\Projects\fitnesstn-erp-ovh (voir LISEZ-MOI.txt dans ce dossier).
cd /d "%~dp0"
set PHP=C:\xampp\php\php.exe
if not exist "%PHP%" set PHP=php
"%PHP%" tools\ovh_package.php
echo.
pause
