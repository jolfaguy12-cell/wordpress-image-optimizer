"""
Woo Image Optimizer — Server 2 API
===================================
FastAPI service that converts WooCommerce product images to WebP and
stores original-file backups so they can be restored later.

Required env var:
    WOO_IMG_API_KEY   — Bearer token the WordPress plugin sends

Optional env vars:
    WOO_IMG_BACKUP_DIR   — absolute path for backup storage (default: ./backups)
    WOO_IMG_HOST         — bind host (default: 0.0.0.0 for two-server setup;
                           set to 127.0.0.1 when running behind a local nginx)
    WOO_IMG_PORT         — bind port (default: 7700)
"""
from __future__ import annotations

import base64
import os
from typing import Annotated, Optional

from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, UploadFile
from fastapi.responses import Response

import backup as bk
import optimizer as opt

app = FastAPI(title="Woo Image Optimizer API", version="1.0.0", docs_url=None, redoc_url=None)

# ---------------------------------------------------------------------------
# Auth
# ---------------------------------------------------------------------------

_API_KEY: str = os.environ.get("WOO_IMG_API_KEY", "")


def _require_bearer(authorization: Optional[str] = Header(default=None)) -> None:
    if not _API_KEY:
        raise HTTPException(status_code=503, detail="API key not configured on server.")
    if not authorization:
        raise HTTPException(status_code=401, detail="Authorization header missing.")
    scheme, _, token = authorization.partition(" ")
    if scheme.lower() != "bearer" or token != _API_KEY:
        raise HTTPException(status_code=401, detail="Invalid Bearer token.")


Auth = Annotated[None, Depends(_require_bearer)]


# ---------------------------------------------------------------------------
# POST /optimize
# ---------------------------------------------------------------------------

@app.post("/optimize")
async def optimize(
    _auth: Auth,
    file: UploadFile = File(..., description="Original image file (JPEG, PNG, etc.)"),
    attachment_id: int = Form(...),
    quality: int = Form(82, ge=1, le=100),
    max_width: int = Form(2048, ge=1),
    max_height: int = Form(2048, ge=1),
):
    """
    Convert an image to WebP.
    Returns base64-encoded WebP plus size stats.
    """
    data = await file.read()
    if not data:
        raise HTTPException(status_code=422, detail="Uploaded file is empty.")

    original_size = len(data)

    try:
        webp_bytes, width, height = opt.optimize_to_webp(data, quality, max_width, max_height)
    except Exception as exc:
        raise HTTPException(status_code=422, detail=f"Image processing failed: {exc}")

    optimized_size = len(webp_bytes)
    return {
        "success": True,
        "webp_file": base64.b64encode(webp_bytes).decode(),
        "original_size": original_size,
        "optimized_size": optimized_size,
        "saved_bytes": max(0, original_size - optimized_size),
        "width": width,
        "height": height,
    }


# ---------------------------------------------------------------------------
# POST /backup
# ---------------------------------------------------------------------------

@app.post("/backup")
async def create_backup(
    _auth: Auth,
    file: UploadFile = File(..., description="Original image file to keep as backup"),
    attachment_id: int = Form(...),
):
    """Store original file permanently. Returns a backup_key for later retrieval."""
    data = await file.read()
    if not data:
        raise HTTPException(status_code=422, detail="Uploaded file is empty.")

    key = bk.store(data, attachment_id, file.filename or "")
    return {"success": True, "backup_key": key}


# ---------------------------------------------------------------------------
# GET /backup/{key}
# ---------------------------------------------------------------------------

@app.get("/backup/{key}")
async def get_backup(_auth: Auth, key: str):
    """Download original file binary by backup key."""
    try:
        data = bk.retrieve(key)
    except ValueError:
        raise HTTPException(status_code=400, detail="Invalid backup key format.")

    if data is None:
        raise HTTPException(status_code=404, detail="Backup not found.")

    return Response(content=data, media_type="application/octet-stream")


# ---------------------------------------------------------------------------
# DELETE /backup/{key}
# ---------------------------------------------------------------------------

@app.delete("/backup/{key}")
async def delete_backup(_auth: Auth, key: str):
    """Permanently delete a stored backup."""
    try:
        found = bk.delete(key)
    except ValueError:
        raise HTTPException(status_code=400, detail="Invalid backup key format.")

    if not found:
        raise HTTPException(status_code=404, detail="Backup not found.")

    return {"success": True}


# ---------------------------------------------------------------------------
# Entrypoint (python main.py)
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    import uvicorn

    uvicorn.run(
        "main:app",
        host=os.environ.get("WOO_IMG_HOST", "0.0.0.0"),
        port=int(os.environ.get("WOO_IMG_PORT", "7700")),
        reload=False,
    )
