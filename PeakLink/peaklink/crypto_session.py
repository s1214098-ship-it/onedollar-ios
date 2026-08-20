"""連線後的畫面／輸入加密：用連線密碼做 E2E，中繼看不到畫面。"""

from __future__ import annotations

import hashlib
import os

from cryptography.hazmat.primitives.ciphers.aead import AESGCM


def derive_session_key(session_id: str, password: str) -> bytes:
    material = f"peaklink-v1|{session_id}|{password}".encode("utf-8")
    return hashlib.sha256(material).digest()


class SessionCipher:
    def __init__(self, key: bytes) -> None:
        if len(key) != 32:
            raise ValueError("session key 必須是 32 bytes")
        self._aes = AESGCM(key)

    def encrypt(self, plaintext: bytes) -> bytes:
        nonce = os.urandom(12)
        return nonce + self._aes.encrypt(nonce, plaintext, None)

    def decrypt(self, blob: bytes) -> bytes:
        if len(blob) < 13:
            raise ValueError("密文太短")
        nonce, data = blob[:12], blob[12:]
        return self._aes.decrypt(nonce, data, None)
