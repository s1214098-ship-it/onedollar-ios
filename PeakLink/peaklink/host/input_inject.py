"""把操作端送來的滑鼠／鍵盤套用到本機。僅在使用者同意的遠端工作階段中使用。"""

from __future__ import annotations

from peaklink.protocol import MOUSE_DOWN, MOUSE_MOVE, MOUSE_UP, MOUSE_WHEEL

_BUTTONS = {0: "left", 1: "left", 2: "right", 3: "middle"}


class InputInjector:
    def __init__(self) -> None:
        from pynput.keyboard import Controller as KeyController
        from pynput.mouse import Controller as MouseController

        self.mouse = MouseController()
        self.keyboard = KeyController()
        self.frame_size = (1920, 1080)
        self.screen_size = _screen_size()

    def set_frame_size(self, width: int, height: int) -> None:
        if width > 0 and height > 0:
            self.frame_size = (width, height)

    def apply_mouse(self, action: int, x: int, y: int, button: int = 0, wheel: int = 0) -> None:
        from pynput.mouse import Button

        sx, sy = self._scale(x, y)
        if action in {MOUSE_MOVE, MOUSE_DOWN, MOUSE_UP}:
            self.mouse.position = (sx, sy)
        name = _BUTTONS.get(button, "left")
        btn = Button.left if name == "left" else Button.right if name == "right" else Button.middle
        if action == MOUSE_DOWN:
            self.mouse.press(btn)
        elif action == MOUSE_UP:
            self.mouse.release(btn)
        elif action == MOUSE_WHEEL and wheel:
            self.mouse.scroll(0, wheel)

    def apply_key(self, down: bool, vk: int, text: str = "") -> None:
        from pynput.keyboard import Key

        special = {
            8: Key.backspace,
            9: Key.tab,
            13: Key.enter,
            27: Key.esc,
            32: Key.space,
            37: Key.left,
            38: Key.up,
            39: Key.right,
            40: Key.down,
            46: Key.delete,
        }
        key_obj: object
        if vk in special:
            key_obj = special[vk]
        elif text:
            key_obj = text
        elif 65 <= vk <= 90:
            key_obj = chr(vk + 32)
        elif 48 <= vk <= 57:
            key_obj = chr(vk)
        else:
            return
        if down:
            self.keyboard.press(key_obj)
        else:
            self.keyboard.release(key_obj)

    def _scale(self, x: int, y: int) -> tuple[int, int]:
        fw, fh = self.frame_size
        sw, sh = self.screen_size
        if fw <= 0 or fh <= 0:
            return x, y
        return int(x * sw / fw), int(y * sh / fh)


def _screen_size() -> tuple[int, int]:
    try:
        import mss

        with mss.mss() as sct:
            monitor = sct.monitors[1] if len(sct.monitors) > 1 else sct.monitors[0]
            return int(monitor["width"]), int(monitor["height"])
    except Exception:  # noqa: BLE001
        return 1920, 1080
