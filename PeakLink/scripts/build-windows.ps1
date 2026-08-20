$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot\..
python -m pip install -e ".[desktop]"
python -m pip install pyinstaller
python -m PyInstaller --noconfirm --windowed --name PeakLink `
  --hidden-import pynput.keyboard._win32 `
  --hidden-import pynput.mouse._win32 `
  --hidden-import mss `
  --collect-all peaklink `
  peaklink/launcher.py
python -m PyInstaller --noconfirm --console --name PeakLinkServer `
  --collect-all peaklink `
  peaklink/server/app.py
Write-Host "已輸出 dist\PeakLink.exe 與 dist\PeakLinkServer.exe"
