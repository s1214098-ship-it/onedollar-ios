param(
  [string]$TaskName = "HuowangYungchingPoolsSync",
  [string]$WebRoot = "F:\Web\huowang-staging",
  [string]$User = "Administrator",
  [string]$Password = ""
)

$ErrorActionPreference = "Stop"
$runner = Join-Path $WebRoot "api\sync-yungching-pools-run.ps1"
if (!(Test-Path -LiteralPath $runner)) {
  throw "Runner not found: $runner"
}

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

if ($Password) {
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

$times = @((Get-ScheduledTask -TaskName $TaskName).Triggers | ForEach-Object { $_.StartBoundary })
Write-Host "Installed $TaskName"
Write-Host ("Times: " + ($times -join ", "))
Write-Host ("LogonType: " + (Get-ScheduledTask -TaskName $TaskName).Principal.LogonType)
Write-Host ("StopOnIdleEnd: " + (Get-ScheduledTask -TaskName $TaskName).Settings.IdleSettings.StopOnIdleEnd)
