"""被控端連線代理：向中繼註冊，並可在 Taliscale IP 上開直連埠。"""

from __future__ import annotations

import asyncio
import contextlib
import logging
from collections.abc import Callable
from typing import Any

from peaklink.constants import DEFAULT_FPS
from peaklink.crypto_session import SessionCipher, derive_session_key
from peaklink.ids import password_hash
from peaklink.protocol import (
    CTRL_CONSENT,
    CTRL_ERROR,
    CTRL_PING,
    CTRL_REGISTER_HOST,
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

logger = logging.getLogger("peaklink.host")

OnEvent = Callable[[dict[str, Any]], None]


class HostAgent:
    def __init__(
        self,
        *,
        session_id: str,
        password: str,
        relay_ws: str,
        license_document: dict[str, Any] | None,
        display_name: str,
        modes: list[str],
        tailscale_ip: str | None,
        consent_required: bool,
        jpeg_quality: int,
        fps: int,
        max_width: int,
        on_event: OnEvent | None = None,
    ) -> None:
        self.session_id = session_id
        self.password = password
        self.relay_ws = relay_ws
        self.license_document = license_document
        self.display_name = display_name
        self.modes = modes
        self.tailscale_ip = tailscale_ip
        self.consent_required = consent_required
        self.jpeg_quality = jpeg_quality
        self.fps = max(1, fps or DEFAULT_FPS)
        self.max_width = max_width
        self.on_event = on_event or (lambda _e: None)
        self.cipher = SessionCipher(derive_session_key(session_id, password))
        self._ws = None
        self._streaming = False
        self._stop = asyncio.Event()
        self._injector = None
        self._direct_allowed: bool | None = None

    def _emit(self, event: dict[str, Any]) -> None:
        try:
            self.on_event(event)
        except Exception:  # noqa: BLE001
            logger.debug("on_event failed", exc_info=True)

    async def stop(self) -> None:
        self._stop.set()
        if self._ws is not None:
            await self._ws.close()

    async def send_consent(self, allowed: bool) -> None:
        self._direct_allowed = allowed
        if self._ws is None:
            return
        await self._ws.send(encode_json({"type": CTRL_CONSENT, "allowed": allowed}))

    async def run(self) -> None:
        from websockets.asyncio.client import connect as ws_connect

        hello = {
            "type": CTRL_REGISTER_HOST,
            "session_id": self.session_id,
            "password_hash": password_hash(self.session_id, self.password),
            "display_name": self.display_name,
            "modes": self.modes,
            "tailscale_ip": self.tailscale_ip,
            "consent_required": self.consent_required,
            "license": self.license_document,
        }
        async with ws_connect(self.relay_ws, max_size=8 * 1024 * 1024) as ws:
            self._ws = ws
            await ws.send(encode_json(hello))
            stream_task = asyncio.create_task(self._stream_loop())
            ping_task = asyncio.create_task(self._ping_loop())
            try:
                async for raw in ws:
                    if isinstance(raw, bytes):
                        await self._handle_binary(raw)
                        continue
                    data = decode_json(raw)
                    kind = data.get("type")
                    if kind == CTRL_ERROR:
                        self._emit(data)
                        break
                    if kind == CTRL_VIEWER_WAITING:
                        self._emit(data)
                    elif kind == CTRL_SESSION_START:
                        self._streaming = True
                        self._emit(data)
                    elif kind == CTRL_SESSION_END:
                        self._streaming = False
                        self._emit(data)
                    else:
                        self._emit(data)
                    if self._stop.is_set():
                        break
            finally:
                self._streaming = False
                stream_task.cancel()
                ping_task.cancel()
                self._ws = None

    async def _ping_loop(self) -> None:
        while not self._stop.is_set():
            if self._ws is not None:
                with contextlib.suppress(Exception):
                    await self._ws.send(encode_json({"type": CTRL_PING}))
            await asyncio.sleep(15)

    async def _stream_loop(self) -> None:
        from peaklink.host.capture import grab_jpeg
        from peaklink.host.input_inject import InputInjector

        self._injector = InputInjector()
        interval = 1.0 / self.fps
        while not self._stop.is_set():
            if self._streaming and self._ws is not None:
                try:
                    width, height, jpeg = grab_jpeg(
                        max_width=self.max_width,
                        quality=self.jpeg_quality,
                    )
                    self._injector.set_frame_size(width, height)
                    packed = pack_jpeg(width, height, jpeg)
                    await self._ws.send(self.cipher.encrypt(packed))
                except Exception:  # noqa: BLE001
                    logger.debug("capture failed", exc_info=True)
            await asyncio.sleep(interval)

    async def _handle_binary(self, blob: bytes) -> None:
        if self._injector is None:
            return
        try:
            payload = self.cipher.decrypt(blob)
        except Exception:  # noqa: BLE001
            return
        kind = payload[0]
        if kind == FRAME_MOUSE:
            action, x, y, button, wheel = unpack_mouse(payload)
            self._injector.apply_mouse(action, x, y, button, wheel)
        elif kind == FRAME_KEY:
            down, vk, text = unpack_key(payload)
            self._injector.apply_key(down, vk, text)


class DirectHostServer:
    """Taliscale／區網直連：在本機埠接受與中繼相同的 WebSocket 協定。"""

    def __init__(self, agent: HostAgent, host: str, port: int) -> None:
        self.agent = agent
        self.host = host
        self.port = port
        self._server = None

    async def start(self) -> None:
        from peaklink.host.direct_room import handle_direct_client
        from websockets.asyncio.server import serve as ws_serve

        async def handler(connection):
            await handle_direct_client(connection, self.agent)

        self._server = await ws_serve(handler, self.host, self.port, max_size=8 * 1024 * 1024)

    async def stop(self) -> None:
        if self._server is not None:
            self._server.close()
            await self._server.wait_closed()
            self._server = None
