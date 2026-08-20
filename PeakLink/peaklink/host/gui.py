"""被控端視窗。"""

from __future__ import annotations

import tkinter as tk
from tkinter import filedialog, messagebox
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
from peaklink.tailscale import TailscaleStatus, probe_tailscale
from peaklink.ui_bridge import AsyncBridge
from peaklink.ui_theme import (
    ACCENT,
    BG,
    CARD,
    MUTED,
    TEXT,
    AccentButton,
    Header,
    Pill,
    StatusDot,
    apply_window,
    card,
    copy_text,
    heading,
    mono_font,
    muted,
    ui_font,
)


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
        apply_window(root, title=f"{APP_NAME}  被控端", size="640x760", minsize=(600, 700))
        self.cfg = AppConfig.load()
        self.bridge = AsyncBridge(root)
        self.session_id = generate_session_id()
        self.password = generate_password()
        self.agent: HostAgent | None = None
        self.direct: DirectHostServer | None = None
        self.ts = TailscaleStatus(False, False, None, None, None, None)
        try:
            self.license = load_installed_license()
        except LicenseError as exc:
            messagebox.showwarning(APP_NAME, f"授權檔無效，改用免費版。\n{exc}")
            self.license = free_payload()

        Header(root, subtitle="把下面這組 ID 與密碼給客戶，即可連進來", badge="被控端").pack(fill="x", padx=20, pady=(16, 12))

        cred = card(root, fill="x", padx=20, pady=(0, 12))
        body = tk.Frame(cred, bg=CARD)
        body.pack(fill="x", padx=20, pady=18)
        top = tk.Frame(body, bg=CARD)
        top.pack(fill="x")
        heading(top, "本機遠端 ID").pack(side="left")
        AccentButton(top, "複製 ID", self.copy_id, variant="ghost").pack(side="right")
        self.id_var = tk.StringVar(master=root, value=format_session_id(self.session_id))
        tk.Label(body, textvariable=self.id_var, bg=CARD, fg=ACCENT, font=mono_font(28)).pack(anchor="w", pady=(4, 12))

        pw_row = tk.Frame(body, bg=CARD)
        pw_row.pack(fill="x")
        heading(pw_row, "連線密碼", 12).pack(side="left")
        AccentButton(pw_row, "複製密碼", self.copy_pw, variant="ghost").pack(side="right")
        self.pw_var = tk.StringVar(master=root, value=self.password)
        tk.Label(body, textvariable=self.pw_var, bg=CARD, fg=TEXT, font=mono_font(22)).pack(anchor="w", pady=(4, 0))
        muted(body, "每次開啟或按「重新產生」都會換成新的隨機組合。").pack(fill="x", pady=(8, 0))

        meta = tk.Frame(root, bg=BG)
        meta.pack(fill="x", padx=20, pady=(0, 12))
        self.license_var = tk.StringVar(master=root, value=self.license.display_status())
        self.license_pill = Pill(
            meta,
            self.license.display_status(),
            kind="ok" if self.license.edition == "member" else "accent",
        )
        self.license_pill.pack(side="left")

        mode = card(root, fill="x", padx=20, pady=(0, 12))
        mode_in = tk.Frame(mode, bg=CARD)
        mode_in.pack(fill="x", padx=20, pady=16)
        heading(mode_in, "連線方式").pack(anchor="w")
        self.classic_var = tk.BooleanVar(master=root, value=True)
        self.ts_var = tk.BooleanVar(master=root, value=True)
        tk.Checkbutton(
            mode_in,
            text="一般遠端（中繼，給客戶用）",
            variable=self.classic_var,
            bg=CARD,
            fg=TEXT,
            activebackground=CARD,
            font=ui_font(11),
            selectcolor=CARD,
            anchor="w",
        ).pack(fill="x", pady=(10, 2))
        tk.Checkbutton(
            mode_in,
            text="Taliscale 模式（Tailscale 100.x 直連）",
            variable=self.ts_var,
            bg=CARD,
            fg=TEXT,
            activebackground=CARD,
            font=ui_font(11),
            selectcolor=CARD,
            anchor="w",
        ).pack(fill="x", pady=2)
        self.ts_status = tk.StringVar(master=root, value="正在檢查 Tailscale…")
        muted(mode_in, var=self.ts_status).pack(fill="x", pady=(6, 8))
        self.auto_accept = tk.BooleanVar(master=root, value=bool(self.cfg.auto_accept_member and self.license.edition == "member"))
        tk.Checkbutton(
            mode_in,
            text="會員：自動接受連入（無人值守）。免費版仍會詢問。",
            variable=self.auto_accept,
            bg=CARD,
            fg=MUTED,
            activebackground=CARD,
            font=ui_font(10),
            selectcolor=CARD,
            anchor="w",
        ).pack(fill="x")

        status_card = card(root, fill="x", padx=20, pady=(0, 12))
        status_in = tk.Frame(status_card, bg=CARD)
        status_in.pack(fill="x", padx=20, pady=14)
        self.status = StatusDot(status_in)
        self.status.pack(anchor="w")
        self.status.set("尚未上線", kind="muted")
        self.remaining_var = tk.StringVar(master=root, value="")
        muted(status_in, var=self.remaining_var).pack(anchor="w", pady=(6, 0))

        btns = tk.Frame(root, bg=BG)
        btns.pack(fill="x", padx=20, pady=(0, 8))
        self.go_btn = AccentButton(btns, "上線等待連線", self.start, variant="primary")
        self.go_btn.pack(side="left")
        AccentButton(btns, "重新產生 ID", self.regen, variant="ghost").pack(side="left", padx=8)
        AccentButton(btns, "匯入授權", self.import_license, variant="ghost").pack(side="left")

        muted(
            root,
            f"免費版每一次遠端最長 {FREE_SESSION_SECONDS // 60} 分鐘。會員依付款天數使用。"
            "兩岸請走兩邊都連得到的中繼（建議 443 / WSS）。",
            wrap=580,
        ).pack(fill="x", padx=24, pady=(4, 16))

        root.protocol("WM_DELETE_WINDOW", self.on_close)
        root.after(50, self._refresh_tailscale)

    def _refresh_tailscale(self) -> None:
        try:
            self.ts = probe_tailscale()
            self.ts_status.set(self.ts.summary())
        except Exception as exc:  # noqa: BLE001
            self.ts_status.set(f"Tailscale 檢查失敗：{exc}")

    def copy_id(self) -> None:
        copy_text(self.root, self.session_id)
        self.status.set("已複製遠端 ID", kind="ok")

    def copy_pw(self) -> None:
        copy_text(self.root, self.password)
        self.status.set("已複製連線密碼", kind="ok")

    def regen(self) -> None:
        if self.agent:
            messagebox.showinfo(APP_NAME, "請先停止上線再重新產生 ID")
            return
        self.session_id = generate_session_id()
        self.password = generate_password()
        self.id_var.set(format_session_id(self.session_id))
        self.pw_var.set(self.password)
        self.status.set("已產生新的 ID 與密碼", kind="accent")

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
            self.license_pill.configure(text=payload.display_status())
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
        self.go_btn.apply_variant("danger")
        self.status.set("正在探測中繼…", kind="warn")
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
        self.go_btn.apply_variant("primary")
        self.status.set("已停止", kind="muted")
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
                self.bridge.ui(self.status.set, f"Taliscale 直連埠無法開啟：{exc}（一般遠端仍可用）", "warn")
        self.bridge.ui(self.ts_status.set, self.ts.summary())
        self.bridge.ui(self.status.set, f"已上線｜中繼：{best.label}", "ok")
        try:
            await agent.run()
        except Exception as exc:  # noqa: BLE001
            self.bridge.ui(self.status.set, f"連線中斷：{exc}", "danger")
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
            self.status.set("正在被遠端控制（畫面與滑鼠鍵盤已分享）", kind="warn")
        elif kind == "session_tick":
            remaining = event.get("remaining")
            if remaining is not None:
                self.remaining_var.set(f"遠端進行中，剩餘 {int(remaining)} 秒")
        elif kind == "session_end":
            self.status.set(event.get("message") or "遠端已結束，仍在等待下一次連線", kind="ok")
            self.remaining_var.set("")

    def on_close(self) -> None:
        if self.agent:
            self.bridge.submit(self._stop())
        self.root.destroy()


def launch() -> None:
    from peaklink.boot import run_app

    run_app(lambda root: HostWindow(root))
