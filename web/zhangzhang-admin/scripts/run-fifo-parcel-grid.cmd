@echo off
setlocal
"C:\Program Files\nodejs\node.exe" "F:\Web\lingzanzan-staging\scripts\test-lingzanzan-fifo-parcel-grid.js"
if errorlevel 1 exit /b 1
"C:\Program Files\nodejs\node.exe" "F:\Web\lingzanzan-staging\scripts\fix-lingzanzan-fifo-parcel-grid.js"
