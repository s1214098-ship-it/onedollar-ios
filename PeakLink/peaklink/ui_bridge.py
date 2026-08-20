"""asyncio 與 tkinter 橋接。"""

from __future__ import annotations

import asyncio
import threading
from collections.abc import Callable, Coroutine
from typing import Any


class AsyncBridge:
    def __init__(self, widget: Any) -> None:
        self.widget = widget
        self.loop = asyncio.new_event_loop()
        self._thread = threading.Thread(target=self._run, daemon=True)
        self._thread.start()

    def _run(self) -> None:
        asyncio.set_event_loop(self.loop)
        self.loop.run_forever()

    def submit(self, coro: Coroutine[Any, Any, Any]) -> None:
        asyncio.run_coroutine_threadsafe(coro, self.loop)

    def ui(self, fn: Callable[..., None], *args: Any) -> None:
        self.widget.after(0, lambda: fn(*args))

    def stop(self) -> None:
        self.loop.call_soon_threadsafe(self.loop.stop)
