"""操作端連線。"""

from __future__ import annotations

import asyncio
from collections.abc import Callable
from typing import Any

from peaklink.crypto_session import SessionCipher, derive_session_key
from peaklink.protocol import (
    CTRL_ERROR,
    CTRL_PING,
    CTRL_REGISTER_VIEWER,
    CTRL_SESSION_END,
    CTRL_SESSION_START,
    decode_json,
    encode_json,
    pack_key,
    pack_mouse,
    unpack_jpeg,
)

OnEvent = Callable[[dict[str, Any]], None]
OnFrame = Callable[[int, int, bytes], None]


class ViewerClient:
    def __init__(
        self,
        *,
        session_id: str,
        password: str,
        relay_ws: str,
        on_event: OnEvent | None = None,
        on_frame: OnFrame | None = None,
    ) -> None:
        self.session_id = session_id
        self.password = password
        self.relay_ws = relay_ws
        self.on_event = on_event or (lambda _e: None)
        self.on_frame = on_frame or (lambda _w, _h, _j: None)
        self.cipher = SessionCipher(derive_session_key(session_id, password))
        self._ws = None
        self.connected = False
        self.frame_size = (0, 0)

    async def stop(self) -> None:
        self.connected = False
        if self._ws is not None:
            await self._ws.close()

    async def send_mouse(self, action: int, x: int, y: int, button: int = 0, wheel: int = 0) -> None:
        if self._ws is None or not self.connected:
            return
        await self._ws.send(self.cipher.encrypt(pack_mouse(action, x, y, button, wheel)))

    async def send_key(self, down: bool, vk: int, text: str = "") -> None:
        if self._ws is None or not self.connected:
            return
        await self._ws.send(self.cipher.encrypt(pack_key(down, vk, text)))

    async def run(self) -> None:
        from websockets.asyncio.client import connect as ws_connect

        hello = {
            "type": CTRL_REGISTER_VIEWER,
            "session_id": self.session_id,
            "password": self.password,
        }
        async with ws_connect(self.relay_ws, max_size=8 * 1024 * 1024) as ws:
            self._ws = ws
            await ws.send(encode_json(hello))
            ping = asyncio.create_task(self._ping())
            try:
                async for raw in ws:
                    if isinstance(raw, bytes):
                        try:
                            payload = self.cipher.decrypt(raw)
                            width, height, jpeg = unpack_jpeg(payload)
                            self.frame_size = (width, height)
                            self.on_frame(width, height, jpeg)
                        except Exception:  # noqa: BLE001
                            continue
                        continue
                    data = decode_json(raw)
                    kind = data.get("type")
                    if kind == CTRL_ERROR:
                        self.on_event(data)
                        break
                    if kind == CTRL_SESSION_START:
                        self.connected = True
                    elif kind == CTRL_SESSION_END:
                        self.connected = False
                    self.on_event(data)
            finally:
                ping.cancel()
                self.connected = False
                self._ws = None

    async def _ping(self) -> None:
        while True:
            await asyncio.sleep(15)
            if self._ws is not None:
                await self._ws.send(encode_json({"type": CTRL_PING}))
