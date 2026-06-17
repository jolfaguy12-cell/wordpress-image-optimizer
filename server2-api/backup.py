"""
Backup storage: stores original image files keyed by UUID.
Prevents path traversal by validating keys against UUID4 pattern.
"""
from __future__ import annotations

import json
import os
import re
import uuid
from pathlib import Path

_BACKUP_DIR = Path(os.environ.get("WOO_IMG_BACKUP_DIR", str(Path(__file__).parent / "backups"))).resolve()
_KEY_RE = re.compile(
    r"^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"
)


def _validate(key: str) -> None:
    if not _KEY_RE.match(key):
        raise ValueError("Invalid backup key.")


def _ensure_dir() -> None:
    _BACKUP_DIR.mkdir(parents=True, exist_ok=True)


def store(data: bytes, attachment_id: int, original_name: str) -> str:
    _ensure_dir()
    key = str(uuid.uuid4())
    (_BACKUP_DIR / key).write_bytes(data)
    (_BACKUP_DIR / f"{key}.json").write_text(
        json.dumps(
            {
                "attachment_id": attachment_id,
                "original_name": original_name,
            }
        ),
        encoding="utf-8",
    )
    return key


def retrieve(key: str) -> bytes | None:
    _validate(key)
    path = _BACKUP_DIR / key
    if not path.exists():
        return None
    return path.read_bytes()


def delete(key: str) -> bool:
    _validate(key)
    path = _BACKUP_DIR / key
    if not path.exists():
        return False
    path.unlink()
    meta = _BACKUP_DIR / f"{key}.json"
    if meta.exists():
        meta.unlink()
    return True


def purge_older_than(days: int) -> int:
    """Delete backups whose JSON sidecar is older than `days` days. Returns count deleted."""
    import time

    cutoff = time.time() - days * 86400
    removed = 0
    for meta_path in _BACKUP_DIR.glob("*.json"):
        if meta_path.stat().st_mtime < cutoff:
            data_path = meta_path.with_suffix("")
            if data_path.exists():
                data_path.unlink()
            meta_path.unlink()
            removed += 1
    return removed
