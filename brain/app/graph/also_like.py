# brain/app/graph/also_like.py
""""You may also like": complementary categories, inferred from the MISSION.

Tiers 1-3 are "more of what you asked about". This tier is the opposite: it
reads the recent categories together, guesses what the shopper is actually
doing ("chocolate + hiking boots" → a trek), and picks OTHER categories that
trip needs (tents, stoves, backpacks).

One small LLM call does the guessing, but it never names products and never
invents categories: it may only pick slugs from the store's real category list,
and every slug it returns is re-checked here. Products are then pulled from the
catalog the same way the other tiers are.

Cost control:
  * The answer depends only on the SET of recent categories, so it is cached
    by that set. Most turns don't change the set and cost nothing.
  * The call is awaited with a short budget. If it is slow, this turn falls back
    to sibling categories, and the shielded call still finishes and fills the
    cache for the next turn.
"""

from __future__ import annotations

import asyncio
import json
import logging
import re
from dataclasses import dataclass

from app.catalog.index import SearchQuery

log = logging.getLogger("brain.also_like")

TIER_SIZE = 8
MAX_CATEGORIES = 4
BUDGET_S = 2.5
_CACHE_MAX = 512


@dataclass(frozen=True, slots=True)
class Mission:
    title: str | None  # "trekking & camping trip"; None for the sibling fallback
    categories: tuple[str, ...]


_cache: dict[tuple[str, ...], Mission] = {}
_inflight: dict[tuple[str, ...], asyncio.Task] = {}


# ═════════════════════════════════════════════════════════════════ entry point


async def also_like(
    history: list[str],
    catalog,
    llm,
    model: str,
    exclude_ids: list[int],
) -> tuple[Mission | None, list[int], int, int]:
    """(mission, ranked product ids, tokens_in, tokens_out). Never raises."""
    if not history:
        return None, [], 0, 0

    mission, tin, tout = await _mission(history, catalog, llm, model)
    if not mission.categories:
        return None, [], tin, tout
    ids = _fill(mission.categories, catalog, exclude_ids)
    return (mission if ids else None), ids, tin, tout


# ════════════════════════════════════════════════════════════════════ mission


def _key(history: list[str]) -> tuple[str, ...]:
    return tuple(sorted(set(history)))


async def _mission(history, catalog, llm, model) -> tuple[Mission, int, int]:
    key = _key(history)
    if key in _cache:
        return _cache[key], 0, 0

    if not (llm and getattr(llm, "configured", False) and model):
        return _siblings(history, catalog), 0, 0

    task = _inflight.get(key)
    if task is None:
        task = asyncio.ensure_future(_infer(key, history, catalog, llm, model))
        _inflight[key] = task
        task.add_done_callback(lambda _t: _inflight.pop(key, None))

    try:
        # shield: a timeout here must not cancel the call; it still fills the
        # cache for the next turn.
        mission, tin, tout = await asyncio.wait_for(asyncio.shield(task), BUDGET_S)
    except asyncio.TimeoutError:
        log.info("also_like: mission call over budget for %s; using siblings", key)
        return _siblings(history, catalog), 0, 0
    except Exception:
        log.exception("also_like: mission call failed")
        return _siblings(history, catalog), 0, 0
    return mission, tin, tout


async def _infer(key, history, catalog, llm, model) -> tuple[Mission, int, int]:
    res = await llm.chat(
        model,
        [{"role": "user", "content": build_prompt(history, catalog)}],
        max_tokens=120,
        temperature=0.0,
        response_format={"type": "json_object"},
    )
    mission = parse_mission(res.text, history, catalog)
    if not mission.categories:
        mission = _siblings(history, catalog)
    if len(_cache) >= _CACHE_MAX:
        _cache.pop(next(iter(_cache)))
    _cache[key] = mission
    return mission, res.input_tokens, res.output_tokens


