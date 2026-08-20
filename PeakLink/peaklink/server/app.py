"""峰連中繼／信令伺服器：配對被控端與操作端，轉送加密畫面。"""

from __future__ import annotations

import argparse
import asyncio
import logging
import time
from typing import Any

from fastapi import FastAPI, WebSocket, WebSocketDisconnect
from fastapi.responses import JSONResponse, PlainTextResponse

from peaklink import __version__
from peaklink.constants import APP_NAME, DEFAULT_RELAY_PORT, FREE_SESSION_SECONDS
from peaklink.ids import verify_password
from peaklink.license import LicenseError, LicensePayload, bundled_public_key_pem, load_public_key, verify_document
from peaklink.protocol import (
    CTRL_CONSENT,
    CTRL_ERROR,
    CTRL_PING,
    CTRL_PONG,
    CTRL_REGISTER_HOST,
    CTRL_REGISTER_VIEWER,
    CTRL_REGISTERED,
    CTRL_SESSION_END,
    CTRL_SESSION_START,
    CTRL_SESSION_TICK,
    CTRL_VIEWER_WAITING,
    decode_json,
    encode_json,
)
from peaklink.server.rooms import Room, RoomError, RoomRegistry
from peaklink.session_policy import budget_from_license

logger = logging.getLogger("peaklink.server")
registry = RoomRegistry()
app = FastAPI(title=APP_NAME, version=__version__)


def _error(code: str, message: str) -> str:
    return encode_json({"type": CTRL_ERROR, "code": code, "message": message})


async def _send(ws: WebSocket | None, message: dict[str, Any]) -> None:
    if ws is None:
        return
    try:
        await ws.send_text(encode_json(message))
    except Exception:  # noqa: BLE001
        logger.debug("send failed", exc_info=True)


def _payload_from_host(data: dict[str, Any]) -> LicensePayload:
    public = load_public_key(bundled_public_key_pem())
    document = data.get("license")
    if not document:
        return LicensePayload(
            edition="free",
            customer_name="免費用戶",
            issued_at="",
            expires_at=None,
            max_session_seconds=FREE_SESSION_SECONDS,
            package="free",
            license_id="free",
        )
    if not isinstance(document, dict):
        raise LicenseError("授權格式錯誤")
    payload = document.get("payload") if isinstance(document.get("payload"), dict) else {}
    if payload.get("edition") == "free" and not document.get("signature"):
        return LicensePayload(
            edition="free",
            customer_name=str(payload.get("customer_name") or "免費用戶"),
            issued_at="",
            expires_at=None,
            max_session_seconds=FREE_SESSION_SECONDS,
            package="free",
            license_id="free",
        )
    return verify_document(document, public)


@app.get("/health")
async def health() -> dict[str, Any]:
    return {"ok": True, "app": APP_NAME, "version": __version__}


@app.get("/")
async def root() -> PlainTextResponse:
    return PlainTextResponse(f"{APP_NAME} 中繼 {__version__}\n")


@app.get("/rooms")
async def rooms() -> JSONResponse:
    return JSONResponse({"rooms": await registry.list_public()})


async def _tick_loop(room: Room) -> None:
    try:
        while room.host is not None and room.viewer is not None:
            remaining = room.remaining()
            payload = {
                "type": CTRL_SESSION_TICK,
                "remaining": remaining,
                "edition": room.edition,
            }
            await _send(room.host, payload)
            await _send(room.viewer, payload)
            if remaining is not None and remaining <= 0:
                end = {
                    "type": CTRL_SESSION_END,
                    "reason": room.budget.reason,
                    "message": "免費版單次遠端已達 5 分鐘上限，升級會員後單次不限時長。"
                    if room.edition == "free"
                    else "會員使用期間已結束，請續費。",
                }
                await _send(room.host, end)
                await _send(room.viewer, end)
                viewer = room.viewer
                room.reset_session()
                if viewer is not None:
                    await viewer.close()
                break
            await asyncio.sleep(1)
    except Exception:  # noqa: BLE001
        logger.debug("tick loop ended", exc_info=True)


def _dest_for(room: Room, role: str) -> WebSocket | None:
    return room.viewer if role == "host" else room.host


async def _relay_loop(source: WebSocket, room: Room, role: str) -> None:
    try:
        while True:
            message = await source.receive()
            if message["type"] == "websocket.disconnect":
                break
            dest = _dest_for(room, role)
            remaining = room.remaining()
            if room.started_at is not None and remaining is not None and remaining <= 0:
                continue
            if message.get("text") is not None:
                raw = message["text"]
                try:
                    data = decode_json(raw)
                except ValueError:
                    continue
                if data.get("type") == CTRL_PING:
                    await source.send_text(encode_json({"type": CTRL_PONG}))
                    continue
                if data.get("type") == CTRL_CONSENT and role == "host":
                    allowed = bool(data.get("allowed"))
                    room.consented = allowed
                    if allowed:
                        room.started_at = room.started_at or time.monotonic()
                        start = {
                            "type": CTRL_SESSION_START,
                            "edition": room.edition,
                            "remaining": room.remaining(),
                            "display_name": room.display_name,
                        }
                        await _send(room.host, start)
                        await _send(room.viewer, start)
                    else:
                        await _send(
                            room.viewer,
                            {
                                "type": CTRL_SESSION_END,
                                "reason": "denied",
                                "message": "對方拒絕了這次遠端連線",
                            },
                        )
                        if room.viewer is not None:
                            await room.viewer.close()
                        room.reset_session()
                    continue
                if dest is not None:
                    await dest.send_text(raw)
            elif message.get("bytes") is not None:
                if dest is None or not room.consented:
                    continue
                await dest.send_bytes(message["bytes"])
    except WebSocketDisconnect:
        return
    except Exception:  # noqa: BLE001
        logger.debug("relay loop ended (%s)", role, exc_info=True)


