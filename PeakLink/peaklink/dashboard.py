"""主畫面：一打開就顯示隨機 ID／密碼（不必再進被控端）。"""

from __future__ import annotations

import tkinter as tk
from tkinter import filedialog, messagebox
from typing import Any

from peaklink.config import AppConfig
from peaklink.constants import APP_NAME, DEFAULT_RELAY_PORT, DIRECT_PORT, FREE_SESSION_SECONDS
from peaklink.host.agent import DirectHostServer, HostAgent
from peaklink.host.gui import _license_document
from peaklink.ids import format_session_id, generate_password, generate_session_id
from peaklink.license import (
    LicenseError,
    bundled_public_key_pem,
    free_payload,
    load_installed_license,
    load_public_key,
    loads_license,
    save_license_file,
    verify_document,
)
from peaklink.relay import pick_relay
from peaklink.tailscale import TailscaleStatus, probe_tailscale
from peaklink.ui_bridge import AsyncBridge
from peaklink.ui_theme import (
    ACCENT,
    ACCENT_SOFT,
    BG,
    CARD,
    LINE,
    MUTED,
    TEXT,
    AccentButton,
    Header,
    Pill,
    StatusDot,
    card,
    copy_text,
    heading,
    mono_font,
    muted,
    ui_font,
)


