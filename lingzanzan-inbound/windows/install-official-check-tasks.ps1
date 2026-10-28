#Requires -RunAsAdministrator
# Import 12:00 and 15:00 Asia/Taipei official-check tasks. Disable leftover 18:00 triggers.
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path

Write-Host 'Existing tasks matching logistics / carrier / 12:00 / 18:00:'
schtasks /Query /FO LIST /V | Select-String -Pattern 'LZ-Official|carrier-thorough|scheduled-run|物流|18:00|12:00' | ForEach-Object { $_.Line }

foreach ($name in @('LZ-OfficialCheck-1200', 'LZ-OfficialCheck-1500')) {
  schtasks /Delete /TN $name /F 2>$null | Out-Null
}

schtasks /Create /TN 'LZ-OfficialCheck-1200' /XML (Join-Path $here 'LZ-OfficialCheck-1200.xml')
schtasks /Create /TN 'LZ-OfficialCheck-1500' /XML (Join-Path $here 'LZ-OfficialCheck-1500.xml')

$scriptsDestCandidates = @(
  'C:\PHT-Web\lingzanzan-staging\scripts',
  'D:\PHT-Web\lingzanzan-staging\scripts'
)
$dest = $scriptsDestCandidates | Where-Object { Test-Path (Split-Path $_ -Parent) } | Select-Object -First 1
if ($dest) {
  New-Item -ItemType Directory -Force -Path $dest | Out-Null
  Copy-Item -Force (Join-Path $here 'lz-official-check-slot.ps1') (Join-Path $dest 'lz-official-check-slot.ps1')
  Write-Host "Copied slot script to $dest"
} else {
  Write-Warning 'lingzanzan-staging not on C: or D:. Copy lz-official-check-slot.ps1 next to carrier-tracking-scheduled-run.php\scripts\ yourself, and edit the XML <Arguments> path.'
}

Write-Host @'
Done. Confirm:
  schtasks /Query /TN LZ-OfficialCheck-1200
  schtasks /Query /TN LZ-OfficialCheck-1500
Disable any old 18:00 carrier task. Claude schedule 12:00/16:00 is separate — turn it off or retitle to 12:00/15:00.
'@
