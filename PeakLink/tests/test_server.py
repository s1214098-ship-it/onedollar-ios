from __future__ import annotations

from fastapi.testclient import TestClient

from peaklink.ids import password_hash
from peaklink.protocol import decode_json, encode_json
from peaklink.server.app import app, registry


def test_health():
    client = TestClient(app)
    resp = client.get("/health")
    assert resp.status_code == 200
    assert resp.json()["ok"] is True


def test_host_register_and_viewer_wrong_password():
    client = TestClient(app)

    async def _clear():
        await registry.clear()

    import asyncio

    asyncio.run(_clear())

    with client.websocket_connect("/ws") as host:
        host.send_text(
            encode_json(
                {
                    "type": "register_host",
                    "session_id": "123456789",
                    "password_hash": password_hash("123456789", "654321"),
                    "display_name": "demo",
                    "modes": ["classic", "taliscale"],
                    "consent_required": False,
                    "license": {"payload": {"edition": "free"}, "signature": ""},
                }
            )
        )
        registered = decode_json(host.receive_text())
        assert registered["type"] == "registered"
        assert registered["role"] == "host"

        with client.websocket_connect("/ws") as viewer:
            viewer.send_text(
                encode_json(
                    {
                        "type": "register_viewer",
                        "session_id": "123456789",
                        "password": "000000",
                    }
                )
            )
            err = decode_json(viewer.receive_text())
            assert err["type"] == "error"
            assert err["code"] == "auth"


def test_viewer_not_found():
    client = TestClient(app)
    with client.websocket_connect("/ws") as viewer:
        viewer.send_text(
            encode_json({"type": "register_viewer", "session_id": "999999999", "password": "1"})
        )
        err = decode_json(viewer.receive_text())
        assert err["code"] == "not_found"
