"""兩岸中繼選擇：探測可達端點，優先共同可達的海外／自訂中繼。"""

from __future__ import annotations

import time
from dataclasses import dataclass

import httpx

from peaklink.constants import DEFAULT_RELAYS


@dataclass
class RelayEndpoint:
    id: str
    label: str
    http: str
    ws: str
    region: str
    rtt_ms: float | None = None
    ok: bool = False
    error: str | None = None


def configured_relays(extra: list[dict] | None = None) -> list[RelayEndpoint]:
    rows = list(DEFAULT_RELAYS)
    if extra:
        rows.extend(extra)
    out: list[RelayEndpoint] = []
    seen: set[str] = set()
    for row in rows:
        rid = str(row["id"])
        if rid in seen:
            continue
        seen.add(rid)
        out.append(
            RelayEndpoint(
                id=rid,
                label=str(row["label"]),
                http=str(row["http"]).rstrip("/"),
                ws=str(row["ws"]),
                region=str(row.get("region") or ""),
            )
        )
    return out


def probe_one(endpoint: RelayEndpoint, timeout: float = 2.5) -> RelayEndpoint:
    url = f"{endpoint.http}/health"
    started = time.perf_counter()
    try:
        with httpx.Client(timeout=timeout, follow_redirects=True) as client:
            resp = client.get(url)
        elapsed = (time.perf_counter() - started) * 1000
        if resp.status_code == 200:
            endpoint.ok = True
            endpoint.rtt_ms = round(elapsed, 1)
            return endpoint
        endpoint.ok = False
        endpoint.error = f"HTTP {resp.status_code}"
        endpoint.rtt_ms = round(elapsed, 1)
    except httpx.HTTPError as exc:
        endpoint.ok = False
        endpoint.error = str(exc)[:180]
    return endpoint


def rank_relays(endpoints: list[RelayEndpoint]) -> list[RelayEndpoint]:
    """可達者依 RTT 排序；同 RTT 時海外 dual-shore 優先於單一地區。"""

    def key(item: RelayEndpoint) -> tuple:
        region_bonus = 0 if item.region in {"sea", "dual", "local"} else 1
        rtt = item.rtt_ms if item.ok and item.rtt_ms is not None else 9_999_999
        return (0 if item.ok else 1, region_bonus, rtt)

    return sorted(endpoints, key=key)


def pick_relay(
    *,
    preferred_id: str = "auto",
    extra: list[dict] | None = None,
    timeout: float = 2.5,
) -> tuple[RelayEndpoint | None, list[RelayEndpoint]]:
    endpoints = configured_relays(extra)
    probed = [probe_one(ep, timeout=timeout) for ep in endpoints]
    if preferred_id and preferred_id != "auto":
        for item in probed:
            if item.id == preferred_id:
                return (item if item.ok else None), probed
        return None, probed
    ranked = rank_relays(probed)
    best = ranked[0] if ranked and ranked[0].ok else None
    return best, probed
