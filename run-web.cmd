@echo off
setlocal

set "PHP_BIN=D:\XAMPP\php\php.exe"
if not exist "%PHP_BIN%" set "PHP_BIN=php"

cd /d "%~dp0public"
"%PHP_BIN%" -S 127.0.0.1:8000 "%~dp0vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php"
