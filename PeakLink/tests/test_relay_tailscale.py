from __future__ import annotations

from peaklink.relay import RelayEndpoint, rank_relays
from peaklink.tailscale import parse_status_json


def test_rank_prefers_reachable_sea():
    tw = RelayEndpoint("tw", "台灣", "https://tw", "wss://tw/ws", "tw", rtt_ms=20, ok=True)
    sea = RelayEndpoint("sea", "海外", "https://sea", "wss://sea/ws", "sea", rtt_ms=40, ok=True)
    dead = RelayEndpoint("cn", "大陸", "https://cn", "wss://cn/ws", "cn", ok=False, error="timeout")
    ranked = rank_relays([tw, sea, dead])
    assert ranked[0].id == "sea"
    assert ranked[-1].ok is False


def test_parse_tailscale_status():
    raw = """
    {
      "BackendState": "Running",
      "TailscaleIPs": ["100.64.12.34", "fd7a:115c:a1e0::1"],
      "Self": {"HostName": "desk-tw", "DNSName": "desk-tw.tailnet.ts.net."}
    }
    """
    status = parse_status_json(raw)
    assert status.ready
    assert status.ipv4 == "100.64.12.34"
    assert "100.64.12.34" in status.summary()