def build_prompt(history: list[str], catalog) -> str:
    cats = catalog.index.categories
    recent = [f"- {cats[s].name} ({s})" if s in cats else f"- {s}" for s in history]
    available = [
        f"- {c.slug}: {c.name}"
        for c in sorted(cats.values(), key=lambda c: -c.count)
        if c.count > 0
    ][:80]
    return "\n".join(
        [
            "A shopper in an online store recently looked at these categories,",
            "most recent first:",
            *recent,
            "",
            "Guess the real-life activity or plan behind them (for example",
            "chocolate + hiking boots → a trekking & camping trip), then choose",
            f"up to {MAX_CATEGORIES} OTHER categories from the list below that the",
            "same plan would also need. Prefer things that complete the plan over",
            "things that are similar to what they already looked at. Do not",
            "choose the categories above or their sub-categories.",
            "",
            "Available categories (slug: name):",
            *available,
            "",
            'Reply with JSON only: {"mission": "<3-6 words, lowercase, e.g. '
            'trekking & camping trip>", "categories": ["<slug>", ...]}',
            'If nothing fits, reply {"mission": null, "categories": []}.',
        ]
    )


def parse_mission(text: str, history: list[str], catalog) -> Mission:
    """Never raises. Keeps only real, non-empty slugs outside the history."""
    try:
        data = json.loads(text)
    except (json.JSONDecodeError, TypeError):
        return Mission(None, ())
    if not isinstance(data, dict):
        return Mission(None, ())

    cats = catalog.index.categories
    blocked = _family(history, catalog)
    picked: list[str] = []
    for s in data.get("categories") or []:
        s = str(s).strip().lower()
        c = cats.get(s)
        if c and c.count > 0 and s not in blocked and s not in picked:
            picked.append(s)
    picked = picked[:MAX_CATEGORIES]

    title = data.get("mission")
    title = re.sub(r"\s+", " ", str(title)).strip()[:60] if title else None
    return Mission(title or None, tuple(picked))


# ═══════════════════════════════════════════════════════════════════ fallback


def _family(history: list[str], catalog) -> set[str]:
    """The history slugs, their parents and their children: not "other"."""
    cats = catalog.index.categories
    by_id = {c.id: c for c in cats.values()}
    out = set(history)
    for s in history:
        c = cats.get(s)
        if not c:
            continue
        if c.parent and c.parent in by_id:
            out.add(by_id[c.parent].slug)
        out.update(ch.slug for ch in cats.values() if ch.parent == c.id)
    return out


def _siblings(history: list[str], catalog) -> Mission:
    """No model: categories that share a parent with the most recent one."""
    cats = catalog.index.categories
    blocked = _family(history, catalog)
    for s in history:
        c = cats.get(s)
        if not c or not c.parent:
            continue
        sib = sorted(
            (
                o
                for o in cats.values()
                if o.parent == c.parent and o.count > 0 and o.slug not in blocked
            ),
            key=lambda o: -o.count,
        )
        if sib:
            return Mission(None, tuple(o.slug for o in sib[:MAX_CATEGORIES]))
    return Mission(None, ())


# ══════════════════════════════════════════════════════════════════ products


def _fill(categories: tuple[str, ...], catalog, exclude_ids: list[int]) -> list[int]:
    """Round-robin across the categories so one tent shop can't fill the row."""
    taken = set(exclude_ids)
    per_cat: list[list[int]] = []
    for cat in categories:
        res = catalog.index.search(
            SearchQuery(
                category=cat,
                in_stock_only=True,
                exclude_ids=list(taken),
                limit=TIER_SIZE,
            )
        )
        per_cat.append([p.id for p in res.products])

    out: list[int] = []
    for row in range(TIER_SIZE):
        for ids in per_cat:
            if row < len(ids) and ids[row] not in taken:
                out.append(ids[row])
                taken.add(ids[row])
                if len(out) >= TIER_SIZE:
                    return out
    return out
