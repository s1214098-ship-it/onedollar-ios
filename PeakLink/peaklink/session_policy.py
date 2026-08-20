"""連線時長政策：免費單次 5 分鐘；會員看軟體到期日。"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timezone

from peaklink.license import LicensePayload
from peaklink.constants import FREE_SESSION_SECONDS


def _now() -> datetime:
    return datetime.now(timezone.utc)


@dataclass
class SessionBudget:
    max_seconds: int | None
    reason: str
    software_expires_at: str | None = None

    def remaining(self, elapsed_seconds: float, now: datetime | None = None) -> int | None:
        """回傳剩餘秒數；None 代表不限（仍可能被軟體到期截斷）。"""
        now = now or _now()
        session_left: int | None
        if self.max_seconds is None:
            session_left = None
        else:
            session_left = max(0, int(self.max_seconds - elapsed_seconds))
        if not self.software_expires_at:
            return session_left
        exp = datetime.fromisoformat(self.software_expires_at.replace("Z", "+00:00"))
        software_left = max(0, int((exp - now).total_seconds()))
        if session_left is None:
            return software_left
        return min(session_left, software_left)

    def allowed(self, elapsed_seconds: float, now: datetime | None = None) -> bool:
        left = self.remaining(elapsed_seconds, now)
        return left is None or left > 0


def budget_from_license(license: LicensePayload) -> SessionBudget:
    if license.edition == "free":
        return SessionBudget(
            max_seconds=license.session_limit_seconds() or FREE_SESSION_SECONDS,
            reason="free_session_cap",
        )
    return SessionBudget(
        max_seconds=None,
        reason="member_period",
        software_expires_at=license.expires_at,
    )
