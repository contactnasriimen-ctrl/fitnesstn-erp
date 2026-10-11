@echo off
title ERP FitnessTN
cd /d "%~dp0"
set PHP=C:\xampp\php\php.exe
if not exist "%PHP%" set PHP=php
echo.
echo   ERP FitnessTN  -  http://localhost:8093
echo   Gardez cette fenetre ouverte pendant l'utilisation (Ctrl+C pour arreter).
echo.
start "" http://localhost:8093
"%PHP%" -S localhost:8093 router.php
