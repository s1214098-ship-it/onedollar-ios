"""產生 Windows 圖示 peaklink.ico。"""

from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageDraw, ImageFont


def _font(size: int) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    candidates = [
        Path("/usr/share/fonts/truetype/wqy/wqy-microhei.ttc"),
        Path("/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf"),
        Path("C:/Windows/Fonts/msjhbd.ttc"),
        Path("C:/Windows/Fonts/msjh.ttc"),
        Path("C:/Windows/Fonts/segoeui.ttf"),
    ]
    for path in candidates:
        if path.exists():
            try:
                return ImageFont.truetype(str(path), size=size)
            except OSError:
                continue
    return ImageFont.load_default()


def generate_icon(dest: Path) -> Path:
    dest.parent.mkdir(parents=True, exist_ok=True)
    master = Image.new("RGBA", (256, 256), (0, 0, 0, 0))
    draw = ImageDraw.Draw(master)
    draw.rounded_rectangle((8, 8, 248, 248), radius=48, fill=(12, 92, 138, 255))
    draw.rounded_rectangle((36, 36, 220, 220), radius=36, fill=(18, 124, 176, 255))
    font = _font(140)
    text = "峰"
    bbox = draw.textbbox((0, 0), text, font=font)
    tw, th = bbox[2] - bbox[0], bbox[3] - bbox[1]
    draw.text(((256 - tw) / 2 - bbox[0], (256 - th) / 2 - bbox[1] - 8), text, font=font, fill="white")
    master.save(
        dest,
        format="ICO",
        sizes=[(16, 16), (24, 24), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)],
    )
    return dest


if __name__ == "__main__":
    out = Path(__file__).resolve().parent / "peaklink.ico"
    generate_icon(out)
    print(out)
