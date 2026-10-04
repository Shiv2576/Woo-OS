# brain/app/api/routes.py
from __future__ import annotations

from fastapi import APIRouter, HTTPException, Request
from pydantic import ValidationError

from app.catalog.index import SearchQuery
from app.config import settings
from app.contract import ContractError, TurnRequest, parse_request
from app.graph.pipeline import handle_turn
from app.llm.openrouter import LLMError

router = APIRouter()


@router.get("/health")
async def health(request: Request) -> dict:
    c = request.app.state.catalog
    return {
        "status": "ok",
        "catalog": c.index.stats(),
        "sync": c.sync,
        "llm_configured": request.app.state.llm.configured,
    }


@router.post("/search")
async def search(q: SearchQuery, request: Request) -> dict:
    res = request.app.state.catalog.index.search(q)
    return {
        "took_ms": res.took_ms,
        "total_matched": res.total_matched,
        "products": [{**p.card(), "score": res.scores[p.id]} for p in res.products],
    }


@router.post("/admin/reload")
async def reload(request: Request) -> dict:
    return await request.app.state.catalog.reload()


@router.get("/debug/llm")
async def debug_llm(request: Request, message: str) -> dict:
    if not settings.debug:
        raise HTTPException(status_code=404)
    try:
        res = await request.app.state.llm.chat(
            settings.llm_model,
            [{"role": "user", "content": "Reply with the single word OK."}],
            max_tokens=5,
        )
        return {"ok": True, "reply": res.text.strip(), **res.metrics()}
    except LLMError as e:
        return {"ok": False, "error": str(e)}


@router.post("/turn")
async def turn(request: Request) -> dict:
    raw = await request.json()
    try:
        req = parse_request(raw)
    except (ContractError, ValidationError) as e:
        raise HTTPException(status_code=422, detail=str(e))

    resp = await handle_turn(
        req, request.app.state.catalog, request.app.state.llm, settings.llm_model
    )
    return resp.to_dict()
