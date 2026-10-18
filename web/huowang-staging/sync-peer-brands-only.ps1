param(
  [string]$WebRoot = ""
)

$ErrorActionPreference = "Stop"
$Root = if ($WebRoot) { $WebRoot } else { Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path) }
if (!(Test-Path -LiteralPath (Join-Path $Root "tools\sync-peer-brands.js"))) {
  $Root = "F:\Web\huowang-staging"
}
$Script = Join-Path $Root "tools\sync-peer-brands.js"
$LogDir = Join-Path $Root "logs"
$LogFile = Join-Path $LogDir "peer-brands-sync.log"

if (!(Test-Path -LiteralPath $Script)) {
  throw "Peer brands sync script not found: $Script"
}
if (!(Test-Path -LiteralPath $LogDir)) {
  New-Item -Path $LogDir -ItemType Directory -Force | Out-Null
}

function Write-Log([string]$Message) {
  $line = "[{0}] {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
  Add-Content -LiteralPath $LogFile -Value $line -Encoding UTF8
  Write-Host $line
}

$node = @(
  (Get-Command node -ErrorAction SilentlyContinue).Source,
  "$env:ProgramFiles\nodejs\node.exe",
  "${env:ProgramFiles(x86)}\nodejs\node.exe"
) | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -First 1
if (-not $node) { throw "node.exe not found" }

$env:NODE_OPTIONS = "--max-old-space-size=8192"
Write-Log "runner start node=$node"
$prevEap = $ErrorActionPreference
$ErrorActionPreference = "Continue"
& cmd.exe /c "`"$node`" `"$Script`" 2>&1" | ForEach-Object {
  $line = "$_"
  if ($line.Trim()) { Write-Log $line.Trim() }
}
$code = $LASTEXITCODE
$ErrorActionPreference = $prevEap
Write-Log ("runner exit {0}" -f $code)
if ($code -ne 0) { exit $code }
