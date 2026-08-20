"""安全啟動：先藏視窗、設好標題，失敗就顯示錯誤而不是空白 tk。"""

from __future__ import annotations

import sys
import traceback
import tkinter as tk

from peaklink.config import app_data_dir
from peaklink.constants import APP_NAME
from peaklink.ui_theme import BG, TEXT, apply_window, ui_font


def _write_log(text: str) -> None:
    try:
        path = app_data_dir() / "startup.log"
        path.write_text(text, encoding="utf-8")
    except OSError:
        pass


def show_crash(root: tk.Misc, detail: str) -> None:
    _write_log(detail)
    for child in root.winfo_children():
        child.destroy()
    root.title(f"{APP_NAME} 啟動失敗")
    root.configure(bg=BG)
    box = tk.Frame(root, bg=BG)
    box.pack(fill="both", expand=True, padx=20, pady=20)
    tk.Label(box, text="畫面沒出來，錯誤如下：", bg=BG, fg=TEXT, font=ui_font(13, bold=True), anchor="w").pack(fill="x")
    tk.Label(box, text="可把這段文字傳給開發者。完整紀錄在 %APPDATA%\\PeakLink\\startup.log", bg=BG, fg=TEXT, font=ui_font(10), anchor="w", wraplength=640, justify="left").pack(fill="x", pady=(4, 8))
    txt = tk.Text(box, height=18, wrap="word", font=("Consolas", 10))
    txt.pack(fill="both", expand=True)
    txt.insert("1.0", detail)
    txt.configure(state="disabled")


def run_app(build) -> None:
    """build(root) 負責把介面畫上已存在的 Tk。"""
    root = tk.Tk()
    root.withdraw()
    root.title(APP_NAME)
    try:
        apply_window(root, title=APP_NAME, size="980x720", minsize=(860, 640))
        build(root)
        root.deiconify()
        root.lift()
        try:
            root.attributes("-topmost", True)
            root.after(400, lambda: root.attributes("-topmost", False))
        except tk.TclError:
            pass
        root.mainloop()
    except Exception:
        detail = traceback.format_exc()
        _write_log(detail)
        try:
            show_crash(root, detail)
            root.deiconify()
            root.mainloop()
        except Exception:
            sys.stderr.write(detail)
            raise
