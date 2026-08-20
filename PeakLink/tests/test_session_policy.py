from __future__ import annotations

from datetime import datetime, timedelta, timezone

from peaklink.constants import FREE_SESSION_SECONDS
from peaklink.license import LicensePayload
from peaklink.session_policy import budget_from_license


def test_free_budget_caps_session():
    lic = LicensePayload(
        edition="free",
        customer_name="x",
        issued_at="",
        expires_at=None,
        max_session_seconds=FREE_SESSION_SECONDS,
    )
    budget = budget_from_license(lic)
    assert budget.remaining(0) == 300
    assert budget.remaining(290) == 10
    assert budget.remaining(300) == 0
    assert not budget.allowed(300)


def test_member_budget_uses_expiry():
    exp = datetime.now(timezone.utc) + timedelta(hours=2)
    lic = LicensePayload(
        edition="member",
        customer_name="y",
        issued_at="",
        expires_at=exp.isoformat(),
        max_session_seconds=None,
    )
    budget = budget_from_license(lic)
    left = budget.remaining(0)
    assert left is not None
    assert 7100 <= left <= 7200
    assert budget.allowed(10)
