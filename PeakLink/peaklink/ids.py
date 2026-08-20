"""遠端 ID／密碼產生。"""

from __future__ import annotations

import hashlib
import hmac
import os
import random
import string


def generate_session_id() -> str:
    """九位數字，顯示成 123 456 789。"""
    n = random.SystemRandom().randrange(100_000_000, 1_000_000_000)
    return f"{n:09d}"


def format_session_id(session_id: str) -> str:
    digits = "".join(ch for ch in session_id if ch.isdigit())
    if len(digits) != 9:
        return session_id
    return f"{digits[0:3]} {digits[3:6]} {digits[6:9]}"


def generate_password(length: int = 6) -> str:
    alphabet = string.digits
    rng = random.SystemRandom()
    return "".join(rng.choice(alphabet) for _ in range(length))


def password_hash(session_id: str, password: str) -> str:
    digest = hmac.new(
        b"peaklink-password",
        f"{session_id}:{password}".encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()
    return digest


def verify_password(session_id: str, password: str, expected_hash: str) -> bool:
    actual = password_hash(session_id, password)
    return hmac.compare_digest(actual, expected_hash)


def random_token(nbytes: int = 16) -> str:
    return os.urandom(nbytes).hex()
