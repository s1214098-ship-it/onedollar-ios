"""操作端視窗。"""

from __future__ import annotations

import io
import tkinter as tk
from tkinter import messagebox
from typing import Any

from PIL import Image, ImageTk

from peaklink.config import AppConfig
from peaklink.constants import APP_NAME, DIRECT_PORT, FREE_SESSION_SECONDS
from peaklink.protocol import MOUSE_DOWN, MOUSE_MOVE, MOUSE_UP, MOUSE_WHEEL
from peaklink.relay import pick_relay
from peaklink.ui_bridge import AsyncBridge
from peaklink.ui_theme import (
    ACCENT,
    ACCENT_SOFT,
    BG,
    CARD,
    LINE,
    MUTED,
    SCREEN,
    TEXT,
    AccentButton,
    Header,
    StatusDot,
    apply_window,
    card,
    ui_font,
)
from peaklink.viewer.client import ViewerClient


class ViewerWindow:
    def __init__(
        self,
        root: tk.Tk | tk.Toplevel,
        *,
        session_id: str = "",
        password: str = "",
        mode: str = "classic",
        ts_ip: str = "",
        auto_connect: bool = False,
    ) -> None:
        self.root = root
        self.cfg = AppConfig.load()
        self.bridge = AsyncBridge(root)
        self.client: ViewerClient | None = None
        self.photo = None
        self.remote_size = (0, 0)
        self.mode = tk.StringVar(master=root, value=mode)

        apply_window(root, title=f"{APP_NAME}  操作端", size="1080x740", minsize=(900, 620))

        Header(root, subtitle="輸入對方的遠端 ID 與密碼即可連線", badge="操作端").pack(fill="x", padx=16, pady=(12, 10))

        bar = card(root, fill="x", padx=16, pady=(0, 10))
        inner = tk.Frame(bar, bg=CARD)
        inner.pack(fill="x", padx=16, pady=14)

        def field(parent, label, width, show=""):
            box = tk.Frame(parent, bg=CARD)
            box.pack(side="left", padx=(0, 14))
            tk.Label(box, text=label, bg=CARD, fg=MUTED, font=ui_font(9, bold=True)).pack(anchor="w")
            entry = tk.Entry(
                box,
                width=width,
                bg="#F8FBFD",
                fg=TEXT,
                relief="flat",
                highlightthickness=1,
                highlightbackground=LINE,
                highlightcolor=ACCENT,
                font=ui_font(12),
                show=show,
            )
            entry.pack(ipady=6, pady=(4, 0))
            return entry

        self.id_entry = field(inner, "遠端 ID", 16)
        self.pw_entry = field(inner, "連線密碼", 10, "*")
        self.ts_entry = field(inner, "Taliscale IP（選填）", 16)

        mode_box = tk.Frame(inner, bg=CARD)
        mode_box.pack(side="left", padx=(0, 14))
        tk.Label(mode_box, text="連線模式", bg=CARD, fg=MUTED, font=ui_font(9, bold=True)).pack(anchor="w")
        switch = tk.Frame(mode_box, bg=CARD)
        switch.pack(anchor="w", pady=(4, 0))
        self._classic_btn = tk.Label(switch, text="一般遠端", padx=12, pady=6, cursor="hand2", font=ui_font(10, bold=True))
        self._ts_btn = tk.Label(switch, text="Taliscale", padx=12, pady=6, cursor="hand2", font=ui_font(10, bold=True))
        self._classic_btn.pack(side="left")
        self._ts_btn.pack(side="left", padx=(6, 0))
        self._classic_btn.bind("<Button-1>", lambda _e: self._set_mode("classic"))
        self._ts_btn.bind("<Button-1>", lambda _e: self._set_mode("taliscale"))
        self._set_mode(mode)

        self.go_btn = AccentButton(inner, "連線", self.connect, variant="primary")
        self.go_btn.pack(side="right", pady=(12, 0))

        status_row = tk.Frame(root, bg=BG)
        status_row.pack(fill="x", padx=20, pady=(0, 8))
        self.status = StatusDot(status_row)
        self.status.pack(side="left")
        self.status.set(f"免費客戶單次最長 {FREE_SESSION_SECONDS // 60} 分鐘；會員看授權期間", kind="muted")

        screen = tk.Frame(root, bg=SCREEN, highlightthickness=0)
        screen.pack(fill="both", expand=True, padx=16, pady=(0, 16))
        self.canvas = tk.Canvas(screen, bg=SCREEN, highlightthickness=0)
        self.canvas.pack(fill="both", expand=True)
        self._placeholder = self.canvas.create_text(
            400,
            240,
            text="尚未連線\n請輸入 ID 與密碼後按連線",
            fill="#7A8B99",
            font=ui_font(14),
            justify="center",
        )
        self.canvas.bind("<Configure>", self._place_placeholder)
        self.canvas.bind("<Motion>", self._mouse_move)
        self.canvas.bind("<ButtonPress-1>", lambda e: self._mouse_button(e, MOUSE_DOWN, 1))
        self.canvas.bind("<ButtonRelease-1>", lambda e: self._mouse_button(e, MOUSE_UP, 1))
        self.canvas.bind("<ButtonPress-3>", lambda e: self._mouse_button(e, MOUSE_DOWN, 2))
        self.canvas.bind("<ButtonRelease-3>", lambda e: self._mouse_button(e, MOUSE_UP, 2))
        self.canvas.bind("<MouseWheel>", self._wheel)
        root.bind("<KeyPress>", lambda e: self._key(e, True))
        root.bind("<KeyRelease>", lambda e: self._key(e, False))
        root.protocol("WM_DELETE_WINDOW", self.on_close)
        if session_id:
            self.id_entry.insert(0, session_id)
        if password:
            self.pw_entry.insert(0, password)
        if ts_ip:
            self.ts_entry.insert(0, ts_ip)
        if auto_connect and session_id and password:
            self.root.after(200, self.connect)

    def _set_mode(self, value: str) -> None:
        self.mode.set(value)
        on = (ACCENT, "white")
        off = (ACCENT_SOFT, ACCENT)
        classic = value == "classic"
        self._classic_btn.configure(bg=on[0] if classic else off[0], fg=on[1] if classic else off[1])
        self._ts_btn.configure(bg=on[0] if not classic else off[0], fg=on[1] if not classic else off[1])

    def _place_placeholder(self, event=None) -> None:
        if self.photo is not None:
            return
        try:
            self.canvas.coords(self._placeholder, self.canvas.winfo_width() / 2, self.canvas.winfo_height() / 2)
        except tk.TclError:
            pass

    def connect(self) -> None:
        if self.client:
            self.bridge.submit(self._stop())
            return
        session_id = "".join(ch for ch in self.id_entry.get() if ch.isdigit())
        password = self.pw_entry.get().strip()
        if len(session_id) != 9:
            messagebox.showerror(APP_NAME, "請輸入 9 位數遠端 ID")
            return
        if not password:
            messagebox.showerror(APP_NAME, "請輸入連線密碼")
            return
        self.go_btn.configure(text="中斷")
        self.go_btn.apply_variant("danger")
        self.status.set("連線中…", "warn")
        self.bridge.submit(self._run(session_id, password))

    async def _stop(self) -> None:
        if self.client:
            await self.client.stop()
            self.client = None
        self.bridge.ui(self._stopped)

    def _stopped(self) -> None:
        self.go_btn.configure(text="連線")
        self.go_btn.apply_variant("primary")
        self.status.set("已中斷", "muted")
        self.photo = None
        self.canvas.delete("frame")
        self.canvas.itemconfigure(self._placeholder, state="normal")
        self._place_placeholder()

    async def _run(self, session_id: str, password: str) -> None:
        if self.mode.get() == "taliscale":
            ip = self.ts_entry.get().strip()
            if not ip:
                self.bridge.ui(messagebox.showerror, APP_NAME, "Taliscale 模式請填對方 100.x IP")
                self.bridge.ui(self._stopped)
                return
            ws = f"ws://{ip}:{self.cfg.direct_port or DIRECT_PORT}"
        else:
            extra = []
            if self.cfg.custom_relay_http and self.cfg.custom_relay_ws:
                extra.append(
                    {
                        "id": "custom",
                        "label": "自訂中繼",
                        "http": self.cfg.custom_relay_http,
                        "ws": self.cfg.custom_relay_ws,
                        "region": "custom",
                    }
                )
            best, _probed = pick_relay(preferred_id=self.cfg.relay_id, extra=extra)
            if best is None:
                ws = "ws://127.0.0.1:8787/ws"
            else:
                ws = best.ws
        client = ViewerClient(
            session_id=session_id,
            password=password,
            relay_ws=ws,
            on_event=lambda e: self.bridge.ui(self._on_event, e),
            on_frame=lambda w, h, j: self.bridge.ui(self._on_frame, w, h, j),
        )
        self.client = client
        try:
            await client.run()
        except Exception as exc:  # noqa: BLE001
            self.bridge.ui(self.status.set, f"連線失敗：{exc}", "danger")
        finally:
            self.client = None
            self.bridge.ui(self._stopped)

    def _on_event(self, event: dict[str, Any]) -> None:
        kind = event.get("type")
        if kind == "error":
            messagebox.showerror(APP_NAME, event.get("message") or "連線錯誤")
        elif kind == "viewer_waiting":
            self.status.set(event.get("message") or "等待對方允許…", "warn")
        elif kind == "session_start":
            remaining = event.get("remaining")
            extra = "會員連線" if remaining is None else f"剩餘 {int(remaining)} 秒"
            self.status.set(f"已連線  {extra}", "ok")
        elif kind == "session_tick":
            remaining = event.get("remaining")
            if remaining is not None:
                self.status.set(f"遠端進行中，剩餘 {int(remaining)} 秒", "ok")
        elif kind == "session_end":
            self.status.set(event.get("message") or "遠端結束", "muted")

    def _on_frame(self, width: int, height: int, jpeg: bytes) -> None:
        self.remote_size = (width, height)
        image = Image.open(io.BytesIO(jpeg))
        cw = max(1, self.canvas.winfo_width())
        ch = max(1, self.canvas.winfo_height())
        image.thumbnail((cw, ch), Image.Resampling.BILINEAR)
        self.photo = ImageTk.PhotoImage(image)
        self.canvas.delete("frame")
        self.canvas.itemconfigure(self._placeholder, state="hidden")
        self.canvas.create_image(cw // 2, ch // 2, image=self.photo, anchor="center", tags="frame")

    def _map(self, event) -> tuple[int, int] | None:
        if not self.client or not self.remote_size[0]:
            return None
        cw = max(1, self.canvas.winfo_width())
        ch = max(1, self.canvas.winfo_height())
        rw, rh = self.remote_size
        scale = min(cw / rw, ch / rh)
        dw, dh = rw * scale, rh * scale
        ox, oy = (cw - dw) / 2, (ch - dh) / 2
        x = int((event.x - ox) / scale)
        y = int((event.y - oy) / scale)
        if x < 0 or y < 0 or x > rw or y > rh:
            return None
        return x, y

    def _mouse_move(self, event) -> None:
        mapped = self._map(event)
        if not mapped or not self.client:
            return
        x, y = mapped
        self.bridge.submit(self.client.send_mouse(MOUSE_MOVE, x, y))

    def _mouse_button(self, event, action: int, button: int) -> None:
        mapped = self._map(event)
        if not mapped or not self.client:
            return
        x, y = mapped
        self.bridge.submit(self.client.send_mouse(action, x, y, button))

    def _wheel(self, event) -> None:
        mapped = self._map(event)
        if not mapped or not self.client:
            return
        x, y = mapped
        delta = 1 if event.delta > 0 else -1
        self.bridge.submit(self.client.send_mouse(MOUSE_WHEEL, x, y, 0, delta))

    def _key(self, event, down: bool) -> None:
        if not self.client:
            return
        vk = int(event.keycode or 0)
        text = event.char if event.char and event.char.isprintable() else ""
        self.bridge.submit(self.client.send_key(down, vk, text))

    def on_close(self) -> None:
        if self.client:
            self.bridge.submit(self._stop())
        self.root.destroy()


def launch() -> None:
    from peaklink.boot import run_app

    run_app(lambda root: ViewerWindow(root))
