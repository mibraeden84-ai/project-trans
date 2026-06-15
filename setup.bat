@echo off
REM Translink GPS Library - PostgreSQL Setup (Windows)

echo.
echo ========================================
echo   Translink GPS Library - Setup
echo ========================================
echo.

set "PHP_BIN=php"
where php >nul 2>nul
if %ERRORLEVEL% NEQ 0 (
    if exist "C:\xampp\php\php.exe" (
        set "PHP_BIN=C:\xampp\php\php.exe"
    ) else (
        echo [ERROR] PHP not found in PATH.
        echo   Use full path: C:\xampp\php\php.exe install.php
        pause
        exit /b 1
    )
)

"%PHP_BIN%" -m | findstr /I "pdo_pgsql" >nul
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] PHP extension pdo_pgsql is not enabled.
    echo   In XAMPP, edit C:\xampp\php\php.ini and enable:
    echo   extension=pdo_pgsql
    pause
    exit /b 1
)

echo [1/3] Installing PostgreSQL database...
"%PHP_BIN%" install.php
if %ERRORLEVEL% NEQ 0 (
    echo.
    echo [ERROR] Installation failed.
    echo   - Is PostgreSQL running on port 5432?
    echo   - Are DB_USER and DB_PASS correct in config.php or environment variables?
    pause
    exit /b 1
)

echo [2/3] Starting development server...
echo   Site: http://localhost:8000
echo   Admin: http://localhost:8000/admin/login.php
echo   Login: admin / password
echo.
start http://localhost:8000
"%PHP_BIN%" -S 0.0.0.0:8000 -t "%~dp0"
