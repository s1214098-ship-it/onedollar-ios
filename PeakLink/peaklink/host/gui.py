"""被控端視窗。"""

from __future__ import annotations

import tkinter as tk
from tkinter import filedialog, messagebox, ttk
from typing import Any

from peaklink.config import AppConfig
from peaklink.constants import APP_NAME, DIRECT_PORT, FREE_SESSION_SECONDS
from peaklink.host.agent import DirectHostServer, HostAgent
from peaklink.ids import format_session_id, generate_password, generate_session_id
from peaklink.license import (
    LicenseError,
    bundled_public_key_pem,
    free_payload,
    license_path,
    load_installed_license,
    load_public_key,
    loads_license,
    save_license_file,
    verify_document,
)
from peaklink.relay import pick_relay
from peaklink.tailscale import probe_tailscale
from peaklink.ui_bridge import AsyncBridge


def _license_document() -> dict:
    path = license_path()
    if path.exists():
        return loads_license(path.read_text(encoding="utf-8"))
    payload = free_payload()
    return {
        "payload": {
            "edition": payload.edition,
            "customer_name": payload.customer_name,
            "issued_at": payload.issued_at,
            "expires_at": payload.expires_at,
            "max_session_seconds": payload.max_session_seconds,
            "paid_amount": payload.paid_amount,
            "paid_currency": payload.paid_currency,
            "package": payload.package,
            "note": payload.note,
            "license_id": payload.license_id,
        },
        "signature": "",
        "alg": "Ed25519",
        "v": 1,
    }


