"""
WebP conversion using Pillow.
Returns (webp_bytes, final_width, final_height).
"""
from __future__ import annotations

import io

from PIL import Image

_RESAMPLE = Image.Resampling.LANCZOS


def optimize_to_webp(
    data: bytes,
    quality: int = 82,
    max_width: int = 2048,
    max_height: int = 2048,
) -> tuple[bytes, int, int]:
    img = Image.open(io.BytesIO(data))

    # Preserve transparency when present, otherwise RGB
    if img.mode in ("RGBA", "LA", "PA"):
        img = img.convert("RGBA")
    elif img.mode != "RGB":
        img = img.convert("RGB")

    # Downscale only — never upscale
    w, h = img.size
    if w > max_width or h > max_height:
        img.thumbnail((max_width, max_height), _RESAMPLE)

    w, h = img.size

    buf = io.BytesIO()
    img.save(buf, format="WEBP", quality=quality, method=6)
    return buf.getvalue(), w, h
