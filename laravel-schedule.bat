@echo off
cd /d C:\xampp\htdocs\my-laravel-app
C:\xampp\php\php.exe artisan schedule:run >> schedule.log 2>&1
