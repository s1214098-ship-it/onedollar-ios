from __future__ import annotations

from datetime import datetime, timedelta, timezone

import pytest

from peaklink.constants import FREE_SESSION_SECONDS
from peaklink.license import (
    LicenseError,
    days_from_payment,
    dumps_license,
    free_payload,
    loads_license,
    member_payload,
    sign_payload,
    verify_document,
)


def test_free_session_is_five_minutes():
    lic = free_payload()
    assert lic.edition == "free"
    assert lic.session_limit_seconds() == FREE_SESSION_SECONDS
    assert lic.session_limit_seconds() == 300
    assert not lic.is_expired()
    assert "免費" in lic.display_status()


def test_payment_amount_converts_to_days():
    assert days_from_payment(199, "TWD") >= 7
    assert days_from_payment(300, "TWD") == 30
    assert days_from_payment(10, "TWD") == 7  # 最低天數


def test_member_package_month(keypair):
    private, public, *_ = keypair
    payload = member_payload(customer_name="測試客戶", amount=0, package="month")
    assert payload.edition == "member"
    assert payload.max_session_seconds is None
    document = sign_payload(payload, private)
    loaded = verify_document(loads_license(dumps_license(document)), public)
    assert loaded.customer_name == "測試客戶"
    remaining = loaded.remaining_software_seconds()
    assert remaining is not None
    assert 29 * 86400 <= remaining <= 30 * 86400


def test_custom_amount_and_days(keypair):
    private, public, *_ = keypair
    payload = member_payload(customer_name="自訂", amount=800, currency="TWD", days=45)
    document = sign_payload(payload, private)
    loaded = verify_document(document, public)
    assert "45" in (loaded.note or "")


def test_expired_member_rejected(keypair):
    private, public, *_ = keypair
    past = datetime.now(timezone.utc) - timedelta(days=40)
    payload = member_payload(customer_name="過期", amount=199, package="month", now=past)
    document = sign_payload(payload, private)
    with pytest.raises(LicenseError, match="過期"):
        verify_document(document, public)


def test_tampered_license_rejected(keypair):
    private, public, *_ = keypair
    payload = member_payload(customer_name="A", amount=199, package="month")
    document = sign_payload(payload, private)
    document["payload"]["customer_name"] = "B"
    with pytest.raises(LicenseError):
        verify_document(document, public)
