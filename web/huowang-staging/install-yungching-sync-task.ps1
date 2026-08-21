param(
  [string]$TaskName = "HuowangYungchingPoolsSync",
  [string]$EnsureTaskName = "HuowangYungchingPoolsSyncEnsure",
  [string]$WebRoot = "F:\Web\huowang-staging",
  [string]$User = "Administrator",
  [string]$Password = ""
)

$ErrorActionPreference = "Stop"
$runner = @(
  (Join-Path $WebRoot "api\sync-yungching-pools-run.ps1"),
  (Join-Path $WebRoot "sync-yungching-pools-run.ps1")
) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
if (-not $runner) {
  throw "Runner not found under $WebRoot"
}

$ensure = @(
  (Join-Path $WebRoot "api\ensure-yungching-sync-task.ps1"),
  (Join-Path $WebRoot "ensure-yungching-sync-task.ps1")
) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1

$arg = '-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "' + $runner + '" -WebRoot "' + $WebRoot + '"'
$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument $arg
$noon = New-ScheduledTaskTrigger -Daily -At "12:00"
$evening = New-ScheduledTaskTrigger -Daily -At "18:00"
$settings = New-ScheduledTaskSettingsSet `
  -StartWhenAvailable `
  -MultipleInstances IgnoreNew `
  -AllowStartIfOnBatteries `
  -DontStopIfGoingOnBatteries `
  -DontStopOnIdleEnd `
  -Hidden `
  -RestartCount 1 `
  -RestartInterval (New-TimeSpan -Minutes 5) `
  -ExecutionTimeLimit (New-TimeSpan -Hours 2)

$existing = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($existing) {
  Enable-ScheduledTask -TaskName $TaskName | Out-Null
  Write-Host "Kept existing $TaskName (already scheduled daily 12:00 and 18:00)"
} elseif ($Password) {
  Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger @($noon, $evening) `
    -Settings $settings `
    -Description "Huowang backend listings daily 12:00 and 18:00 from Yungching public pages" `
    -RunLevel Highest `
    -User $User `
    -Password $Password `
    -Force | Out-Null
} else {
  Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger @($noon, $evening) `
    -Settings $settings `
    -Description "Huowang backend listings daily 12:00 and 18:00 from Yungching public pages" `
    -RunLevel Highest `
    -User $User `
    -Force | Out-Null
}

if ($ensure) {
  $ensureArg = '-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "' + $ensure + '" -WebRoot "' + $WebRoot + '"'
  $ensureAction = New-ScheduledTaskAction -Execute "powershell.exe" -Argument $ensureArg
  $boot = New-ScheduledTaskTrigger -AtStartup
  $ensureSettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -DontStopOnIdleEnd -Hidden
  $ensureExisting = Get-ScheduledTask -TaskName $EnsureTaskName -ErrorAction SilentlyContinue
  if ($ensureExisting) {
    Set-ScheduledTask -TaskName $EnsureTaskName -Action $ensureAction -Trigger $boot -Settings $ensureSettings | Out-Null
  } else {
    Register-ScheduledTask `
      -TaskName $EnsureTaskName `
      -Action $ensureAction `
      -Trigger $boot `
      -Settings $ensureSettings `
      -Description "Re-enable Huowang 12:00/18:00 listing sync after reboot" `
      -RunLevel Highest `
      -User $User `
      -Force | Out-Null
  }
}

$times = @((Get-ScheduledTask -TaskName $TaskName).Triggers | ForEach-Object { $_.StartBoundary })
Write-Host "Installed $TaskName"
Write-Host ("Times: " + ($times -join ", "))
Write-Host ("LogonType: " + (Get-ScheduledTask -TaskName $TaskName).Principal.LogonType)
Write-Host ("StopOnIdleEnd: " + (Get-ScheduledTask -TaskName $TaskName).Settings.IdleSettings.StopOnIdleEnd)
Write-Host ("EnsureTask: " + $EnsureTaskName)
