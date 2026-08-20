"""Taliscale（Tailscale）相容模式：偵測 100.x 網址與 CLI 狀態。"""

from __future__ import annotations

import ipaddress
import json
import shutil
import subprocess
from dataclasses import dataclass
from typing import Any


@dataclass
class TailscaleStatus:
    installed: bool
    running: bool
    ipv4: str | None
    hostname: str | None
    magicdns: str | None
    backend: str | None
    error: str | None = None

    @property
    def ready(self) -> bool:
        return bool(self.running and self.ipv4)

    def summary(self) -> str:
        if not self.installed:
            return "未安裝 Tailscale／Taliscale"
        if not self.running:
            return "已安裝但未連上 Tailscale"
        if self.ipv4:
            extra = f"（{self.hostname}）" if self.hostname else ""
            return f"Taliscale 就緒 {self.ipv4}{extra}"
        return "Tailscale 連線中但尚無 IP"


def _is_tailscale_ip(value: str) -> bool:
    try:
        ip = ipaddress.ip_address(value)
    except ValueError:
        return False
    return ip in ipaddress.ip_network("100.64.0.0/10")


def parse_status_json(raw: str) -> TailscaleStatus:
    data: dict[str, Any] = json.loads(raw)
    backend = str(data.get("BackendState") or data.get("backendState") or "")
    running = backend.upper() == "RUNNING"
    ipv4 = None
    addrs = data.get("TailscaleIPs") or data.get("tailscaleIPs") or []
    for item in addrs:
        text = str(item)
        if _is_tailscale_ip(text.split("/")[0]):
            ipv4 = text.split("/")[0]
            break
        if ":" not in text and text.startswith("100."):
            ipv4 = text.split("/")[0]
            break
    self_node = data.get("Self") or {}
    hostname = self_node.get("HostName") or self_node.get("DNSName") or data.get("Self", {}).get("HostName")
    magic = self_node.get("DNSName") or data.get("MagicDNSSuffix")
    return TailscaleStatus(
        installed=True,
        running=running or bool(ipv4),
        ipv4=ipv4,
        hostname=str(hostname) if hostname else None,
        magicdns=str(magic).rstrip(".") if magic else None,
        backend=backend or None,
    )


def probe_tailscale(timeout: float = 3.0) -> TailscaleStatus:
    binary = shutil.which("tailscale") or shutil.which("tailscale.exe")
    if not binary:
        return TailscaleStatus(
            installed=False,
            running=False,
            ipv4=None,
            hostname=None,
            magicdns=None,
            backend=None,
        )
    try:
        proc = subprocess.run(
            [binary, "status", "--json"],
            capture_output=True,
            text=True,
            timeout=timeout,
            check=False,
        )
    except (OSError, subprocess.SubprocessError) as exc:
        return TailscaleStatus(
            installed=True,
            running=False,
            ipv4=None,
            hostname=None,
            magicdns=None,
            backend=None,
            error=str(exc),
        )
    if proc.returncode != 0 or not proc.stdout.strip():
        return TailscaleStatus(
            installed=True,
            running=False,
            ipv4=None,
            hostname=None,
            magicdns=None,
            backend=None,
            error=(proc.stderr or "tailscale status 失敗").strip()[:200],
        )
    try:
        return parse_status_json(proc.stdout)
    except (json.JSONDecodeError, TypeError, ValueError) as exc:
        return TailscaleStatus(
            installed=True,
            running=False,
            ipv4=None,
            hostname=None,
            magicdns=None,
            backend=None,
            error=f"無法解析 Tailscale 狀態：{exc}",
        )