class Dashboard:
    def __init__(self, root: tk.Tk) -> None:
        self.root = root
        self.cfg = AppConfig.load()
        self.bridge = AsyncBridge(root)
        self.session_id = generate_session_id()
        self.password = generate_password()
        self.agent: HostAgent | None = None
        self.direct: DirectHostServer | None = None
        self.ts = TailscaleStatus(False, False, None, None, None, None)
        try:
            self.license = load_installed_license()
        except LicenseError:
            self.license = free_payload()

        Header(root, subtitle="左側把 ID 給客戶；右側輸入對方 ID 連過去", badge="主畫面").pack(fill="x", padx=16, pady=(12, 10))

        cols = tk.Frame(root, bg=BG)
        cols.pack(fill="both", expand=True, padx=16, pady=(0, 8))
        cols.columnconfigure(0, weight=1)
        cols.columnconfigure(1, weight=1)

        left = card(cols)
        left.grid(row=0, column=0, sticky="nsew", padx=(0, 8))
        right = card(cols)
        right.grid(row=0, column=1, sticky="nsew", padx=(8, 0))

        self._build_host_card(left)
        self._build_viewer_card(right)

        bottom = card(root, fill="x", padx=16, pady=(0, 14))
        foot = tk.Frame(bottom, bg=CARD)
        foot.pack(fill="x", padx=16, pady=12)
        self.status = StatusDot(foot)
        self.status.pack(side="left")
        self.status.set("尚未上線。先按「上線等待連線」，再把 ID 給對方。", "muted")
        AccentButton(foot, "匯入授權", self.import_license, variant="ghost").pack(side="right")
        AccentButton(foot, "啟動本機中繼", self.start_relay, variant="ghost").pack(side="right", padx=8)

        self.root.protocol("WM_DELETE_WINDOW", self.on_close)
        self.root.after(50, self._refresh_tailscale)

    def _build_host_card(self, parent: tk.Frame) -> None:
        box = tk.Frame(parent, bg=CARD)
        box.pack(fill="both", expand=True, padx=20, pady=18)
        heading(box, "本機識別碼（給客戶連進來）").pack(anchor="w")
        muted(box, "一打開就會隨機產生。可複製，也可重新產生。").pack(anchor="w", pady=(2, 10))

        self.id_var = tk.StringVar(master=self.root, value=format_session_id(self.session_id))
        self.pw_var = tk.StringVar(master=self.root, value=self.password)

        id_row = tk.Frame(box, bg=CARD)
        id_row.pack(fill="x")
        tk.Label(id_row, text="遠端 ID", bg=CARD, fg=MUTED, font=ui_font(9, bold=True)).pack(side="left")
        AccentButton(id_row, "複製", self.copy_id, variant="ghost").pack(side="right")
        tk.Label(box, textvariable=self.id_var, bg=CARD, fg=ACCENT, font=mono_font(26)).pack(anchor="w", pady=(2, 10))

        pw_row = tk.Frame(box, bg=CARD)
        pw_row.pack(fill="x")
        tk.Label(pw_row, text="連線密碼", bg=CARD, fg=MUTED, font=ui_font(9, bold=True)).pack(side="left")
        AccentButton(pw_row, "複製", self.copy_pw, variant="ghost").pack(side="right")
        tk.Label(box, textvariable=self.pw_var, bg=CARD, fg=TEXT, font=mono_font(20)).pack(anchor="w", pady=(2, 10))

        kind = "ok" if self.license.edition == "member" else "accent"
        Pill(box, self.license.display_status(), kind=kind).pack(anchor="w", pady=(0, 12))

        self.classic_var = tk.BooleanVar(master=self.root, value=True)
        self.ts_var = tk.BooleanVar(master=self.root, value=True)
        tk.Checkbutton(box, text="一般遠端（中繼，給客戶）", variable=self.classic_var, bg=CARD, fg=TEXT, activebackground=CARD, selectcolor=CARD, font=ui_font(10), anchor="w").pack(fill="x")
        tk.Checkbutton(box, text="Taliscale（Tailscale 直連）", variable=self.ts_var, bg=CARD, fg=TEXT, activebackground=CARD, selectcolor=CARD, font=ui_font(10), anchor="w").pack(fill="x")
        self.ts_status = tk.StringVar(master=self.root, value="正在檢查 Tailscale…")
        muted(box, var=self.ts_status).pack(anchor="w", pady=(4, 8))

        self.auto_accept = tk.BooleanVar(master=self.root, value=bool(self.cfg.auto_accept_member and self.license.edition == "member"))
        tk.Checkbutton(
            box,
            text="會員：自動接受連入",
            variable=self.auto_accept,
            bg=CARD,
            fg=MUTED,
            activebackground=CARD,
            selectcolor=CARD,
            font=ui_font(10),
            anchor="w",
        ).pack(fill="x")

        btns = tk.Frame(box, bg=CARD)
        btns.pack(fill="x", pady=(14, 0))
        self.go_btn = AccentButton(btns, "上線等待連線", self.start_host, variant="primary")
        self.go_btn.pack(side="left")
        AccentButton(btns, "重新產生", self.regen, variant="ghost").pack(side="left", padx=8)
        self.remaining_var = tk.StringVar(master=self.root, value="")
        muted(box, var=self.remaining_var).pack(anchor="w", pady=(10, 0))

    def _build_viewer_card(self, parent: tk.Frame) -> None:
        box = tk.Frame(parent, bg=CARD)
        box.pack(fill="both", expand=True, padx=20, pady=18)
        heading(box, "連到遠端（去控制別人）").pack(anchor="w")
        muted(box, "輸入對方畫面上的 ID 與密碼。").pack(anchor="w", pady=(2, 12))

        tk.Label(box, text="對方遠端 ID", bg=CARD, fg=MUTED, font=ui_font(9, bold=True)).pack(anchor="w")
        self.remote_id = tk.Entry(box, bg="#F8FBFD", fg=TEXT, relief="flat", highlightthickness=1, highlightbackground=LINE, highlightcolor=ACCENT, font=ui_font(14))
        self.remote_id.pack(fill="x", ipady=8, pady=(4, 10))

        tk.Label(box, text="對方連線密碼", bg=CARD, fg=MUTED, font=ui_font(9, bold=True)).pack(anchor="w")
        self.remote_pw = tk.Entry(box, show="*", bg="#F8FBFD", fg=TEXT, relief="flat", highlightthickness=1, highlightbackground=LINE, highlightcolor=ACCENT, font=ui_font(14))
        self.remote_pw.pack(fill="x", ipady=8, pady=(4, 10))

        tk.Label(box, text="模式", bg=CARD, fg=MUTED, font=ui_font(9, bold=True)).pack(anchor="w")
        self.view_mode = tk.StringVar(master=self.root, value="classic")
        mode_row = tk.Frame(box, bg=CARD)
        mode_row.pack(anchor="w", pady=(4, 10))
        tk.Radiobutton(mode_row, text="一般遠端", variable=self.view_mode, value="classic", bg=CARD, fg=TEXT, selectcolor=CARD, activebackground=CARD, font=ui_font(10)).pack(side="left")
        tk.Radiobutton(mode_row, text="Taliscale", variable=self.view_mode, value="taliscale", bg=CARD, fg=TEXT, selectcolor=CARD, activebackground=CARD, font=ui_font(10)).pack(side="left", padx=12)

        tk.Label(box, text="Taliscale IP（僅直連時需要）", bg=CARD, fg=MUTED, font=ui_font(9, bold=True)).pack(anchor="w")
        self.ts_ip = tk.Entry(box, bg="#F8FBFD", fg=TEXT, relief="flat", highlightthickness=1, highlightbackground=LINE, highlightcolor=ACCENT, font=ui_font(12))
        self.ts_ip.pack(fill="x", ipady=6, pady=(4, 14))

        AccentButton(box, "連線到對方", self.open_viewer, variant="primary").pack(anchor="w")
        muted(box, f"免費版單次最長 {FREE_SESSION_SECONDS // 60} 分鐘。", wrap=360).pack(anchor="w", pady=(12, 0))

    def _refresh_tailscale(self) -> None:
        try:
            self.ts = probe_tailscale()
            self.ts_status.set(self.ts.summary())
        except Exception as exc:  # noqa: BLE001
            self.ts_status.set(f"Tailscale 檢查失敗：{exc}")

    def copy_id(self) -> None:
        copy_text(self.root, self.session_id)
        self.status.set("已複製遠端 ID", "ok")

    def copy_pw(self) -> None:
        copy_text(self.root, self.password)
        self.status.set("已複製連線密碼", "ok")

    def regen(self) -> None:
        if self.agent:
            messagebox.showinfo(APP_NAME, "請先停止上線再重新產生")
            return
        self.session_id = generate_session_id()
        self.password = generate_password()
        self.id_var.set(format_session_id(self.session_id))
        self.pw_var.set(self.password)
        self.status.set("已產生新的 ID 與密碼", "accent")

    def import_license(self) -> None:
        path = filedialog.askopenfilename(title="選擇授權檔", filetypes=[("PeakLink license", "*.peaklic *.json"), ("All", "*.*")])
        if not path:
            return
        try:
            document = loads_license(open(path, encoding="utf-8").read())
            payload = verify_document(document, load_public_key(bundled_public_key_pem()))
            save_license_file(document)
            self.license = payload
            messagebox.showinfo(APP_NAME, payload.display_status())
        except (OSError, LicenseError) as exc:
            messagebox.showerror(APP_NAME, str(exc))

    def start_relay(self) -> None:
        import threading

        from peaklink.server.app import serve

        threading.Thread(target=lambda: serve("0.0.0.0", DEFAULT_RELAY_PORT), daemon=True, name="peaklink-relay").start()
        messagebox.showinfo(APP_NAME, f"本機中繼已啟動於埠 {DEFAULT_RELAY_PORT}。")

    def start_host(self) -> None:
        if self.agent:
            self.bridge.submit(self._stop_host())
            return
        if not self.classic_var.get() and not self.ts_var.get():
            messagebox.showerror(APP_NAME, "請至少選一種連線模式")
            return
        self.go_btn.configure(text="停止上線")
        self.go_btn.apply_variant("danger")
        self.status.set("正在上線…", "warn")
        self.bridge.submit(self._run_host())

    async def _stop_host(self) -> None:
        if self.direct:
            await self.direct.stop()
            self.direct = None
        if self.agent:
            await self.agent.stop()
            self.agent = None
        self.bridge.ui(self._host_stopped)

    def _host_stopped(self) -> None:
        self.go_btn.configure(text="上線等待連線")
        self.go_btn.apply_variant("primary")
        self.status.set("已停止上線", "muted")
        self.remaining_var.set("")

    async def _run_host(self) -> None:
        extra = []
        if self.cfg.custom_relay_http and self.cfg.custom_relay_ws:
            extra.append({"id": "custom", "label": "自訂中繼", "http": self.cfg.custom_relay_http, "ws": self.cfg.custom_relay_ws, "region": "custom"})
        best, probed = pick_relay(preferred_id=self.cfg.relay_id, extra=extra)
        if best is None:
            local = next((p for p in probed if p.id == "auto-local"), None)
            best = local
        if best is None:
            self.bridge.ui(messagebox.showerror, APP_NAME, "沒有可用中繼。請先「啟動本機中繼」，或架設中繼伺服器。")
            self.bridge.ui(self._host_stopped)
            return
        modes = []
        if self.classic_var.get():
            modes.append("classic")
        if self.ts_var.get():
            modes.append("taliscale")
        consent = not (self.license.edition == "member" and self.auto_accept.get())
        if not consent:
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
            on_event=lambda e: self.bridge.ui(self._on_host_event, e),
        )
        self.agent = agent
        if "taliscale" in modes:
            self.direct = DirectHostServer(agent, self.ts.ipv4 or "0.0.0.0", self.cfg.direct_port or DIRECT_PORT)
            try:
                await self.direct.start()
            except OSError as exc:
                self.bridge.ui(self.status.set, f"Taliscale 直連埠無法開啟：{exc}", "warn")
        self.bridge.ui(self.status.set, f"已上線｜把 ID {format_session_id(self.session_id)} 給對方｜{best.label}", "ok")
        try:
            await agent.run()
        except Exception as exc:  # noqa: BLE001
            self.bridge.ui(self.status.set, f"連線中斷：{exc}", "danger")
        finally:
            if self.direct:
                await self.direct.stop()
                self.direct = None
            self.agent = None
            self.bridge.ui(self._host_stopped)

    def _on_host_event(self, event: dict[str, Any]) -> None:
        kind = event.get("type")
        if kind == "error":
            messagebox.showerror(APP_NAME, event.get("message") or "中繼錯誤")
        elif kind == "viewer_waiting":
            allowed = messagebox.askyesno(APP_NAME, event.get("message") or "允許遠端連線？")
            if self.agent:
                self.bridge.submit(self.agent.send_consent(bool(allowed)))
        elif kind == "session_start":
            remaining = event.get("remaining")
            self.remaining_var.set("遠端進行中（會員不限單次）" if remaining is None else f"遠端進行中，剩餘 {int(remaining)} 秒")
            self.status.set("正在被遠端控制", "warn")
        elif kind == "session_tick" and event.get("remaining") is not None:
            self.remaining_var.set(f"遠端進行中，剩餘 {int(event['remaining'])} 秒")
        elif kind == "session_end":
            self.status.set(event.get("message") or "遠端已結束", "ok")
            self.remaining_var.set("")

    def open_viewer(self) -> None:
        from peaklink.viewer.gui import ViewerWindow

        sid = "".join(ch for ch in self.remote_id.get() if ch.isdigit())
        password = self.remote_pw.get().strip()
        if len(sid) != 9:
            messagebox.showerror(APP_NAME, "請輸入對方 9 位數遠端 ID")
            return
        if not password:
            messagebox.showerror(APP_NAME, "請輸入對方連線密碼")
            return
        win = tk.Toplevel(self.root)
        ViewerWindow(
            win,
            session_id=sid,
            password=password,
            mode=self.view_mode.get(),
            ts_ip=self.ts_ip.get().strip(),
            auto_connect=True,
        )

    def on_close(self) -> None:
        if self.agent:
            self.bridge.submit(self._stop_host())
        self.root.destroy()


def build_dashboard(root: tk.Tk) -> None:
    Dashboard(root)
