"""啟動器：被控端／操作端／匯入授權。"""

from __future__ import annotations

import tkinter as tk
from tkinter import filedialog, messagebox

from peaklink.constants import APP_NAME, DEFAULT_RELAY_PORT, FREE_SESSION_SECONDS
from peaklink.license import (
    LicenseError,
    bundled_public_key_pem,
    load_public_key,
    loads_license,
    save_license_file,
    verify_document,
)
from peaklink.ui_theme import (
    ACCENT,
    ACCENT_SOFT,
    CARD,
    LINE,
    MUTED,
    TEXT,
    AccentButton,
    Header,
    apply_window,
    card,
    muted,
    ui_font,
)


def _action_row(parent: tk.Misc, title: str, desc: str, button: str, command, *, primary: bool = False) -> None:
    row = tk.Frame(parent, bg=CARD)
    row.pack(fill="x", padx=18, pady=8)
    texts = tk.Frame(row, bg=CARD)
    texts.pack(side="left", fill="x", expand=True)
    tk.Label(texts, text=title, bg=CARD, fg=TEXT, font=ui_font(13, bold=True), anchor="w").pack(fill="x")
    tk.Label(texts, text=desc, bg=CARD, fg=MUTED, font=ui_font(10), anchor="w", justify="left", wraplength=280).pack(fill="x")
    AccentButton(row, button, command, variant="primary" if primary else "ghost").pack(side="right", padx=(12, 0))


def main() -> None:
    root = tk.Tk()
    apply_window(root, title=APP_NAME, size="520x620", minsize=(480, 580))

    Header(root, subtitle="遠端協助｜把 ID 給客戶，或連進別人的電腦", badge="Windows").pack(fill="x", padx=20, pady=(20, 12))

    hero = card(root, fill="x", padx=20, pady=(0, 12))
    inner = tk.Frame(hero, bg=CARD)
    inner.pack(fill="x", padx=18, pady=16)
    tk.Label(inner, text="免費用戶單次遠端 5 分鐘", bg=ACCENT_SOFT, fg=ACCENT, font=ui_font(10, bold=True), padx=10, pady=4).pack(anchor="w")
    tk.Label(
        inner,
        text=f"會員依付款天數使用，單次不限時長。免費版最長 {FREE_SESSION_SECONDS // 60} 分鐘。",
        bg=CARD,
        fg=MUTED,
        font=ui_font(10),
        wraplength=440,
        justify="left",
        anchor="w",
    ).pack(fill="x", pady=(8, 0))

    actions = card(root, fill="both", expand=True, padx=20, pady=(0, 12))
    tk.Label(actions, text="開始使用", bg=CARD, fg=TEXT, font=ui_font(12, bold=True)).pack(anchor="w", padx=18, pady=(16, 4))

    def host() -> None:
        root.destroy()
        from peaklink.host.gui import launch

        launch()

    def viewer() -> None:
        root.destroy()
        from peaklink.viewer.gui import launch

        launch()

    def import_license() -> None:
        path = filedialog.askopenfilename(
            title="選擇授權檔",
            filetypes=[("PeakLink license", "*.peaklic *.json"), ("All", "*.*")],
        )
        if not path:
            return
        try:
            document = loads_license(open(path, encoding="utf-8").read())
            payload = verify_document(document, load_public_key(bundled_public_key_pem()))
            save_license_file(document)
            messagebox.showinfo(APP_NAME, f"已匯入\n{payload.display_status()}")
        except (OSError, LicenseError) as exc:
            messagebox.showerror(APP_NAME, str(exc))

    def start_relay() -> None:
        import threading

        from peaklink.server.app import serve

        threading.Thread(target=lambda: serve("0.0.0.0", DEFAULT_RELAY_PORT), daemon=True, name="peaklink-relay").start()
        messagebox.showinfo(
            APP_NAME,
            f"本機中繼已啟動於埠 {DEFAULT_RELAY_PORT}。\n請開防火牆，或只給區網／Taliscale 使用。",
        )

    _action_row(actions, "被控端", "產生隨機 ID 與密碼，讓客戶連進來控制這台電腦", "開啟", host, primary=True)
    tk.Frame(actions, bg=LINE, height=1).pack(fill="x", padx=18)
    _action_row(actions, "操作端", "輸入對方的 ID 與密碼，連過去遠端協助", "連線", viewer)
    tk.Frame(actions, bg=LINE, height=1).pack(fill="x", padx=18)
    _action_row(actions, "會員授權", "匯入付款後取得的 .peaklic 檔", "匯入", import_license)
    tk.Frame(actions, bg=LINE, height=1).pack(fill="x", padx=18)
    _action_row(actions, "本機中繼", "在這台電腦開中繼，給區網或測試使用", "啟動", start_relay)
    tk.Label(actions, text="", bg=CARD).pack(pady=4)

    muted(root, "Taliscale 模式需本機已登入 Tailscale。一般遠端只要有中繼即可。", wrap=460).pack(padx=24, pady=(0, 16))
    root.mainloop()


if __name__ == "__main__":
    main()
