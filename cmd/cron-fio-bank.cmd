@echo off
REM cron-fio-bank.cmd — stahovani bankovnich pohybu z Fio API
setlocal
set "PROJECT_ROOT=%~dp0.."
set "LOG_DIR=%PROJECT_ROOT%\log"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set TODAY=%%i
php "%PROJECT_ROOT%\api\bin\cron-fio-bank.php" %* >> "%LOG_DIR%\fio-bank-%TODAY%.log" 2>&1