@app.websocket("/ws")
async def websocket_endpoint(ws: WebSocket) -> None:
    await ws.accept()
    room: Room | None = None
    role = ""
    try:
        first = await ws.receive_text()
        data = decode_json(first)
        msg_type = data.get("type")
        if msg_type == CTRL_REGISTER_HOST:
            session_id = str(data.get("session_id") or "")
            password_hash = str(data.get("password_hash") or "")
            if len(session_id) != 9 or not session_id.isdigit():
                await ws.send_text(_error("bad_id", "遠端 ID 必須是 9 位數字"))
                await ws.close()
                return
            try:
                license_payload = _payload_from_host(data)
            except LicenseError as exc:
                await ws.send_text(_error("license", str(exc)))
                await ws.close()
                return
            if license_payload.is_expired():
                await ws.send_text(_error("expired", "授權已過期"))
                await ws.close()
                return
            room = Room(
                session_id=session_id,
                password_hash=password_hash,
                edition=license_payload.edition,
                budget=budget_from_license(license_payload),
                host=ws,
                display_name=str(data.get("display_name") or ""),
                modes=list(data.get("modes") or ["classic"]),
                tailscale_ip=data.get("tailscale_ip"),
                consent_required=bool(data.get("consent_required", True)),
                consented=not bool(data.get("consent_required", True)),
            )
            try:
                await registry.register_host(room)
            except RoomError as exc:
                await ws.send_text(_error(exc.code, exc.message))
                await ws.close()
                return
            role = "host"
            await _send(
                ws,
                {
                    "type": CTRL_REGISTERED,
                    "role": "host",
                    "session_id": session_id,
                    "edition": license_payload.edition,
                    "remaining": room.remaining(),
                },
            )
            await _relay_loop(ws, room, "host")
        elif msg_type == CTRL_REGISTER_VIEWER:
            session_id = str(data.get("session_id") or "")
            password = str(data.get("password") or "")
            room = await registry.get(session_id)
            if room is None or room.host is None:
                await ws.send_text(_error("not_found", "找不到這個遠端 ID，請確認被控端已上線"))
                await ws.close()
                return
            if room.viewer is not None:
                await ws.send_text(_error("busy", "這個 ID 已有人連線中"))
                await ws.close()
                return
            if not verify_password(session_id, password, room.password_hash):
                await ws.send_text(_error("auth", "連線密碼不正確"))
                await ws.close()
                return
            room.viewer = ws
            role = "viewer"
            await _send(
                ws,
                {
                    "type": CTRL_REGISTERED,
                    "role": "viewer",
                    "session_id": session_id,
                    "edition": room.edition,
                    "modes": room.modes,
                    "tailscale_ip": room.tailscale_ip,
                    "display_name": room.display_name,
                },
            )
            if room.consent_required and not room.consented:
                await _send(
                    room.host,
                    {
                        "type": CTRL_VIEWER_WAITING,
                        "message": "有人請求遠端連線，請在被控端允許或拒絕",
                    },
                )
                await _send(
                    ws,
                    {
                        "type": CTRL_VIEWER_WAITING,
                        "message": "等待對方允許連線…",
                    },
                )
            else:
                room.consented = True
                room.started_at = room.started_at or time.monotonic()
                start = {
                    "type": CTRL_SESSION_START,
                    "edition": room.edition,
                    "remaining": room.remaining(),
                    "display_name": room.display_name,
                }
                await _send(room.host, start)
                await _send(room.viewer, start)

            tick = asyncio.create_task(_tick_loop(room))
            try:
                await _relay_loop(ws, room, "viewer")
            finally:
                tick.cancel()
        else:
            await ws.send_text(_error("bad_type", "請先註冊為被控端或操作端"))
            await ws.close()
    except WebSocketDisconnect:
        pass
    except Exception:  # noqa: BLE001
        logger.exception("websocket error")
    finally:
        if room is not None:
            if role == "viewer":
                other = room.host
                room.reset_session()
                await _send(
                    other,
                    {"type": CTRL_SESSION_END, "reason": "viewer_left", "message": "操作端已離開"},
                )
            elif role == "host":
                other = room.viewer
                await registry.drop_if_host(room.session_id, ws)
                await _send(
                    other,
                    {"type": CTRL_SESSION_END, "reason": "host_left", "message": "被控端已離線"},
                )


def serve(host: str = "0.0.0.0", port: int = DEFAULT_RELAY_PORT) -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    import uvicorn

    # 用 app 物件而不是字串路徑，PyInstaller 打包後才找得到。
    uvicorn.run(app, host=host, port=port, reload=False, log_level="info")


def main() -> None:
    parser = argparse.ArgumentParser(description=f"{APP_NAME} 中繼伺服器")
    parser.add_argument("--host", default="0.0.0.0")
    parser.add_argument("--port", type=int, default=DEFAULT_RELAY_PORT)
    args = parser.parse_args()
    serve(args.host, args.port)


if __name__ == "__main__":
    main()
