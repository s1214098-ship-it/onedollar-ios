from __future__ import annotations

from peaklink.crypto_session import SessionCipher, derive_session_key
from peaklink.ids import format_session_id, generate_session_id, password_hash, verify_password
from peaklink.protocol import (
    pack_jpeg,
    pack_key,
    pack_mouse,
    unpack_jpeg,
    unpack_key,
    unpack_mouse,
    MOUSE_DOWN,
)


def test_session_id_format():
    sid = generate_session_id()
    assert len(sid) == 9 and sid.isdigit()
    shown = format_session_id(sid)
    assert shown.replace(" ", "") == sid


def test_password_hash_roundtrip():
    assert verify_password("123456789", "654321", password_hash("123456789", "654321"))
    assert not verify_password("123456789", "000000", password_hash("123456789", "654321"))


def test_jpeg_and_input_frames():
    blob = pack_jpeg(320, 240, b"\xff\xd8fake")
    w, h, data = unpack_jpeg(blob)
    assert (w, h, data) == (320, 240, b"\xff\xd8fake")
    mouse = pack_mouse(MOUSE_DOWN, 10, 20, 1, 0)
    action, x, y, button, wheel = unpack_mouse(mouse)
    assert (action, x, y, button, wheel) == (MOUSE_DOWN, 10, 20, 1, 0)
    key = pack_key(True, 65, "a")
    down, vk, text = unpack_key(key)
    assert down is True and vk == 65 and text == "a"


def test_session_cipher_roundtrip():
    key = derive_session_key("123456789", "pw")
    cipher = SessionCipher(key)
    packed = pack_jpeg(8, 8, b"hello")
    out = cipher.decrypt(cipher.encrypt(packed))
    assert out == packed
    other = SessionCipher(derive_session_key("123456789", "other"))
    try:
        other.decrypt(cipher.encrypt(packed))
        raise AssertionError("should fail")
    except Exception:
        pass
