# brain/app/graph/decide.py
"""The one LLM call per turn: it returns a typed DECISION, not a label.

The model returns the intent AND which product it means in a single answer.
The pipeline validates that answer and executes it mechanically. The model
never writes text shown to the shopper.
"""

from __future__ import annotations

import json
from typing import Any, Literal

from pydantic import BaseModel, Field, ValidationError, field_validator

INTENTS = (
    "search",
    "cart_add",
    "cart_remove",
    "checkout",
    "faq",
    "order_status",
    "clarify",
    "other",
)


def _to_int(v: Any) -> int | None:
    if v is None or v == "":
        return None
    try:
        return int(float(v))
    except (TypeError, ValueError):
        return None


class Target(BaseModel):
    source: Literal["screen", "cart", "focus"] = "screen"
    # 1-based number from the lists in the prompt
    index: int = 0

    @field_validator("index", mode="before")
    @classmethod
    def _idx(cls, v):
        return _to_int(v) or 0


class Filters(BaseModel):
    # product words, e.g. "spicy coconut chips"
    keywords: str | None = None
    # must be one of the listed categories
    category_text: str | None = None
    max_price: int | None = None
    min_price: int | None = None
    # e.g. "for a monsoon trek" (kept for later scoring)
    soft_preference: str | None = None

    @field_validator("max_price", "min_price", mode="before")
    @classmethod
    def _price(cls, v):
        return _to_int(v)


class Decision(BaseModel):
    intent: str = "other"
    target: Target | None = None
    quantity: int = 1
    filters: Filters | None = None
    faq_topic: str | None = None

    # {"Colour": "Brown", "Size": "UK 8"}
    options: dict[str, str] = Field(default_factory=dict)

    # clarify only: the plausible items, numbered as in the prompt (1-based)
    candidates: list[int] = Field(default_factory=list)

    # clarify only: "cart_add" or "cart_remove"
    clarify_for: str | None = None

    @field_validator("options", mode="before")
    @classmethod
    def _opts(cls, v):
        if not isinstance(v, dict):
            return {}
        return {str(k): str(x) for k, x in v.items() if x not in (None, "")}

    @field_validator("quantity", mode="before")
    @classmethod
    def _qty(cls, v):
        n = _to_int(v) or 1
        return max(1, min(20, n))

    @field_validator("candidates", mode="before")
    @classmethod
    def _cands(cls, v):
        if not isinstance(v, list):
            return []
        return [n for n in (_to_int(x) for x in v) if n]


def parse_decision(text: str) -> Decision:
    """Never raises: anything unparseable or unknown becomes intent='other'."""
    try:
        data = json.loads(text)
        if not isinstance(data, dict):
            return Decision()
        d = Decision.model_validate(data)
    except (json.JSONDecodeError, ValidationError):
        return Decision()
    if d.intent not in INTENTS:
        d.intent = "other"
    return d


_INTENT_HELP = [
    "Intents:",
    "- search: find, browse, compare or refine products.",
    "- cart_add: add a product ON SCREEN (or the focused product) to the cart.",
    "- cart_remove: remove something that is IN THE CART.",
    "- checkout: go to checkout.",
    "- faq: the message matches one of the FAQ topics above.",
    "- order_status: asks about an existing or past order.",
    "- clarify: they want to add or remove something but 2+ items are",
    "  plausible and nothing in the message tells them apart.",
    "- other: unrelated to shopping at this store.",
]

_JSON_SHAPE = [
    "Reply with JSON only:",
    "{",
    '  "intent": "search|cart_add|cart_remove|checkout|faq|order_status|clarify|other",',
    '  "target": {"source": "screen|cart|focus", "index": <number from the lists>} or null,',
    '  "quantity": <integer, default 1>,',
    '  "options": {"<attribute label>": "<exact option label>"} or {},',
    '  "filters": {"keywords": str|null, "category_text": str|null,',
    '              "max_price": int|null, "min_price": int|null,',
    '              "soft_preference": str|null} or null,',
    '  "faq_topic": str|null,',
    '  "candidates": [<numbers from the list>],',
    '  "clarify_for": "cart_add|cart_remove|null"',
    "}",
]

