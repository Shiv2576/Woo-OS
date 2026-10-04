# brain/app/api/webhooks.py
"""Receives product-change pushes from the Mercora WordPress plugin."""

from __future__ import annotations

import hashlib
import hmac
import json

from fastapi import APIRouter, BackgroundTasks, HTTPException, Request

from app.config import settings

router = APIRouter(prefix="/webhooks")


@router.post("/woo")
async def woo_changes(request: Request, background: BackgroundTasks) -> dict:
    body = await request.body()
    expected = hmac.new(
        settings.webhook_secret.encode(), body, hashlib.sha256
    ).hexdigest()
    if not hmac.compare_digest(
        expected, request.headers.get("X-Mercora-Signature", "")
    ):
        raise HTTPException(status_code=401, detail="bad signature")

    data = json.loads(body)
    changed = [int(i) for i in data.get("changed", [])]
    deleted = [int(i) for i in data.get("deleted", [])]
    # Reply immediately; WordPress must never wait on Alice.
    background.add_task(request.app.state.catalog.apply_changes, changed, deleted)
    return {"accepted": len(changed) + len(deleted)}
