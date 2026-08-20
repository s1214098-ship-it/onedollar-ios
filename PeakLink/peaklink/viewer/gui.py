"""操作端視窗。"""

from __future__ import annotations

import io
import tkinter as tk
from tkinter import messagebox, ttk
from typing import Any

from PIL import Image, ImageTk

from peaklink.config import AppConfig
from peaklink.constants import APP_NAME, DIRECT_PORT, FREE_SESSION_SECONDS
from peaklink.protocol import MOUSE_DOWN, MOUSE_MOVE, MOUSE_UP, MOUSE_WHEEL
from peaklink.relay import pick_relay
from peaklink.ui_bridge import AsyncBridge
from peaklink.viewer.client import ViewerClient


class ViewerWindow:
    def __init__(self, root: tk.Tk) -> None:
        self.root = root
        self.cfg = AppConfig.load()
        self.bridge = AsyncBridge(root)
        self.client: ViewerClient | None = None
        self.photo = None
        self.remote_size = (0, 0)

        root.title(f"{APP_NAME} — 操作端")
        root.geometry("980x720")

        top = ttk.Frame(root)
        top.pack(fill="x", padx=8, pady=6)

        ttk.Label(top, text="遠端 ID").grid(row=0, column=0, sticky="w")
        self.id_entry = ttk.Entry(top, width=16)
        self.id_entry.grid(row=0, column=1, padx=4)

        ttk.Label(top, text="密碼").grid(row=0, column=2, sticky="w")
        self.pw_entry = ttk.Entry(top, width=10, show="*")
        self.pw_entry.grid(row=0, column=3, padx=4)

        ttk.Label(top, text="模式").grid(row=0, column=4, sticky="w")
        self.mode = tk.StringVar(value="classic")
        ttk.Combobox(
            top,
            textvariable=self.mode,
            values=["classic", "taliscale"],
            width=12,
            state="readonly",
        ).grid(row=0, column=5, padx=4)

        ttk.Label(top, text="Taliscale IP").grid(row=0, column=6, sticky="w")
        self.ts_entry = ttk.Entry(top, width=16)
        self.ts_entry.grid(row=0, column=7, padx=4)

        self.go_btn = ttk.Button(top, text="連線", command=self.connect)
        self.go_btn.pack_forget()
        self.go_btn.grid(row=0, column=8, padx=8)

        self.status = tk.StringVar(value=f"免費客戶單次最長 {FREE_SESSION_SECONDS // 60} 分鐘；會員看授權期間")
        ttk.Label(root, textvariable=self.status).pack(anchor="w", padx=10)

        self.canvas = tk.Canvas(root, bg="#111", highlightthickness=0)
        self.canvas.pack(fill="both", expand=True, padx=8, pady=8)
        self.canvas.bind("<Motion>", self._mouse_move)
        self.canvas.bind("<ButtonPress-1>", lambda e: self._mouse_button(e, MOUSE_DOWN, 1))
        self.canvas.bind("<ButtonRelease-1>", lambda e: self._mouse_button(e, MOUSE_UP, 1))
        self.canvas.bind("<ButtonPress-3>", lambda e: self._mouse_button(e, MOUSE_DOWN, 2))
        self.canvas.bind("<ButtonRelease-3>", lambda e: self._mouse_button(e, MOUSE_UP, 2))
        self.canvas.bind("<MouseWheel>", self._wheel)
        root.bind("<KeyPress>", lambda e: self._key(e, True))
        root.bind("<KeyRelease>", lambda e: self._key(e, False))
        root.protocol("WM_DELETE_WINDOW", self.on_close)

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
        self.status.set("連線中…")
        self.bridge.submit(self._run(session_id, password))

    async def _stop(self) -> None:
        if self.client:
            await self.client.stop()
            self.client = None
        self.bridge.ui(self._stopped)

    def _stopped(self) -> None:
        self.go_btn.configure(text="連線")
        self.status.set("已中斷")

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
            self.bridge.ui(self.status.set, f"連線失敗：{exc}")
        finally:
            self.client = None
            self.bridge.ui(self._stopped)

    def _on_event(self, event: dict[str, Any]) -> None:
        kind = event.get("type")
        if kind == "error":
            messagebox.showerror(APP_NAME, event.get("message") or "連線錯誤")
        elif kind == "viewer_waiting":
            self.status.set(event.get("message") or "等待對方允許…")
        elif kind == "session_start":
            remaining = event.get("remaining")
            extra = "會員連線" if remaining is None else f"剩餘 {int(remaining)} 秒"
            self.status.set(f"已連線（{event.get('edition')}）{extra}")
        elif kind == "session_tick":
            remaining = event.get("remaining")
            if remaining is not None:
                self.status.set(f"遠端進行中，剩餘 {int(remaining)} 秒")
        elif kind == "session_end":
            self.status.set(event.get("message") or "遠端結束")

    def _on_frame(self, width: int, height: int, jpeg: bytes) -> None:
        self.remote_size = (width, height)
        image = Image.open(io.BytesIO(jpeg))
        cw = max(1, self.canvas.winfo_width())
        ch = max(1, self.canvas.winfo_height())
        image.thumbnail((cw, ch), Image.Resampling.BILINEAR)
        self.photo = ImageTk.PhotoImage(image)
        self.canvas.delete("all")
        self.canvas.create_image(cw // 2, ch // 2, image=self.photo, anchor="center")

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
    root = tk.Tk()
    ViewerWindow(root)
    root.mainloop()