_RULES = [
    "Rules:",
    '- "this", "that", "it", "the one" with exactly one product on screen means',
    "  that product. With several on screen and no other cue (position, name,",
    "  price, attribute), use clarify and list the plausible numbers in candidates.",
    '- Resolve cues: "the second one" -> index 2; "the cheaper one" -> the lower',
    '  price; "the Puma one" -> the matching name.',
    "- cart_add targets use source=screen (a product shown) or source=focus",
    "  (the focused product). cart_remove targets use source=cart.",
    "- If the shopper NAMES a product that is not on screen, use cart_add with",
    "  target null and filters.keywords set to that product's name words.",
    "  Never point target at an unrelated product just to have a target.",
    "- A focused product means the shopper is still deciding on THAT product.",
    '  A message that gives option values for it ("brown and 8", "the black one",',
    '  "size 9") is cart_add with target {source: focus} and options using the',
    "  exact option labels listed. Use search only if the shopper asks for",
    "  different or more products.",
    "- options: only values the shopper actually stated, using the exact labels",
    "  from the focused product's option list or the named product's options.",
    "- For search, filters are the COMPLETE set that should apply after this",
    "  message. Carry earlier filters over only if the shopper is refining them",
    "  (cheaper, under X); drop them when the topic changes.",
    '- keywords: the product words in the message (e.g. "spicy coconut chips"),',
    "  or null if the message only names a category.",
    "- category_text must be one of the listed categories, or null.",
    "  Prefer the most specific one.",
    "- order_status is ONLY for an existing or past order. Adding or buying",
    "  something is never order_status.",
    "- faq_topic must be one of the FAQ topics listed.",
    "- Never invent a product, category or topic that is not listed above.",
]


def build_prompt(
    msg: str,
    store: Any,
    category_names: list[str],
    slots: dict,
    screen: list[str],
    cart: list[str],
    pending_line: str | None,
    focus_line: str | None = None,
) -> str:
    attrs = (
        ", ".join(
            f"{a.label}: {', '.join(a.values[:5])}"
            for a in store.attributes[:6]
            if a.values
        )
        or "none"
    )
    faq = ", ".join(f.topic for f in store.faq) or "none"
    cats = ", ".join(category_names[:40]) or "none"
    screen_txt = "\n".join(f"{i + 1}. {x}" for i, x in enumerate(screen))
    cart_txt = "\n".join(f"{i + 1}. {x}" for i, x in enumerate(cart))

    head = [
        f"You are the decision step of {store.name}'s shopping assistant",
        f"(currency {store.currency}). You never write text shown to the",
        "shopper. You output exactly ONE JSON decision.",
        "",
        f"Store categories (most specific first): {cats}",
        f"Filterable attributes: {attrs}",
        f"FAQ topics the store has answers for: {faq}",
        "",
        "Products on screen:",
        screen_txt or "(nothing shown)",
        "",
        "Shopper's cart:",
        cart_txt or "(empty)",
        "",
        f"Focused product: {focus_line or 'none'}",
        f"Pending question: {pending_line or 'none'}",
        f"Active filters: {json.dumps(slots)}",
        "",
        f'Shopper message: "{msg}"',
        "",
    ]
    return "\n".join(head + _INTENT_HELP + [""] + _JSON_SHAPE + [""] + _RULES)


async def decide(
    llm,
    model: str,
    msg: str,
    store: Any,
    category_names: list[str],
    slots: dict,
    screen: list[str],
    cart: list[str],
    pending_line: str | None,
    focus_line: str | None = None,
) -> tuple[Decision, int, int]:
    prompt = build_prompt(
        msg, store, category_names, slots, screen, cart, pending_line, focus_line
    )
    res = await llm.chat(
        model,
        [{"role": "user", "content": prompt}],
        max_tokens=220,
        temperature=0.0,
        response_format={"type": "json_object"},
    )
    return parse_decision(res.text), res.input_tokens, res.output_tokens
