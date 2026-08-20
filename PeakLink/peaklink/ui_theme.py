"""峰連遠端共用介面：淺色卡片、主色按鈕、狀態點。"""

from __future__ import annotations

import sys
import tkinter as tk
from collections.abc import Callable
from pathlib import Path

from peaklink.constants import APP_NAME, VERSION

BG = "#F3F6FA"
CARD = "#FFFFFF"
LINE = "#D7E2EE"
TEXT = "#1C2B3A"
MUTED = "#5B6B7C"
ACCENT = "#0E6E9C"
ACCENT_HOVER = "#0B5A80"
ACCENT_SOFT = "#E7F4FA"
OK = "#1A9F6A"
WARN = "#C47B12"
DANGER = "#C4453C"
DANGER_HOVER = "#A3362F"
SCREEN = "#101820"


def ui_font(size: int = 11, *, bold: bool = False) -> tuple:
    if sys.platform == "win32":
        family = "Microsoft JhengHei UI"
    elif sys.platform == "darwin":
        family = "PingFang TC"
    else:
        family = "WenQuanYi Micro Hei"
    weight = "bold" if bold else "normal"
    return (family, size, weight)


def mono_font(size: int = 22, *, bold: bool = True) -> tuple:
    family = "Cascadia Mono" if sys.platform == "win32" else "DejaVu Sans Mono"
    return (family, size, "bold" if bold else "normal")


def apply_window(root: tk.Tk | tk.Toplevel, *, title: str, size: str, minsize: tuple[int, int] | None = None) -> None:
    root.title(title)
    root.geometry(size)
    root.configure(bg=BG)
    if minsize:
        root.minsize(*minsize)
    try:
        root.option_add("*Font", ui_font(11))
    except tk.TclError:
        pass
    _try_icon(root)


def _try_icon(root: tk.Misc) -> None:
    candidates = []
    meipass = getattr(sys, "_MEIPASS", None)
    if meipass:
        candidates.append(Path(meipass) / "packaging" / "peaklink.ico")
    here = Path(__file__).resolve()
    candidates.append(here.parents[1] / "packaging" / "peaklink.ico")
    for path in candidates:
        if path.exists():
            try:
                root.iconbitmap(default=str(path))
            except tk.TclError:
                try:
                    root.iconbitmap(str(path))
                except tk.TclError:
                    return
            return


def card(parent: tk.Misc, **pack) -> tk.Frame:
    frame = tk.Frame(parent, bg=CARD, highlightbackground=LINE, highlightthickness=1, bd=0)
    if pack:
        frame.pack(**pack)
    return frame


def heading(parent: tk.Misc, text: str, size: int = 13) -> tk.Label:
    return tk.Label(parent, text=text, bg=parent.cget("bg"), fg=TEXT, font=ui_font(size, bold=True), anchor="w")


def muted(parent: tk.Misc, text: str = "", *, var: tk.StringVar | None = None, wrap: int = 0) -> tk.Label:
    return tk.Label(
        parent,
        text=text,
        textvariable=var,
        bg=parent.cget("bg"),
        fg=MUTED,
        font=ui_font(10),
        justify="left",
        wraplength=wrap,
        anchor="w",
    )


class Pill(tk.Label):
    def __init__(self, parent: tk.Misc, text: str, *, kind: str = "accent") -> None:
        colors = {
            "accent": (ACCENT_SOFT, ACCENT),
            "ok": ("#E5F7EE", OK),
            "warn": ("#FFF4E0", WARN),
            "muted": ("#EEF2F6", MUTED),
        }
        bg, fg = colors.get(kind, colors["muted"])
        super().__init__(parent, text=text, bg=bg, fg=fg, font=ui_font(9, bold=True), padx=10, pady=3)


class StatusDot(tk.Frame):
    def __init__(self, parent: tk.Misc) -> None:
        super().__init__(parent, bg=parent.cget("bg"))
        self._canvas = tk.Canvas(self, width=12, height=12, bg=parent.cget("bg"), highlightthickness=0)
        self._canvas.pack(side="left", padx=(0, 8))
        self._dot = self._canvas.create_oval(2, 2, 10, 10, fill="#9AA8B5", outline="")
        self.label = tk.Label(self, text="", bg=parent.cget("bg"), fg=TEXT, font=ui_font(11))
        self.label.pack(side="left")

    def set(self, text: str, kind: str = "muted") -> None:
        fill = {"ok": OK, "warn": WARN, "danger": DANGER, "accent": ACCENT, "muted": "#9AA8B5"}[kind]
        self._canvas.itemconfigure(self._dot, fill=fill)
        self.label.configure(text=text)


class AccentButton(tk.Button):
    def __init__(self, parent: tk.Misc, text: str, command: Callable[[], None] | None, *, variant: str = "primary") -> None:
        self._variant = variant
        super().__init__(
            parent,
            text=text,
            command=command,
            relief="flat",
            bd=0,
            cursor="hand2",
            font=ui_font(11, bold=True),
            padx=18,
            pady=10,
        )
        self.apply_variant(variant)
        self.bind("<Enter>", lambda _e: self._hover(True))
        self.bind("<Leave>", lambda _e: self._hover(False))

    def apply_variant(self, variant: str) -> None:
        self._variant = variant
        self._hover(False)

    def _hover(self, on: bool) -> None:
        if self._variant == "primary":
            self.configure(bg=ACCENT_HOVER if on else ACCENT, fg="white", activebackground=ACCENT_HOVER, activeforeground="white")
        elif self._variant == "danger":
            self.configure(bg=DANGER_HOVER if on else DANGER, fg="white", activebackground=DANGER_HOVER, activeforeground="white")
        else:
            self.configure(
                bg=ACCENT_SOFT if on else CARD,
                fg=ACCENT,
                activebackground=ACCENT_SOFT,
                activeforeground=ACCENT,
                highlightbackground=LINE,
                highlightthickness=1,
            )


class Header(tk.Frame):
    def __init__(self, parent: tk.Misc, *, subtitle: str, badge: str = "") -> None:
        super().__init__(parent, bg=CARD, highlightbackground=LINE, highlightthickness=1)
        inner = tk.Frame(self, bg=CARD)
        inner.pack(fill="x", padx=20, pady=16)
        mark = tk.Canvas(inner, width=36, height=36, bg=CARD, highlightthickness=0)
        mark.pack(side="left")
        mark.create_oval(2, 2, 34, 34, fill=ACCENT, outline="")
        mark.create_text(18, 18, text="峰", fill="white", font=ui_font(12, bold=True))
        titles = tk.Frame(inner, bg=CARD)
        titles.pack(side="left", padx=12)
        tk.Label(titles, text=APP_NAME, bg=CARD, fg=TEXT, font=ui_font(16, bold=True)).pack(anchor="w")
        tk.Label(titles, text=subtitle, bg=CARD, fg=MUTED, font=ui_font(10)).pack(anchor="w")
        right = tk.Frame(inner, bg=CARD)
        right.pack(side="right")
        if badge:
            Pill(right, badge, kind="accent").pack(side="right")
        tk.Label(right, text=f"v{VERSION}", bg=CARD, fg=MUTED, font=ui_font(9)).pack(side="right", padx=(0, 8))


def copy_text(root: tk.Misc, value: str) -> None:
    root.clipboard_clear()
    root.clipboard_append(value)
    root.update_idletasks()
