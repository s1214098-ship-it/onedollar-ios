"""Taliscale 直連時的本機配對（不經過公共中繼）。"""

from __future__ import annotations

import asyncio
import time

from peaklink.crypto_session import SessionCipher, derive_session_key
from peaklink.ids import password_hash, verify_password
from peaklink.license import LicensePayload, bundled_public_key_pem, free_payload, load_public_key, verify_document
from peaklink.protocol import (
    CTRL_CONSENT,
    CTRL_ERROR,
    CTRL_REGISTER_VIEWER,
    CTRL_REGISTERED,
    CTRL_SESSION_END,
    CTRL_SESSION_START,
    CTRL_VIEWER_WAITING,
    FRAME_KEY,
    FRAME_MOUSE,
    decode_json,
    encode_json,
    pack_jpeg,
    unpack_key,
    unpack_mouse,
)
from peaklink.session_policy import SessionBudget, budget_from_license


async def handle_direct_client(ws, agent) -> None:
    first = await ws.recv()
    if isinstance(first, bytes):
        await ws.send(encode_json({"type": CTRL_ERROR, "code": "bad_type", "message": "請先註冊"}))
        return
    data = decode_json(first)
    if data.get("type") != CTRL_REGISTER_VIEWER:
        await ws.send(encode_json({"type": CTRL_ERROR, "code": "bad_type", "message": "直連請用操作端註冊"}))
        return
    if str(data.get("session_id") or "") != agent.session_id:
        await ws.send(encode_json({"type": CTRL_ERROR, "code": "bad_id", "message": "遠端 ID 不符"}))
        return
    if not verify_password(agent.session_id, str(data.get("password") or ""), password_hash(agent.session_id, agent.password)):
        await ws.send(encode_json({"type": CTRL_ERROR, "code": "auth", "message": "連線密碼不正確"}))
        return

    license_payload: LicensePayload = free_payload()
    document = agent.license_document
    if isinstance(document, dict) and (document.get("payload") or {}).get("edition") == "member":
        try:
            license_payload = verify_document(document, load_public_key(bundled_public_key_pem()))
        except Exception:  # noqa: BLE001
            license_payload = free_payload()
    budget: SessionBudget = budget_from_license(license_payload)
    consented = not agent.consent_required
    cipher = SessionCipher(derive_session_key(agent.session_id, agent.password))

    await ws.send(
        encode_json(
            {
                "type": CTRL_REGISTERED,
                "role": "viewer",
                "session_id": agent.session_id,
                "edition": license_payload.edition,
                "modes": agent.modes,
                "tailscale_ip": agent.tailscale_ip,
                "display_name": agent.display_name,
            }
        )
    )
    if not consented:
        agent._direct_allowed = None
        agent._emit({"type": CTRL_VIEWER_WAITING, "message": "Taliscale 直連請求，請允許或拒絕", "direct": True})
        await ws.send(encode_json({"type": CTRL_VIEWER_WAITING, "message": "等待對方允許連線…"}))
        for _ in range(120):
            if agent._direct_allowed is True:
                consented = True
                break
            if agent._direct_allowed is False:
                await ws.send(
                    encode_json(
                        {
                            "type": CTRL_SESSION_END,
                            "reason": "denied",
                            "message": "對方拒絕了這次遠端連線",
                        }
                    )
                )
                return
            await asyncio.sleep(0.25)
        agent._direct_allowed = None
        if not consented:
            await ws.send(encode_json({"type": CTRL_SESSION_END, "reason": "timeout", "message": "對方沒有回應"}))
            return

    started = time.monotonic()
    start_msg = {
        "type": CTRL_SESSION_START,
        "edition": license_payload.edition,
        "remaining": budget.remaining(0),
        "display_name": agent.display_name,
    }
    await ws.send(encode_json(start_msg))
    agent._emit(start_msg)
    agent._streaming = True

    from peaklink.host.capture import grab_jpeg
    from peaklink.host.input_inject import InputInjector

    injector = InputInjector()

    async def stream():
        interval = 1.0 / agent.fps
        while True:
            elapsed = time.monotonic() - started
            remaining = budget.remaining(elapsed)
            if remaining is not None and remaining <= 0:
                await ws.send(
                    encode_json(
                        {
                            "type": CTRL_SESSION_END,
                            "reason": budget.reason,
                            "message": "免費版單次遠端已達 5 分鐘上限"
                            if license_payload.edition == "free"
                            else "會員使用期間已結束",
                        }
                    )
                )
                break
            width, height, jpeg = grab_jpeg(max_width=agent.max_width, quality=agent.jpeg_quality)
            injector.set_frame_size(width, height)
            await ws.send(cipher.encrypt(pack_jpeg(width, height, jpeg)))
            await asyncio.sleep(interval)

    async def receive():
        async for raw in ws:
            if isinstance(raw, bytes):
                payload = cipher.decrypt(raw)
                if payload[0] == FRAME_MOUSE:
                    action, x, y, button, wheel = unpack_mouse(payload)
                    injector.apply_mouse(action, x, y, button, wheel)
                elif payload[0] == FRAME_KEY:
                    down, vk, text = unpack_key(payload)
                    injector.apply_key(down, vk, text)
            else:
                decode_json(raw)

    try:
        await asyncio.gather(stream(), receive())
    finally:
        agent._streaming = False
        agent._emit({"type": CTRL_SESSION_END, "reason": "direct_closed", "message": "直連已結束"})
