# 在「你的電腦」執行，把同意書複製到 NAS。
# 正確位置：\\100.97.127.26\火旺資料\AI轉檔存放區\合約書格式\

$ErrorActionPreference = 'Stop'
$dest = '\\100.97.127.26\火旺資料\AI轉檔存放區\合約書格式'
New-Item -ItemType Directory -Force -Path $dest | Out-Null

$base = 'https://raw.githubusercontent.com/s1214098-ship-it/onedollar-ios/cursor/property-sale-agreement-1867/AI%E8%BD%89%E6%AA%94%E5%AD%98%E6%94%BE%E5%8D%80/%E5%90%88%E7%B4%84%E6%9B%B8%E6%A0%BC%E5%BC%8F'
$files = @(
  '房屋及土地出售價款分配同意書.pdf',
  '房屋及土地出售價款分配同意書.docx',
  '房屋及土地出售價款分配同意書.html'
)

foreach ($f in $files) {
  $out = Join-Path $dest $f
  Write-Host "下載 $f"
  Invoke-WebRequest -Uri "$base/$([uri]::EscapeDataString($f))" -OutFile $out
}

Write-Host "完成：$dest"
explorer $dest
