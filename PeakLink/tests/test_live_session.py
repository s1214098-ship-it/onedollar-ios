from __future__ import annotations

import threading
import time

import httpx
import pytest
import uvicorn
from websockets.asyncio.client import connect

from peaklink.ids import password_hash
from peaklink.protocol import decode_json, encode_json
from peaklink.server.app import app, registry

PORT = 18791


@pytest.fixture(scope="module")
def live_server():
    config = uvicorn.Config(app, host="127.0.0.1", port=PORT, log_level="error")
    server = uvicorn.Server(config)
    thread = threading.Thread(target=server.run, daemon=True)
    thread.start()
    deadline = time.time() + 8
    while time.time() < deadline:
        try:
            resp = httpx.get(f"http://127.0.0.1:{PORT}/health", timeout=0.5)
            if resp.status_code == 200:
                break
        except httpx.HTTPError:
            time.sleep(0.1)
    else:
        raise RuntimeError("中繼測試伺服器沒有起來")
    yield f"ws://127.0.0.1:{PORT}/ws"
    server.should_exit = True


@pytest.mark.asyncio
async def test_classic_session_start_without_consent(live_server):
    await registry.clear()
    sid = "246801357"
    pw = "112233"
    async with connect(live_server) as host, connect(live_server) as viewer:
        await host.send(
            encode_json(
                {
                    "type": "register_host",
                    "session_id": sid,
                    "password_hash": password_hash(sid, pw),
                    "display_name": "host-a",
                    "modes": ["classic"],
                    "consent_required": False,
                    "license": {"payload": {"edition": "free"}, "signature": ""},
                }
            )
        )
        host_hello = decode_json(await host.recv())
        assert host_hello["type"] == "registered"
        await viewer.send(
            encode_json({"type": "register_viewer", "session_id": sid, "password": pw})
        )
        viewer_msgs = [decode_json(await viewer.recv())]
        if viewer_msgs[0]["type"] == "registered":
            viewer_msgs.append(decode_json(await viewer.recv()))
        types = {m["type"] for m in viewer_msgs}
        assert "registered" in types
        assert "session_start" in types or any(m.get("type") == "session_start" for m in viewer_msgs)
        # 被控端也應收到開始
        found = False
        for _ in range(5):
            msg = decode_json(await host.recv())
            if msg.get("type") == "session_start":
                found = True
                assert msg.get("edition") == "free"
                break
        assert found
