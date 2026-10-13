@echo off
rem Inicia el servidor portable de Moodle (Windows).
setlocal
set "ROOT=%~dp0"
set "PHPRC=%ROOT%runtime\windows\php"
cd /d "%ROOT%runtime\windows\php"
"%ROOT%runtime\windows\php\php.exe" "%ROOT%server\start.php"
if errorlevel 1 pause
