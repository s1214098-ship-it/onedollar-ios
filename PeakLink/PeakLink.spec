# -*- mode: python ; coding: utf-8 -*-
from pathlib import Path

from PyInstaller.utils.hooks import collect_all, collect_submodules

root = Path(SPECPATH)
datas, binaries, hidden = [], [], []
for pkg in ("peaklink", "uvicorn", "fastapi", "starlette", "websockets", "cryptography", "pydantic", "anyio", "httpx"):
    try:
        d, b, h = collect_all(pkg)
        datas += d
        binaries += b
        hidden += h
    except Exception:
        hidden += collect_submodules(pkg)

hidden += [
    "pynput.keyboard._win32",
    "pynput.mouse._win32",
    "mss",
    "PIL._tkinter_finder",
    "uvicorn.logging",
    "uvicorn.loops.auto",
    "uvicorn.loops.asyncio",
    "uvicorn.protocols.http.auto",
    "uvicorn.protocols.http.h11_impl",
    "uvicorn.protocols.websockets.auto",
    "uvicorn.protocols.websockets.websockets_impl",
    "uvicorn.lifespan.on",
    "peaklink.host.gui",
    "peaklink.viewer.gui",
    "peaklink.server.app",
    "peaklink.dashboard",
    "peaklink.boot",
]

icon = str(root / "packaging" / "peaklink.ico")
version = str(root / "packaging" / "file_version_info.txt")
if Path(icon).exists():
    datas.append((icon, "packaging"))

a = Analysis(
    [str(root / "peaklink" / "launcher.py")],
    pathex=[str(root)],
    binaries=binaries,
    datas=datas,
    hiddenimports=hidden,
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=["uvloop"],
    noarchive=False,
)
pyz = PYZ(a.pure)
exe = EXE(
    pyz,
    a.scripts,
    [],
    exclude_binaries=True,
    name="PeakLink",
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=False,
    console=False,
    disable_windowed_traceback=False,
    argv_emulation=False,
    icon=icon,
    version=version,
)
coll = COLLECT(
    exe,
    a.binaries,
    a.datas,
    strip=False,
    upx=False,
    upx_exclude=[],
    name="PeakLink",
)