class HostWindow:
    def __init__(self, root: tk.Tk) -> None:
        self.root = root
        self.cfg = AppConfig.load()
        self.bridge = AsyncBridge(root)
        self.session_id = generate_session_id()
        self.password = generate_password()
        self.agent: HostAgent | None = None
        self.direct: DirectHostServer | None = None
        self.ts = probe_tailscale()
        try:
            self.license = load_installed_license()
        except LicenseError as exc:
            messagebox.showwarning(APP_NAME, f"授權檔無效，改用免費版。\n{exc}")
            self.license = free_payload()

        root.title(f"{APP_NAME} — 被控端")
        root.geometry("560x640")
        root.minsize(520, 600)

        pad = {"padx": 12, "pady": 6}
        ttk.Label(root, text=APP_NAME, font=("Microsoft JhengHei UI", 18, "bold")).pack(**pad)
        ttk.Label(root, text="把 ID 與密碼給客戶，即可用一般遠端連進來。").pack()

        id_frame = ttk.LabelFrame(root, text="本機遠端 ID（分享給客戶）")
        id_frame.pack(fill="x", padx=12, pady=8)
        self.id_var = tk.StringVar(value=format_session_id(self.session_id))
        ttk.Label(id_frame, textvariable=self.id_var, font=("Consolas", 28, "bold")).pack(pady=8)
        self.pw_var = tk.StringVar(value=self.password)
        ttk.Label(id_frame, text="連線密碼").pack()
        ttk.Label(id_frame, textvariable=self.pw_var, font=("Consolas", 20)).pack(pady=(0, 8))

        self.license_var = tk.StringVar(value=self.license.display_status())
        ttk.Label(root, textvariable=self.license_var).pack()

        mode = ttk.LabelFrame(root, text="連線模式")
        mode.pack(fill="x", padx=12, pady=8)
        self.classic_var = tk.BooleanVar(value=True)
        self.ts_var = tk.BooleanVar(value=True)
        ttk.Checkbutton(mode, text="一般遠端（中繼，給客戶用）", variable=self.classic_var).pack(anchor="w", padx=8)
        ttk.Checkbutton(mode, text="Taliscale 模式（Tailscale 100.x 直連）", variable=self.ts_var).pack(anchor="w", padx=8)
        self.ts_status = tk.StringVar(value=self.ts.summary())
        ttk.Label(mode, textvariable=self.ts_status).pack(anchor="w", padx=8, pady=4)

        self.auto_accept = tk.BooleanVar(value=bool(self.cfg.auto_accept_member and self.license.edition == "member"))
        ttk.Checkbutton(
            root,
            text="會員：自動接受連入（無人值守）。免費版仍會詢問。",
            variable=self.auto_accept,
        ).pack(anchor="w", padx=16)

        self.status_var = tk.StringVar(value="尚未上線")
        ttk.Label(root, textvariable=self.status_var, foreground="#0a5").pack(pady=4)

        btns = ttk.Frame(root)
        btns.pack(pady=8)
        self.go_btn = ttk.Button(btns, text="上線等待連線", command=self.start)
        self.go_btn.pack(side="left", padx=6)
        ttk.Button(btns, text="重新產生 ID", command=self.regen).pack(side="left", padx=6)
        ttk.Button(btns, text="匯入授權", command=self.import_license).pack(side="left", padx=6)

        self.remaining_var = tk.StringVar(value="")
        ttk.Label(root, textvariable=self.remaining_var).pack()

        note = (
            f"免費版每一次遠端最長 {FREE_SESSION_SECONDS // 60} 分鐘。"
            "會員依付款天數使用，單次不限時長。\n"
            "兩岸連線請把中繼架在台灣與大陸都連得到的位置（建議 443 / WSS），"
            "Taliscale 則走 Tailscale 網內直連。"
        )
        ttk.Label(root, text=note, wraplength=500, justify="left").pack(padx=12, pady=8)

        root.protocol("WM_DELETE_WINDOW", self.on_close)

    def regen(self) -> None:
        if self.agent:
            messagebox.showinfo(APP_NAME, "請先停止上線再重新產生 ID")
            return
        self.session_id = generate_session_id()
        self.password = generate_password()
        self.id_var.set(format_session_id(self.session_id))
        self.pw_var.set(self.password)

    def import_license(self) -> None:
        path = filedialog.askopenfilename(
            title="選擇 .peaklic 授權檔",
            filetypes=[("PeakLink license", "*.peaklic *.json"), ("All", "*.*")],
        )
        if not path:
            return
        try:
            document = loads_license(open(path, encoding="utf-8").read())
            payload = verify_document(document, load_public_key(bundled_public_key_pem()))
            save_license_file(document)
            self.license = payload
            self.license_var.set(payload.display_status())
            messagebox.showinfo(APP_NAME, f"已匯入授權\n{payload.display_status()}")
        except (OSError, LicenseError) as exc:
            messagebox.showerror(APP_NAME, f"無法匯入授權：{exc}")

    def start(self) -> None:
        if self.agent:
            self.bridge.submit(self._stop())
            return
        if not self.classic_var.get() and not self.ts_var.get():
            messagebox.showerror(APP_NAME, "請至少選一種連線模式")
            return
        self.go_btn.configure(text="停止上線")
        self.status_var.set("正在探測中繼…")
        self.bridge.submit(self._run())

    async def _stop(self) -> None:
        if self.direct:
            await self.direct.stop()
            self.direct = None
        if self.agent:
            await self.agent.stop()
            self.agent = None
        self.bridge.ui(self._stopped)

    def _stopped(self) -> None:
        self.go_btn.configure(text="上線等待連線")
        self.status_var.set("已停止")
        self.remaining_var.set("")

    async def _run(self) -> None:
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
        best, probed = pick_relay(preferred_id=self.cfg.relay_id, extra=extra)
        if best is None:
            local = next((p for p in probed if p.id == "auto-local"), None)
            if local:
                best = local
        if best is None:
            self.bridge.ui(messagebox.showerror, APP_NAME, "沒有可用中繼。請先啟動 peaklink-server，或填自訂中繼。")
            self.bridge.ui(self._stopped)
            return

        self.ts = probe_tailscale()
        modes = []
        if self.classic_var.get():
            modes.append("classic")
        if self.ts_var.get():
            modes.append("taliscale")
        consent = True
        if self.license.edition == "member" and self.auto_accept.get():
            consent = False
            self.cfg.auto_accept_member = True
            self.cfg.save()

        agent = HostAgent(
            session_id=self.session_id,
            password=self.password,
            relay_ws=best.ws,
            license_document=_license_document(),
            display_name=self.cfg.display_name or self.ts.hostname or "PeakLink-Host",
            modes=modes,
            tailscale_ip=self.ts.ipv4,
            consent_required=consent,
            jpeg_quality=self.cfg.jpeg_quality,
            fps=self.cfg.fps,
            max_width=self.cfg.max_width,
            on_event=lambda e: self.bridge.ui(self._on_event, e),
        )
        self.agent = agent
        if "taliscale" in modes:
            bind_host = self.ts.ipv4 or "0.0.0.0"
            self.direct = DirectHostServer(agent, bind_host, self.cfg.direct_port or DIRECT_PORT)
            try:
                await self.direct.start()
            except OSError as exc:
                self.bridge.ui(self.status_var.set, f"Taliscale 直連埠無法開啟：{exc}（一般遠端仍可用）")
        self.bridge.ui(self.ts_status.set, self.ts.summary())
        self.bridge.ui(self.status_var.set, f"已上線｜中繼：{best.label}")
        try:
            await agent.run()
        except Exception as exc:  # noqa: BLE001
            self.bridge.ui(self.status_var.set, f"連線中斷：{exc}")
        finally:
            if self.direct:
                await self.direct.stop()
                self.direct = None
            self.agent = None
            self.bridge.ui(self._stopped)

    def _on_event(self, event: dict[str, Any]) -> None:
        kind = event.get("type")
        if kind == "error":
            messagebox.showerror(APP_NAME, event.get("message") or "中繼錯誤")
        elif kind == "viewer_waiting":
            allowed = messagebox.askyesno(APP_NAME, event.get("message") or "允許遠端連線？")
            if self.agent:
                self.bridge.submit(self.agent.send_consent(bool(allowed)))
        elif kind == "session_start":
            remaining = event.get("remaining")
            if remaining is None:
                self.remaining_var.set("遠端進行中（會員不限單次時長）")
            else:
                self.remaining_var.set(f"遠端進行中，剩餘 {int(remaining)} 秒")
            self.status_var.set("正在被遠端控制（畫面與滑鼠鍵盤已分享）")
        elif kind == "session_tick":
            remaining = event.get("remaining")
            if remaining is not None:
                self.remaining_var.set(f"遠端進行中，剩餘 {int(remaining)} 秒")
        elif kind == "session_end":
            self.status_var.set(event.get("message") or "遠端已結束，仍在等待下一次連線")
            self.remaining_var.set("")

    def on_close(self) -> None:
        if self.agent:
            self.bridge.submit(self._stop())
        self.root.destroy()


def launch() -> None:
    root = tk.Tk()
    try:
        style = ttk.Style()
        if "vista" in style.theme_names():
            style.theme_use("vista")
    except tk.TclError:
        pass
    HostWindow(root)
    root.mainloop()
