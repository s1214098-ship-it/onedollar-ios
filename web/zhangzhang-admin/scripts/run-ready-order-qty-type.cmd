@echo off
setlocal
"C:\Program Files\nodejs\node.exe" "F:\Web\lingzanzan-staging\scripts\test-lingzanzan-ready-order-qty-type.js"
if errorlevel 1 exit /b 1
"C:\Program Files\nodejs\node.exe" "F:\Web\lingzanzan-staging\scripts\fix-lingzanzan-ready-order-qty-type.js"
