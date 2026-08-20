"""授權：免費版單次 5 分鐘；會員版依付款換算使用期間。"""

from __future__ import annotations

import base64
import hashlib
import json
from dataclasses import asdict, dataclass
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Literal

from cryptography.hazmat.primitives.asymmetric.ed25519 import (
    Ed25519PrivateKey,
    Ed25519PublicKey,
)
from cryptography.hazmat.primitives.serialization import (
    Encoding,
    NoEncryption,
    PrivateFormat,
    PublicFormat,
    load_pem_private_key,
    load_pem_public_key,
)

from peaklink.config import app_data_dir
from peaklink.constants import (
    CUSTOM_MIN_DAYS,
    CUSTOM_TWD_PER_DAY,
    FREE_SESSION_SECONDS,
    LICENSE_FILENAME,
    PRICE_PACKAGES,
)

Edition = Literal["free", "member"]


class LicenseError(Exception):
    """授權無效或過期。"""


def _utcnow() -> datetime:
    return datetime.now(timezone.utc)


def _parse_dt(value: str | None) -> datetime | None:
    if not value:
        return None
    dt = datetime.fromisoformat(value.replace("Z", "+00:00"))
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt


def days_from_payment(amount: float, currency: str = "TWD", *, days: int | None = None) -> int:
    """依付款金額換算可用天數。若指定 days 則以方案天數為準。"""
    if days is not None:
        if days < 1:
            raise LicenseError("使用天數至少 1 天")
        return days
    if currency.upper() != "TWD":
        # 其他幣別先當 1:1 再走同一換算，發行時建議明確傳 days
        amount_twd = amount
    else:
        amount_twd = amount
    calculated = int(amount_twd // CUSTOM_TWD_PER_DAY)
    return max(CUSTOM_MIN_DAYS, calculated)


@dataclass
class LicensePayload:
    edition: Edition
    customer_name: str
    issued_at: str
    expires_at: str | None
    max_session_seconds: int | None
    paid_amount: float | None = None
    paid_currency: str | None = None
    package: str | None = None
    note: str = ""
    license_id: str = ""

    def expiry(self) -> datetime | None:
        return _parse_dt(self.expires_at)

    def issued(self) -> datetime:
        dt = _parse_dt(self.issued_at)
        return dt or _utcnow()

    def remaining_software_seconds(self, now: datetime | None = None) -> int | None:
        """會員軟體剩餘秒數；免費版回傳 None 表示不靠日曆過期。"""
        if self.edition == "free":
            return None
        exp = self.expiry()
        if exp is None:
            return None
        now = now or _utcnow()
        return max(0, int((exp - now).total_seconds()))

    def is_expired(self, now: datetime | None = None) -> bool:
        if self.edition == "free":
            return False
        remaining = self.remaining_software_seconds(now)
        return remaining is not None and remaining <= 0

    def session_limit_seconds(self) -> int | None:
        """單次遠端上限。免費 300 秒；會員不限單次時長（仍受軟體到期日限制）。"""
        if self.edition == "free":
            return self.max_session_seconds or FREE_SESSION_SECONDS
        return None

    def display_status(self, now: datetime | None = None) -> str:
        if self.edition == "free":
            limit = self.session_limit_seconds() or FREE_SESSION_SECONDS
            return f"免費版（單次遠端 {limit // 60} 分鐘）"
        if self.is_expired(now):
            return "會員已到期，請續費"
        remaining = self.remaining_software_seconds(now) or 0
        days = remaining // 86400
        hours = (remaining % 86400) // 3600
        name = self.customer_name or "會員"
        return f"{name}｜會員剩餘 {days} 天 {hours} 小時"


def canonical_bytes(payload: dict[str, Any]) -> bytes:
    return json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")


def load_private_key(pem: bytes) -> Ed25519PrivateKey:
    key = load_pem_private_key(pem, password=None)
    if not isinstance(key, Ed25519PrivateKey):
        raise LicenseError("私鑰必須是 Ed25519")
    return key


def load_public_key(pem: bytes) -> Ed25519PublicKey:
    key = load_pem_public_key(pem)
    if not isinstance(key, Ed25519PublicKey):
        raise LicenseError("公鑰必須是 Ed25519")
    return key


def generate_keypair() -> tuple[bytes, bytes]:
    private = Ed25519PrivateKey.generate()
    public = private.public_key()
    return (
        private.private_bytes(Encoding.PEM, PrivateFormat.PKCS8, NoEncryption()),
        public.public_bytes(Encoding.PEM, PublicFormat.SubjectPublicKeyInfo),
    )


def sign_payload(payload: LicensePayload, private_key: Ed25519PrivateKey) -> dict[str, Any]:
    body = asdict(payload)
    sig = private_key.sign(canonical_bytes(body))
    return {
        "payload": body,
        "signature": base64.b64encode(sig).decode("ascii"),
        "alg": "Ed25519",
        "v": 1,
    }


def verify_document(document: dict[str, Any], public_key: Ed25519PublicKey) -> LicensePayload:
    if document.get("alg") != "Ed25519":
        raise LicenseError("不支援的授權簽名演算法")
    payload = document.get("payload")
    signature_b64 = document.get("signature")
    if not isinstance(payload, dict) or not isinstance(signature_b64, str):
        raise LicenseError("授權檔格式錯誤")
    try:
        signature = base64.b64decode(signature_b64.encode("ascii"))
        public_key.verify(signature, canonical_bytes(payload))
    except Exception as exc:  # noqa: BLE001 — 統一成授權錯誤
        raise LicenseError("授權簽名無效") from exc
    edition = payload.get("edition")
    if edition not in ("free", "member"):
        raise LicenseError("未知的版本")
    lic = LicensePayload(
        edition=edition,
        customer_name=str(payload.get("customer_name") or ""),
        issued_at=str(payload.get("issued_at") or ""),
        expires_at=payload.get("expires_at"),
        max_session_seconds=payload.get("max_session_seconds"),
        paid_amount=payload.get("paid_amount"),
        paid_currency=payload.get("paid_currency"),
        package=payload.get("package"),
        note=str(payload.get("note") or ""),
        license_id=str(payload.get("license_id") or ""),
    )
    if lic.is_expired():
        raise LicenseError("授權已過期")
    return lic


def free_payload() -> LicensePayload:
    now = _utcnow()
    return LicensePayload(
        edition="free",
        customer_name="免費用戶",
        issued_at=now.isoformat(),
        expires_at=None,
        max_session_seconds=FREE_SESSION_SECONDS,
        package="free",
        license_id="free",
        note="未收費。每一次遠端連線最長五分鐘。",
    )


def member_payload(
    *,
    customer_name: str,
    amount: float,
    currency: str = "TWD",
    package: str | None = None,
    days: int | None = None,
    note: str = "",
    now: datetime | None = None,
) -> LicensePayload:
    now = now or _utcnow()
    if package:
        spec = PRICE_PACKAGES.get(package)
        if not spec:
            raise LicenseError(f"未知方案：{package}")
        use_days = spec["days"]
        amount = float(spec["amount"])
        currency = spec["currency"]
    else:
        use_days = days_from_payment(amount, currency, days=days)
    expires = now + timedelta(days=use_days)
    license_id = hashlib.sha256(f"{customer_name}|{now.isoformat()}|{amount}".encode("utf-8")).hexdigest()[:16]
    return LicensePayload(
        edition="member",
        customer_name=customer_name,
        issued_at=now.isoformat(),
        expires_at=expires.isoformat(),
        max_session_seconds=None,
        paid_amount=float(amount),
        paid_currency=currency,
        package=package or "custom",
        note=note or f"付款 {amount} {currency}，使用 {use_days} 天",
        license_id=license_id,
    )


def dumps_license(document: dict[str, Any]) -> str:
    return json.dumps(document, ensure_ascii=False, indent=2)


def loads_license(text: str) -> dict[str, Any]:
    data = json.loads(text)
    if not isinstance(data, dict):
        raise LicenseError("授權檔不是物件")
    return data


def license_path() -> Path:
    return app_data_dir() / LICENSE_FILENAME


def save_license_file(document: dict[str, Any], path: Path | None = None) -> Path:
    dest = path or license_path()
    dest.parent.mkdir(parents=True, exist_ok=True)
    dest.write_text(dumps_license(document), encoding="utf-8")
    return dest


def bundled_public_key_pem() -> bytes:
    key_path = Path(__file__).resolve().parent / "keys" / "public.pem"
    if not key_path.exists():
        raise LicenseError("缺少內建公鑰 peaklink/keys/public.pem")
    return key_path.read_bytes()


def load_installed_license(public_pem: bytes | None = None) -> LicensePayload:
    """讀取本機授權；沒有檔案就當免費版。"""
    public = load_public_key(public_pem or bundled_public_key_pem())
    path = license_path()
    if not path.exists():
        return free_payload()
    document = loads_license(path.read_text(encoding="utf-8"))
    return verify_document(document, public)
