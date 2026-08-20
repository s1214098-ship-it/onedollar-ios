param(
  [string]$WebRoot = ""
)

$ErrorActionPreference = "Stop"
$Root = if ($WebRoot) { $WebRoot } else { Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path) }
$Script = Join-Path $Root "tools\sync-yungching-pools.js"
$LogDir = Join-Path $Root "logs"
$LogFile = Join-Path $LogDir "yungching-pools-sync.log"
$LockFile = Join-Path $LogDir "yungching-pools-sync.lock"

if (!(Test-Path -LiteralPath $Script)) {
  throw "Sync script not found: $Script"
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
  Write-Log "start yungching pools sync"
  $node = @(
    (Get-Command node -ErrorAction SilentlyContinue).Source,
    "$env:ProgramFiles\nodejs\node.exe",
    "${env:ProgramFiles(x86)}\nodejs\node.exe"
  ) | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -First 1
  if (-not $node) { throw "node.exe not found" }
  Write-Log "node=$node"
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
  $peerScript = Join-Path $Root "tools\sync-peer-brands.js"
  if (Test-Path -LiteralPath $peerScript) {
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
  Write-Log "done"
} catch {
  Write-Log ("error: " + $_.Exception.Message)
  throw
} finally {
  if (Test-Path -LiteralPath $LockFile) {
    Remove-Item -LiteralPath $LockFile -Force
  }
}
