@echo off
setlocal
set "PROJECT_DIR=%~dp0"
set "PHP_EXE=%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=php"
cd /d "%PROJECT_DIR%"
echo Feina Cerca arrancando en http://127.0.0.1:4173
echo Pulsa Ctrl+C para detener el servidor.
"%PHP_EXE%" -S 127.0.0.1:4173 -t "%PROJECT_DIR%" "%PROJECT_DIR%router.php"
pause


