param(
  [string]$TaskName = "LingzanzanHaohongHourlySync",
  [string]$WebRoot = "F:\Web\lingzanzan-staging"
)

$ErrorActionPreference = "Stop"
$runner = Join-Path $WebRoot "scripts\run-haohong-hourly-sync.cmd"
$script = Join-Path $WebRoot "scripts\haohong-hourly-sync.js"
if (!(Test-Path -LiteralPath $runner)) { throw "Runner not found: $runner" }
if (!(Test-Path -LiteralPath $script)) { throw "Hourly sync not found: $script" }

Get-ScheduledTask -ErrorAction SilentlyContinue |
  Where-Object { $_.TaskName -match 'LingzanzanHaohongHourly' } |
  ForEach-Object {
    Write-Host ("Removing old task " + $_.TaskName)
    Unregister-ScheduledTask -TaskName $_.TaskName -Confirm:$false
  }

$action = New-ScheduledTaskAction -Execute $runner
# Start at the next :10 so it stays on the old 00:10 / 01:10 cadence, then repeat forever.
$start = (Get-Date).Date.AddMinutes(10)
if ($start -le (Get-Date)) { $start = $start.AddHours(1) }
$hourly = New-ScheduledTaskTrigger -Once -At $start -RepetitionInterval (New-TimeSpan -Hours 1) -RepetitionDuration (New-TimeSpan -Days 3650)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Hours 2)
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $hourly -Settings $settings -Description "LINGZANZAN HaoHong package and weight sync every hour. Does not change HaoHong website 集運." -RunLevel Highest -Force | Out-Null
$task = Get-ScheduledTask -TaskName $TaskName
$info = $task | Get-ScheduledTaskInfo
Write-Host ("Installed " + $task.TaskName + " hourly; next " + $info.NextRunTime)
