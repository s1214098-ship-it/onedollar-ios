"""測試用授權金鑰與 Starlette client。"""

from __future__ import annotations

import pytest

from peaklink.license import generate_keypair, load_private_key, load_public_key
from peaklink.server.app import registry


@pytest.fixture
def keypair():
    private_pem, public_pem = generate_keypair()
    return load_private_key(private_pem), load_public_key(public_pem), private_pem, public_pem


@pytest.fixture
async def clean_rooms():
    await registry.clear()
    yield
    await registry.clear()
