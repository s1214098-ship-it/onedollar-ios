param(
  [string]$WebRoot = ""
)

$ErrorActionPreference = "Stop"
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
if ($WebRoot) {
  $Root = $WebRoot
} elseif (Test-Path -LiteralPath (Join-Path $here "tools\sync-yungching-pools.js")) {
  $Root = $here
} elseif (Test-Path -LiteralPath (Join-Path (Split-Path -Parent $here) "tools\sync-yungching-pools.js")) {
  $Root = Split-Path -Parent $here
} else {
  $Root = "F:\Web\huowang-staging"
}

$Script = @(
  (Join-Path $Root "tools\sync-yungching-pools.js"),
  (Join-Path $Root "sync-yungching-pools.js")
) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1

$LogDir = Join-Path $Root "logs"
$LogFile = Join-Path $LogDir "yungching-pools-sync.log"
$LockFile = Join-Path $LogDir "yungching-pools-sync.lock"

if (-not $Script) {
  throw "Sync script not found under $Root"
}
if (!(Test-Path -LiteralPath $LogDir)) {
  New-Item -Path $LogDir -ItemType Directory -Force | Out-Null
}

function Write-Log([string]$Message) {
  $line = "[{0}] {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
  Add-Content -LiteralPath $LogFile -Value $line -Encoding UTF8
  Write-Host $line
}

function Get-LockPid([string]$Path) {
  if (!(Test-Path -LiteralPath $Path)) { return $null }
  $raw = Get-Content -LiteralPath $Path -Raw -ErrorAction SilentlyContinue
  if ($raw -match "pid=(\d+)") { return [int]$Matches[1] }
  return $null
}

if (Test-Path -LiteralPath $LockFile) {
  $lockPid = Get-LockPid $LockFile
  $alive = $false
  if ($lockPid) {
    $alive = [bool](Get-Process -Id $lockPid -ErrorAction SilentlyContinue)
  }
  $lockAge = (Get-Date) - (Get-Item -LiteralPath $LockFile).LastWriteTime
  if ($alive -and $lockAge.TotalHours -lt 2) {
    Write-Log "skip: previous sync still running pid=$lockPid ($([int]$lockAge.TotalMinutes) min)"
    exit 0
  }
  Write-Log "stale lock removed pid=$lockPid alive=$alive age=$([int]$lockAge.TotalMinutes) min"
  Remove-Item -LiteralPath $LockFile -Force
}

[System.IO.File]::WriteAllText($LockFile, ("pid={0}`nstarted={1}`n" -f $PID, (Get-Date -Format "o")))
try {
  Write-Log "start yungching pools sync (daily 12:00 and 18:00)"
  $node = @(
    (Get-Command node -ErrorAction SilentlyContinue).Source,
    "$env:ProgramFiles\nodejs\node.exe",
    "${env:ProgramFiles(x86)}\nodejs\node.exe"
  ) | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -First 1
  if (-not $node) { throw "node.exe not found" }
  Write-Log "node=$node script=$Script"
  $prevEap = $ErrorActionPreference
  $ErrorActionPreference = "Continue"
  & cmd.exe /c "`"$node`" `"$Script`" 2>&1" | ForEach-Object {
    $line = "$_"
    if ($line.Trim()) { Write-Log $line.Trim() }
  }
  $code = $LASTEXITCODE
  $ErrorActionPreference = $prevEap
  if ($code -ne 0) {
    throw "sync exited $code"
  }
  Write-Log "done yungching pools"
  $peerScript = @(
    (Join-Path $Root "tools\sync-peer-brands.js"),
    (Join-Path $Root "sync-peer-brands.js")
  ) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
  if ($peerScript) {
    $env:NODE_OPTIONS = "--max-old-space-size=8192"
    Write-Log "start peer brands sync"
    $ErrorActionPreference = "Continue"
    & cmd.exe /c "`"$node`" `"$peerScript`" 2>&1" | ForEach-Object {
      $line = "$_"
      if ($line.Trim()) { Write-Log $line.Trim() }
    }
    $peerCode = $LASTEXITCODE
    $ErrorActionPreference = $prevEap
    if ($peerCode -ne 0) {
      throw "peer brands sync exited $peerCode"
    }
    Write-Log "done peer brands"
  }
  Write-Log "done daily 12:00/18:00 listing update"
} catch {
  Write-Log ("error: " + $_.Exception.Message)
  throw
} finally {
  if (Test-Path -LiteralPath $LockFile) {
    Remove-Item -LiteralPath $LockFile -Force
  }
}
