"""連線房間：一臺被控端配一位操作端，伺服器只轉送。"""

from __future__ import annotations

import asyncio
import time
from dataclasses import dataclass, field
from typing import Any

from fastapi import WebSocket

from peaklink.session_policy import SessionBudget


class RoomError(Exception):
    def __init__(self, code: str, message: str) -> None:
        super().__init__(message)
        self.code = code
        self.message = message


@dataclass
class Room:
    session_id: str
    password_hash: str
    edition: str
    budget: SessionBudget
    host: WebSocket | None = None
    viewer: WebSocket | None = None
    display_name: str = ""
    modes: list[str] = field(default_factory=lambda: ["classic"])
    tailscale_ip: str | None = None
    consent_required: bool = True
    consented: bool = False
    started_at: float | None = None
    lock: asyncio.Lock = field(default_factory=asyncio.Lock)

    def elapsed(self) -> float:
        if self.started_at is None:
            return 0.0
        return time.monotonic() - self.started_at

    def remaining(self) -> int | None:
        return self.budget.remaining(self.elapsed())

    def reset_session(self) -> None:
        self.viewer = None
        self.started_at = None
        self.consented = not self.consent_required

    def snapshot(self) -> dict[str, Any]:
        return {
            "session_id": self.session_id,
            "edition": self.edition,
            "display_name": self.display_name,
            "modes": self.modes,
            "tailscale_ip": self.tailscale_ip,
            "remaining": self.remaining(),
            "busy": self.viewer is not None,
        }


class RoomRegistry:
    def __init__(self) -> None:
        self._rooms: dict[str, Room] = {}
        self._lock = asyncio.Lock()

    async def register_host(self, room: Room) -> Room:
        async with self._lock:
            existing = self._rooms.get(room.session_id)
            if existing and existing.host is not None:
                raise RoomError("id_in_use", "這個遠端 ID 正在使用中，請重新產生")
            self._rooms[room.session_id] = room
            return room

    async def get(self, session_id: str) -> Room | None:
        async with self._lock:
            return self._rooms.get(session_id)

    async def drop_if_host(self, session_id: str, host: WebSocket) -> None:
        async with self._lock:
            room = self._rooms.get(session_id)
            if room and room.host is host:
                self._rooms.pop(session_id, None)

    async def list_public(self) -> list[dict[str, Any]]:
        async with self._lock:
            return [room.snapshot() for room in self._rooms.values()]

    async def clear(self) -> None:
        async with self._lock:
            self._rooms.clear()
