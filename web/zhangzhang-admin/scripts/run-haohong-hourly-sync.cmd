@echo off
setlocal
set ROOT=U:\Web\lingzanzan-staging
if exist F:\Web\lingzanzan-staging\scripts\haohong-hourly-sync.js set ROOT=F:\Web\lingzanzan-staging
set LOGDIR=%ROOT%\data
if exist F:\Logs set LOGDIR=F:\Logs
if not exist "%LOGDIR%" mkdir "%LOGDIR%"
"C:\Program Files\nodejs\node.exe" "%ROOT%\scripts\haohong-hourly-sync.js" >> "%LOGDIR%\lingzanzan-haohong-hourly-sync.log" 2>&1
