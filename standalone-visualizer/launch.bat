@echo off
echo ===================================================
echo   SimpleMCP 3D Memory Visualizer Launcher
echo ===================================================
echo.
echo Starting local web server on port 8765...
start "" "http://localhost:8765/index.html"
php -S localhost:8765
pause
