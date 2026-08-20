"""WebSocket 控制訊息與二進位畫面／輸入框。"""

from __future__ import annotations

import json
import struct
from typing import Any

# 二進位框型別
FRAME_JPEG = 0x01
FRAME_MOUSE = 0x02
FRAME_KEY = 0x03
FRAME_CLIPBOARD = 0x04

MOUSE_MOVE = 1
MOUSE_DOWN = 2
MOUSE_UP = 3
MOUSE_WHEEL = 4

CTRL_REGISTER_HOST = "register_host"
CTRL_REGISTER_VIEWER = "register_viewer"
CTRL_REGISTERED = "registered"
CTRL_VIEWER_WAITING = "viewer_waiting"
CTRL_CONSENT = "consent"
CTRL_SESSION_START = "session_start"
CTRL_SESSION_TICK = "session_tick"
CTRL_SESSION_END = "session_end"
CTRL_ERROR = "error"
CTRL_PING = "ping"
CTRL_PONG = "pong"
CTRL_MODE = "mode"
CTRL_DIRECT_INFO = "direct_info"


def encode_json(message: dict[str, Any]) -> str:
    return json.dumps(message, ensure_ascii=False, separators=(",", ":"))


def decode_json(raw: str) -> dict[str, Any]:
    data = json.loads(raw)
    if not isinstance(data, dict):
        raise ValueError("控制訊息必須是物件")
    return data


def pack_jpeg(width: int, height: int, jpeg: bytes) -> bytes:
    return struct.pack("!BHH", FRAME_JPEG, width, height) + jpeg


def unpack_jpeg(payload: bytes) -> tuple[int, int, bytes]:
    if len(payload) < 5 or payload[0] != FRAME_JPEG:
        raise ValueError("不是 JPEG 畫面框")
    _, width, height = struct.unpack("!BHH", payload[:5])
    return width, height, payload[5:]


def pack_mouse(action: int, x: int, y: int, button: int = 0, wheel: int = 0) -> bytes:
    return struct.pack("!BBhhbb", FRAME_MOUSE, action, int(x), int(y), int(button), int(wheel))


def unpack_mouse(payload: bytes) -> tuple[int, int, int, int, int]:
    if len(payload) != 8 or payload[0] != FRAME_MOUSE:
        raise ValueError("不是滑鼠框")
    _, action, x, y, button, wheel = struct.unpack("!BBhhbb", payload)
    return action, x, y, button, wheel


def pack_key(down: bool, vk: int, text: str = "") -> bytes:
    encoded = text.encode("utf-8")
    if len(encoded) > 32:
        encoded = encoded[:32]
    return struct.pack("!BBH", FRAME_KEY, 1 if down else 0, vk) + bytes([len(encoded)]) + encoded


def unpack_key(payload: bytes) -> tuple[bool, int, str]:
    if len(payload) < 5 or payload[0] != FRAME_KEY:
        raise ValueError("不是鍵盤框")
    _, down, vk = struct.unpack("!BBH", payload[:4])
    n = payload[4]
    text = payload[5 : 5 + n].decode("utf-8", errors="replace")
    return bool(down), vk, text
