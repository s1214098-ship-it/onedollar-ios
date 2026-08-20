"""啟動器：被控端／操作端／匯入授權。"""

from __future__ import annotations

import tkinter as tk
from tkinter import filedialog, messagebox, ttk

from peaklink.constants import APP_NAME, DEFAULT_RELAY_PORT, FREE_SESSION_SECONDS, VERSION
from peaklink.license import (
    LicenseError,
    bundled_public_key_pem,
    load_public_key,
    loads_license,
    save_license_file,
    verify_document,
)


def main() -> None:
    root = tk.Tk()
    root.title(APP_NAME)
    root.geometry("420x420")
    ttk.Label(root, text=APP_NAME, font=("Microsoft JhengHei UI", 20, "bold")).pack(pady=16)
    ttk.Label(
        root,
        text=f"v{VERSION}｜免費版單次遠端 {FREE_SESSION_SECONDS // 60} 分鐘\n會員依付款天數使用",
        justify="center",
    ).pack()

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

    ttk.Button(root, text="我要被遠端（被控端，給客戶連）", command=host).pack(fill="x", padx=40, pady=8)
    ttk.Button(root, text="我要連到別人（操作端）", command=viewer).pack(fill="x", padx=40, pady=8)
    ttk.Button(root, text="匯入會員授權檔", command=import_license).pack(fill="x", padx=40, pady=8)
    ttk.Button(root, text="啟動本機中繼伺服器", command=start_relay).pack(fill="x", padx=40, pady=8)
    ttk.Label(root, text="Taliscale 模式需本機已登入 Tailscale。").pack(pady=12)
    root.mainloop()


if __name__ == "__main__":
    main()
