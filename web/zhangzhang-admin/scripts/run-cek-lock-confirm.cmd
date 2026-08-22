@echo off
setlocal
set CEK_PAGES=F:\Web\lingzanzan-staging\scripts\lingzanzan-pages
"C:\Program Files\nodejs\node.exe" "F:\Web\lingzanzan-staging\scripts\test-lingzanzan-cek-lock-confirm.js"
if errorlevel 1 exit /b 1
"C:\Program Files\nodejs\node.exe" "F:\Web\lingzanzan-staging\scripts\fix-lingzanzan-cek-lock-confirm.js"
