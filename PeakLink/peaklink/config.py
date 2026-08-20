"""本機設定：中繼選擇、直連埠、同意策略。"""

from __future__ import annotations

import json
import os
from dataclasses import asdict, dataclass, field
from pathlib import Path

from peaklink.constants import APP_DATA_DIRNAME, CONFIG_FILENAME, DIRECT_PORT


def app_data_dir() -> Path:
    if os.name == "nt":
        root = Path(os.environ.get("APPDATA") or Path.home() / "AppData" / "Roaming")
    else:
        root = Path(os.environ.get("XDG_CONFIG_HOME") or Path.home() / ".config")
    path = root / APP_DATA_DIRNAME
    path.mkdir(parents=True, exist_ok=True)
    return path


@dataclass
class AppConfig:
    relay_id: str = "auto"
    custom_relay_http: str = ""
    custom_relay_ws: str = ""
    direct_port: int = DIRECT_PORT
    auto_accept_member: bool = False
    jpeg_quality: int = 55
    fps: int = 8
    max_width: int = 1280
    display_name: str = ""
    extra: dict = field(default_factory=dict)

    @classmethod
    def load(cls) -> "AppConfig":
        path = app_data_dir() / CONFIG_FILENAME
        if not path.exists():
            cfg = cls()
            cfg.save()
            return cfg
        data = json.loads(path.read_text(encoding="utf-8"))
        known = {k: v for k, v in data.items() if k in cls.__dataclass_fields__}
        return cls(**known)

    def save(self) -> None:
        path = app_data_dir() / CONFIG_FILENAME
        path.write_text(json.dumps(asdict(self), ensure_ascii=False, indent=2), encoding="utf-8")
