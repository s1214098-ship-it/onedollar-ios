from __future__ import annotations

import io
from typing import Any

from PIL import Image

from peaklink.constants import DEFAULT_JPEG_QUALITY, DEFAULT_MAX_WIDTH


def grab_jpeg(
    *,
    max_width: int = DEFAULT_MAX_WIDTH,
    quality: int = DEFAULT_JPEG_QUALITY,
    sct: Any | None = None,
) -> tuple[int, int, bytes]:
    """擷取主螢幕並壓成 JPEG。回傳 (寬, 高, jpeg bytes)。"""
    import mss

    closer = False
    camera = sct
    if camera is None:
        camera = mss.mss()
        closer = True
    try:
        monitor = camera.monitors[1] if len(camera.monitors) > 1 else camera.monitors[0]
        raw = camera.grab(monitor)
        image = Image.frombytes("RGB", raw.size, raw.rgb)
        width, height = image.size
        if width > max_width:
            ratio = max_width / float(width)
            new_size = (max_width, max(1, int(height * ratio)))
            image = image.resize(new_size, Image.Resampling.BILINEAR)
            width, height = image.size
        buf = io.BytesIO()
        image.save(buf, format="JPEG", quality=quality, optimize=True)
        return width, height, buf.getvalue()
    finally:
        if closer:
            camera.close()
