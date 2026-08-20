$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot\..

python -m pip install -U pip
python -m pip install -e ".[desktop]" pyinstaller

python packaging\generate_icon.py

python -m PyInstaller --noconfirm --clean PeakLink.spec

if (-not (Test-Path "dist\PeakLink\PeakLink.exe")) {
    throw "找不到 dist\PeakLink\PeakLink.exe"
}

$portable = "dist\PeakLink-Portable-1.0.0.zip"
if (Test-Path $portable) { Remove-Item $portable -Force }
Compress-Archive -Path "dist\PeakLink\*" -DestinationPath $portable

$iscc = "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe"
if (-not (Test-Path $iscc)) {
    $iscc = "${env:ProgramFiles}\Inno Setup 6\ISCC.exe"
}
if (Test-Path $iscc) {
    & $iscc "packaging\PeakLink.iss"
    Write-Host "已輸出 dist\PeakLink-Setup-1.0.0.exe"
} else {
    Write-Warning "未安裝 Inno Setup，略過 Setup.exe。請安裝後重跑，或使用 GitHub Actions 產製。"
}

Write-Host "已輸出 dist\PeakLink\PeakLink.exe 與 $portable"
