# brain/app/graph/pipeline.py
"""One turn, in four stages:

  1. GATE      exact-match answers to a question Alice just asked (yes/no, a
               button tap, Undo, the Checkout chip) and option answers for the
               product being discussed ("brown and 8"). No ambiguity, no model call.
  2. DECIDE    ONE LLM call returns a typed decision (intent + which product).
  3. VALIDATE  every reference is checked against real state (is that index on
               screen? is that colour a real option? does that FAQ topic exist?).
  4. DISPATCH  a plain switch executes the validated decision.

The model never writes text the shopper sees. Replies come from templates and
the store's own data, so prices, stock and policies cannot be invented.

FOCUS: while the shopper is choosing options for a product, that product stays
in `ctx.focus`. Follow-ups are understood as being about it until they search
for something else or finish the add.

Reversible things (search, add/remove cart, open checkout) run immediately and
add-to-cart shows an Undo button. Paying is never done here: it always needs
the shopper's own click on WooCommerce's checkout page.
"""

from __future__ import annotations

import asyncio
import difflib
import json
import logging
import re
import time

from app.catalog.index import SearchQuery, tokenize
from app.contract import (
    CartAddAction,
    CartRemoveAction,
    Display,
    NavigateAction,
    Patch,
    Slots,
    TurnRequest,
    TurnResponse,
    meta,
    validate_response,
)
from app.graph.decide import Decision, Filters, decide
from app.graph.shadow import shadow_intent

log = logging.getLogger("brain.pipeline")
shadow_log = logging.getLogger("brain.shadow")

OFF_TOPIC_REPLY = "I'm here to help you shop — try asking me to find a product."
ORDER_LOOKUP_REPLY = (
    "Sure — what's your order number? You can find it in your confirmation email."
)
ADD_CHIPS = ["Undo", "Checkout"]

_YES = {
    "yes",
    "yeah",
    "yep",
    "sure",
    "ok",
    "okay",
    "haan",
    "confirm",
    "yes please",
    "do it",
}
_NO = {"no", "nope", "cancel", "nahi", "no thanks"}
_ORD = {
    "first": 1,
    "1st": 1,
    "1": 1,
    "second": 2,
    "2nd": 2,
    "2": 2,
    "third": 3,
    "3rd": 3,
    "3": 3,
    "fourth": 4,
    "4th": 4,
    "4": 4,
}
_CHECKOUT = {"checkout", "check out", "go to checkout"}

# words that carry no option information when reading "brown and 8" against a product's options
_OPT_FILLER = {
    "the",
    "a",
    "an",
    "and",
    "in",
    "with",
    "please",
    "one",
    "ones",
    "size",
    "colour",
    "color",
    "uk",
    "i",
    "want",
    "take",
    "pick",
    "choose",
    "it",
    "this",
    "that",
    "of",
    "for",
    "to",
    "my",
    "pair",
    "go",
    "will",
    "ill",
    "like",
    "also",
}
_NUMWORDS = {
    "five": "5",
    "six": "6",
    "seven": "7",
    "eight": "8",
    "nine": "9",
    "ten": "10",
    "eleven": "11",
}


def _norm(s: str) -> str:
    return " ".join(re.findall(r"[a-z0-9₹]+", str(s).lower()))


# ═════════════════════════════════════════════════════════════════ entry point


async def handle_turn(req: TurnRequest, catalog, llm, llm_model: str) -> TurnResponse:
    t0 = time.perf_counter()

    # 1. GATE
    gated = await _gate(req, catalog, t0)
    if gated is not None:
        validate_response(gated)
        return gated

    # 2. DECIDE — one LLM call
    screen_ids = req.ctx.on_screen[:8]
    try:
        decision, tin, tout = await decide(
            llm,
            llm_model,
            req.msg,
            req.store,
            _category_hint_list(catalog),
            req.ctx.slots.model_dump(exclude_none=True),
            _screen_lines(screen_ids, catalog),
            _cart_lines(req.ctx.cart, catalog),
            _pending_line(req),
            _focus_line(req, catalog),
        )
    except Exception:
        log.exception("decide call failed; falling back to plain search")
        resp = _fallback_search(req, catalog, t0)
        validate_response(resp)
        return resp

    # 3 + 4. VALIDATE and DISPATCH
    mk = dict(llm_calls=1, tokens_in=tin, tokens_out=tout)
    resp = await _dispatch(req, decision, catalog, screen_ids, mk, t0)

    _log_shadow(req, decision, catalog, screen_ids)
    validate_response(resp)
    return resp


# ═════════════════════════════════════════════════════════════════ 1. gate


async def _gate(req: TurnRequest, catalog, t0: float) -> TurnResponse | None:
    m = _norm(req.msg)
    p = req.ctx.pending
    focus = req.ctx.focus

    if p is not None:
        if p.type == "confirm":
            if m in _YES and p.product_id:
                return await _add(req, p.product_id, 1, catalog, meta("gate:yes"), t0)
            if m in _NO:
                return _build(
                    req,
                    Patch(),
                    None,
                    Display(
                        template="ack",
                        speech="No problem — let me know if you need anything else.",
                    ),
                    meta("gate:no"),
                    t0,
                )

        elif p.type == "choose":
            idx = _match_option(m, p.options)
            if idx is not None and idx < len(p.product_ids):
                pid = p.product_ids[idx]
                if p.then == "cart_remove":
                    return _remove(req, pid, p.options[idx], meta("gate:choose"), t0)
                return await _add(req, pid, 1, catalog, meta("gate:choose"), t0)

        elif p.type == "option":
            idx = _match_option(m, p.options)
            product = await _fresh(catalog, p.product_id) if idx is not None else None
            if idx is not None and product is not None:
                chosen = (
                    dict(focus.chosen)
                    if focus and focus.product_id == p.product_id
                    else {}
                )
                chosen[p.attribute] = p.options[idx]
                return _options_step(req, product, chosen, 1, meta("gate:option"), t0)

        elif p.type == "undo":
            if m == "undo":
                product = catalog.index.get(p.product_id)
                name = product.name if product else "that item"
                return _remove(
                    req,
                    p.product_id,
                    name,
                    meta("gate:undo"),
                    t0,
                    variation_id=p.variation_id,
                )

    # "brown and 8" while choosing options for a product: every word maps to exactly one of
    # that product's own option values, so there is nothing to interpret.
    if focus is not None:
        cached = catalog.index.get(focus.product_id)
        if cached is not None and cached.variations and _text_options(cached, req.msg):
            product = await _fresh(catalog, focus.product_id) or cached
            opts = _text_options(product, req.msg)
            if opts:
                chosen = {**focus.chosen, **opts}
                return _options_step(req, product, chosen, 1, meta("gate:options"), t0)

    if m in _CHECKOUT:  # the "Checkout" chip Alice itself renders
        return _build(
            req,
            Patch(),
            NavigateAction(type="navigate", url="/checkout/"),
            Display(template="navigate", speech="Taking you to checkout."),
            meta("gate:checkout"),
            t0,
        )
    return None


def _match_option(m: str, options: list[str]) -> int | None:
    for i, o in enumerate(options):
        if _norm(o) == m:
            return i
    # a long label is shown truncated with "…": accept the shopper typing the full name
    for i, o in enumerate(options):
        if o.endswith("…") and _norm(o) and m.startswith(_norm(o)):
            return i
    n = _ORD.get(m)
    if n and n <= len(options):
        return n - 1
    return None


# ═════════════════════════════════════════════════════════════════ 3+4. dispatch


async def _dispatch(
    req: TurnRequest, d: Decision, catalog, screen_ids: list[int], mk: dict, t0: float
) -> TurnResponse:
    path = f"decide:{d.intent}"

    if d.intent == "search":
        return _search(req, d, catalog, meta(path, **mk), t0)

    if d.intent == "cart_add":
        focus = req.ctx.focus
        t = d.target
        pid: int | None = None
        chosen: dict[str, str] = {}
        if (t and t.source == "focus") or (t is None and focus):
            if focus:
                pid, chosen = focus.product_id, dict(focus.chosen)
        else:
            pid = _screen_target(d, screen_ids)
            if pid is not None and focus and focus.product_id == pid:
                chosen = dict(focus.chosen)

        # Validate the model's pick: if the shopper NAMED a product and the
        # pick does not match that name, trust the name, not the index.
        kw = (d.filters.keywords or "").strip() if d.filters else ""
        if pid is not None and kw and _name_overlap(kw, catalog, pid) < 0.5:
            pid = None

        if pid is not None:
            return await _add(
                req,
                pid,
                d.quantity,
                catalog,
                meta(path, **mk),
                t0,
                options=d.options,
                chosen=chosen,
            )
        if kw:
            return await _add_by_name(
                req, d, kw, catalog, meta(path + ":by_name", **mk), t0
            )
        if not screen_ids:
            return _build(
                req,
                Patch(),
                None,
                Display(
                    template="ask",
                    speech="Which product would you like to add? "
                    "Try searching for it first.",
                ),
                meta(path + ":empty", **mk),
                t0,
            )
        return _clarify(
            req,
            d,
            screen_ids,
            _screen_names(screen_ids, catalog),
            "cart_add",
            meta(path + ":ambiguous", **mk),
            t0,
        )

    if d.intent == "cart_remove":
        items = _cart_items(req.ctx.cart)
        if not items:
            return _build(
                req,
                Patch(),
                None,
                Display(template="ack", speech="Your cart is empty."),
                meta(path + ":empty", **mk),
                t0,
            )
        item = _cart_target(d, items)
        if item is not None:
            return _remove(
                req, item["product_id"], _item_name(item, catalog), meta(path, **mk), t0
            )
        return _clarify(
            req,
            d,
            [i["product_id"] for i in items],
            [_item_name(i, catalog) for i in items],
            "cart_remove",
            meta(path + ":ambiguous", **mk),
            t0,
        )

    if d.intent == "clarify":
        then = (
            d.clarify_for
            if d.clarify_for in ("cart_add", "cart_remove")
            else "cart_add"
        )
        if then == "cart_remove":
            items = _cart_items(req.ctx.cart)
            return _clarify(
                req,
                d,
                [i["product_id"] for i in items],
                [_item_name(i, catalog) for i in items],
                then,
                meta(path, **mk),
                t0,
            )
        return _clarify(
            req,
            d,
            screen_ids,
            _screen_names(screen_ids, catalog),
            then,
            meta(path, **mk),
            t0,
        )

    if d.intent == "checkout":
        return _build(
            req,
            Patch(),
            NavigateAction(type="navigate", url="/checkout/"),
            Display(template="navigate", speech="Taking you to checkout."),
            meta(path, **mk),
            t0,
        )

    if d.intent == "faq":
        topic = (d.faq_topic or "").lower()
        answer = next(
            (f.answer for f in req.store.faq if f.topic.lower() == topic), None
        )
        if answer:
            return _build(
                req,
                Patch(),
                None,
                Display(template="faq", speech=answer),
                meta(path, **mk),
                t0,
            )
        return _other(
            req, meta(path + ":unknown_topic", **mk), t0
        )  # never guess a policy

    if d.intent == "order_status":
        return _build(
            req,
            Patch(),
            None,
            Display(template="order_lookup", speech=ORDER_LOOKUP_REPLY),
            meta(path, **mk),
            t0,
        )

    return _other(req, meta(path, **mk), t0)


def _screen_target(d: Decision, screen_ids: list[int]) -> int | None:
    t = d.target
    if t and t.source == "screen" and 1 <= t.index <= len(screen_ids):
        return screen_ids[t.index - 1]
    return None


def _cart_items(cart: list[dict]) -> list[dict]:
    out = []
    for item in cart:
        try:
            out.append({**item, "product_id": int(item.get("product_id"))})
        except (TypeError, ValueError):
            continue
    return out


def _cart_target(d: Decision, items: list[dict]) -> dict | None:
    t = d.target
    if t and t.source == "cart" and 1 <= t.index <= len(items):
        return items[t.index - 1]
    return None


def _item_name(item: dict, catalog) -> str:
    if item.get("name"):
        return str(item["name"])
    p = catalog.index.get(item["product_id"])
    return p.name if p else "that item"


# ───────────────────────────────────────────────────────────── cart handlers


async def _fresh(catalog, pid: int):
    """The product as WooCommerce has it RIGHT NOW. If the live read fails or is
    slow, fall back to the in-memory copy: WooCommerce still has the final say
    when the item is actually added."""
    refresh = getattr(catalog, "refresh_product", None)
    if refresh is not None:
        try:
            return await asyncio.wait_for(refresh(pid), timeout=2.0)
        except Exception as e:
            log.warning("live refresh of %s failed (%s); using cached copy", pid, e)
    return catalog.index.get(pid)


async def _add(
    req: TurnRequest,
    pid: int,
    qty: int,
    catalog,
    m,
    t0: float,
    options: dict | None = None,
    chosen: dict | None = None,
) -> TurnResponse:
    product = await _fresh(
        catalog, pid
    )  # never trust a cached stock flag for a purchase
    if product is None:
        return _build(
            req,
            Patch(focus=None),
            None,
            Display(template="ack", speech="I couldn't find that product any more."),
            m,
            t0,
        )
    if not product.in_stock:
        return _sold_out(req, product, catalog, m, t0)

    if product.type == "variable":
        return _options_step(
            req, product, _merge(product, chosen or {}, options or {}), qty, m, t0
        )

    speech = (
        f"Added {product.name} to your cart."
        if qty == 1
        else f"Added {qty} × {product.name} to your cart."
    )
    return _build(
        req,
        Patch(
            pending={"type": "undo", "product_id": pid, "qty": qty},
            added=[pid],
            focus=None,
        ),
        CartAddAction(type="cart.add", product_id=pid, qty=qty),
        Display(template="cart_added", speech=speech, buttons=list(ADD_CHIPS)),
        m,
        t0,
    )


def _sold_out(req: TurnRequest, product, catalog, m, t0: float) -> TurnResponse:
    """Confirmed out of stock by a live read. Offer in-stock alternatives instead of a dead end."""
    leaf = next(
        (
            c
            for c in product.categories
            if getattr(catalog.index.categories.get(c), "parent", 0) != 0
        ),
        None,
    )
    cat = leaf or next(iter(product.categories), None)
    similar = []
    if cat:
        res = catalog.index.search(
            SearchQuery(category=cat, exclude_ids=[product.id], limit=3)
        )
        similar = [p.card() for p in res.products]
    if not similar:
        return _build(
            req,
            Patch(focus=None),
            None,
            Display(
                template="ack", speech=f"{product.name} is out of stock right now."
            ),
            m,
            t0,
        )
    return _build(
        req,
        Patch(on_screen=[c["id"] for c in similar], focus=None),
        None,
        Display(
            template="product_list",
            speech=f"{product.name} is out of stock right now. Here are similar options.",
            items=similar,
        ),
        m,
        t0,
    )


def _name_overlap(keywords: str, catalog, pid: int) -> float:
    """Share of the keyword tokens that appear in the product's name (0..1)."""
    p = catalog.index.get(pid)
    want = set(tokenize(keywords))
    if p is None or not want:
        return 1.0
    return len(want & set(tokenize(p.name))) / len(want)


async def _add_by_name(
    req: TurnRequest, d: Decision, kw: str, catalog, m, t0: float
) -> TurnResponse:
    """The shopper named a product that is not on screen: find it in the catalog."""
    res = catalog.index.search(SearchQuery(text=kw, in_stock_only=False, limit=6))
    want = set(tokenize(kw))
    strong = [p for p in res.products if want and want <= set(tokenize(p.name))]
    if len(strong) == 1:
        return await _add(req, strong[0].id, d.quantity, catalog, m, t0)
    pool = strong or res.products[:3]
    if not pool:
        return _build(
            req,
            Patch(),
            None,
            Display(template="empty", speech=f'I couldn\'t find "{kw}" in the store.'),
            m,
            t0,
        )
    ids = [p.id for p in pool[:4]]
    names = [p.name for p in pool[:4]]
    question = (
        "Which one would you like to add?"
        if strong
        else f'I found these close to "{kw}" — which one?'
    )
    return _choose(req, ids, names, "cart_add", question, m, t0)


def _remove(
    req: TurnRequest, pid: int, name: str, m, t0: float, variation_id: int | None = None
) -> TurnResponse:
    return _build(
        req,
        Patch(focus=None),
        CartRemoveAction(type="cart.remove", product_id=pid, variation_id=variation_id),
        Display(
            template="cart_removed",
            speech=f"Removed {name} from your cart.",
            buttons=["Checkout"],
        ),
        m,
        t0,
    )


def _clarify(
    req: TurnRequest,
    d: Decision,
    ids: list[int],
    names: list[str],
    then: str,
    m,
    t0: float,
) -> TurnResponse:
    picked = [i - 1 for i in d.candidates if 1 <= i <= len(ids)]
    order = list(dict.fromkeys(picked or range(len(ids))))[:4]
    if not order:
        return _build(
            req,
            Patch(),
            None,
            Display(template="ask", speech="Which product do you mean?"),
            m,
            t0,
        )
    verb = "remove" if then == "cart_remove" else "add"
    return _choose(
        req,
        [ids[i] for i in order],
        [names[i] for i in order],
        then,
        f"Which one would you like to {verb}?",
        m,
        t0,
    )


def _choose(
    req: TurnRequest,
    ids: list[int],
    names: list[str],
    then: str,
    question: str,
    m,
    t0: float,
) -> TurnResponse:
    options = _button_labels(names)
    return _build(
        req,
        Patch(
            pending={
                "type": "choose",
                "question": question,
                "then": then,
                "options": options,
                "product_ids": ids,
            }
        ),
        None,
        Display(template="choose", speech=question, buttons=options),
        m,
        t0,
    )


def _button_labels(names: list[str]) -> list[str]:
    short = [n if len(n) <= 38 else n[:35].rstrip() + "…" for n in names]
    if len(set(short)) != len(short):
        short = [f"{i + 1}) {n}" for i, n in enumerate(short)]
    return short


# ───────────────────────────────────────────────────────────── variable products


def _attr_names(p) -> list[str]:
    names: list[str] = []
    for v in p.variations:
        for k in v.attrs:
            if k not in names:
                names.append(k)
    return names


def _values(p, attr: str) -> list[str]:
    out: list[str] = []
    for v in p.variations:
        lab = v.attrs.get(attr)
        if lab and lab not in out:
            out.append(lab)
    return out


def _match_attr(p, name: str) -> str | None:
    return next((a for a in _attr_names(p) if _norm(a) == _norm(name)), None)


def _match_value(p, attr: str, value: str) -> str | None:
    vals, nv = _values(p, attr), _norm(value)
    for lab in vals:
        if _norm(lab) == nv:
            return lab
    toks = set(nv.split())
    cands = [lab for lab in vals if toks and toks <= set(_norm(lab).split())]
    return cands[0] if len(cands) == 1 else None


def _matching(p, chosen: dict[str, str], in_stock_only: bool = True) -> list:
    out = []
    for v in p.variations:
        if in_stock_only and not v.in_stock:
            continue
        if all(
            v.attrs.get(a) is None or _norm(v.attrs[a]) == _norm(lab)
            for a, lab in chosen.items()
        ):
            out.append(v)
    return out


def _by_attr(variations: list) -> dict[str, list[str]]:
    out: dict[str, list[str]] = {}
    for v in variations:
        for a, lab in v.attrs.items():
            out.setdefault(a, [])
            if lab not in out[a]:
                out[a].append(lab)
    return out


def _merge(p, chosen: dict[str, str], new: dict[str, str]) -> dict[str, str]:
    """Add validated option values. Unknown attributes or values are dropped, never guessed."""
    out = dict(chosen)
    for k, val in new.items():
        attr = _match_attr(p, k)
        lab = _match_value(p, attr, val) if attr else None
        if attr and lab:
            out[attr] = lab
    return out


def _text_options(p, msg: str) -> dict[str, str]:
    """Read "brown and 8" against the product's own option values. Only returns a
    result when EVERY meaningful word maps to exactly one option value."""
    toks = [_NUMWORDS.get(t, t) for t in re.findall(r"[a-z0-9]+", msg.lower())]
    toks = [t for t in toks if t not in _OPT_FILLER]
    if not toks:
        return {}
    out: dict[str, str] = {}
    for t in toks:
        hits = [
            (a, lab)
            for a in _attr_names(p)
            for lab in _values(p, a)
            if t in _norm(lab).split()
        ]
        if len(hits) != 1:
            return {}
        a, lab = hits[0]
        if a in out and out[a] != lab:
            return {}
        out[a] = lab
    return out


def _options_step(
    req: TurnRequest, product, chosen: dict[str, str], qty: int, m, t0: float
) -> TurnResponse:
    """Work out what is still needed for this variable product: add it if every
    option is decided, otherwise ask for the next one."""
    if (
        not product.variations
    ):  # variation data unavailable: send them to the product page
        return _build(
            req,
            Patch(focus=None),
            None,
            Display(
                template="options_needed",
                speech=f"{product.name} comes in several options — choose your size and colour on its page.",
                items=[product.card()],
            ),
            m,
            t0,
        )

    chosen = dict(chosen)
    note = ""
    avail = _matching(product, chosen)
    if not avail:
        everything = _matching(product, {})
        if not everything:
            return _build(
                req,
                Patch(focus=None),
                None,
                Display(
                    template="ack",
                    speech=f"{product.name} is sold out in every option.",
                ),
                m,
                t0,
            )
        note = f"{' / '.join(chosen.values())} isn't available. "
        chosen, avail = {}, everything

    by = _by_attr(avail)
    changed = True
    while changed:  # a single remaining value needs no question
        changed = False
        for a, vals in by.items():
            if a not in chosen and len(vals) == 1:
                chosen[a] = vals[0]
                changed = True
        if changed:
            avail = _matching(product, chosen)
            by = _by_attr(avail)

    missing = [a for a in by if a not in chosen]
    if not missing:
        return _add_variation(req, product, avail[0], qty, m, t0)

    first = missing[0]
    summary = "; ".join(f"{a}: {', '.join(by[a])}" for a in missing)
    speech = f"{note}{product.name} (₹{product.price:,}) comes in {summary}."
    if chosen and not note:
        speech = f"Got it — {' / '.join(chosen.values())}. " + speech
    speech += f" Which {first.lower()} would you like?"
    if len(missing) > 1:
        speech += (
            f' You can also give both, like "{by[missing[0]][0]}, {by[missing[1]][0]}".'
        )

    chips = by[first][:6]
    return _build(
        req,
        Patch(
            focus={"product_id": product.id, "chosen": chosen},
            pending={
                "type": "option",
                "product_id": product.id,
                "attribute": first,
                "options": chips,
            },
        ),
        None,
        Display(template="options", speech=speech, buttons=chips),
        m,
        t0,
    )


def _add_variation(
    req: TurnRequest, product, variation, qty: int, m, t0: float
) -> TurnResponse:
    desc = ", ".join(f"{k}: {v}" for k, v in variation.attrs.items())
    label = f"{product.name} ({desc})" if desc else product.name
    speech = (
        f"Added {label} to your cart."
        if qty == 1
        else f"Added {qty} × {label} to your cart."
    )
    return _build(
        req,
        Patch(
            pending={
                "type": "undo",
                "product_id": product.id,
                "variation_id": variation.id,
                "qty": qty,
            },
            added=[product.id],
            focus=None,
        ),
        CartAddAction(
            type="cart.add", product_id=product.id, variation_id=variation.id, qty=qty
        ),
        Display(template="cart_added", speech=speech, buttons=list(ADD_CHIPS)),
        m,
        t0,
    )


# ───────────────────────────────────────────────────────────── search


def _search(req: TurnRequest, d: Decision, catalog, m, t0: float) -> TurnResponse:
    f = d.filters or Filters()
    category_slug = _resolve_category(f.category_text, catalog)
    text = (f.keywords or "").strip() or ("" if category_slug else req.msg)

    base = dict(
        text=text, category=category_slug, exclude_ids=req.ctx.rejected, limit=6
    )
    res = catalog.index.search(
        SearchQuery(max_price=f.max_price, min_price=f.min_price, **base)
    )

    relaxed = False
    if not res.products and (f.max_price or f.min_price):
        res = catalog.index.search(SearchQuery(**base))  # drop the price limits, say so
        relaxed = bool(res.products)

    products = [p.card() for p in res.products]
    label = f.keywords or f.category_text or req.msg
    new_slots = Slots(
        cat=category_slug,
        max_price=None if relaxed else f.max_price,
        min_price=None if relaxed else f.min_price,
    )

    # A new search means the shopper moved on: drop the focused product.
    if not products:
        return _build(
            req,
            Patch(slots=new_slots, on_screen=[], focus=None),
            None,
            Display(
                template="empty", speech=f'I couldn\'t find anything for "{label}".'
            ),
            m,
            t0,
        )

    if relaxed:
        limit_txt = (
            f"under ₹{f.max_price:,}" if f.max_price else f"over ₹{f.min_price:,}"
        )
        speech = f'Nothing {limit_txt} for "{label}" — here are the closest options.'
    else:
        speech = f'Here are {len(products)} results for "{label}".'

    return _build(
        req,
        Patch(on_screen=[p["id"] for p in products], slots=new_slots, focus=None),
        None,
        Display(
            template="product_list",
            speech=speech,
            items=products,
            buttons=_price_chips(products, new_slots.max_price),
        ),
        m,
        t0,
    )


def _resolve_category(text: str | None, catalog) -> str | None:
    """Fuzzy-match the model's category text to a real category slug, or None."""
    if not text:
        return None
    best_slug, best = None, 0.0
    for slug, cat in catalog.index.categories.items():
        ratio = difflib.SequenceMatcher(None, text.lower(), cat.name.lower()).ratio()
        if ratio > best:
            best, best_slug = ratio, slug
    return best_slug if best >= 0.5 else None


def _round_price(n: float) -> int:
    step = 100 if n >= 1000 else 50 if n >= 200 else 10
    return max(step, int(round(n / step) * step))


def _price_chips(products: list[dict], max_price: int | None) -> list[str]:
    if max_price and max_price > 300:
        return [f"Under ₹{_round_price(max_price * 0.6):,}"]
    prices = sorted(p["price"] for p in products if p.get("price"))
    if len(prices) >= 3:
        return [f"Under ₹{_round_price(prices[len(prices) // 2]):,}"]
    return []


# ───────────────────────────────────────────────────────────── other / fallback


def _other(req: TurnRequest, m, t0: float) -> TurnResponse:
    return _build(
        req, Patch(), None, Display(template="deflect", speech=OFF_TOPIC_REPLY), m, t0
    )


def _fallback_search(req: TurnRequest, catalog, t0: float) -> TurnResponse:
    """The decide call failed (timeout, provider down). Degrade to a plain
    keyword search so the shopper still gets something useful."""
    res = catalog.index.search(SearchQuery(text=req.msg, limit=6))
    products = [p.card() for p in res.products]
    if not products:
        return _build(
            req,
            Patch(),
            None,
            Display(
                template="empty",
                speech="I'm having trouble right now — please try again in a moment.",
            ),
            meta("fallback:empty"),
            t0,
        )
    return _build(
        req,
        Patch(on_screen=[p["id"] for p in products]),
        None,
        Display(
            template="product_list",
            speech="I'm having trouble understanding right now — here's what I found.",
            items=products,
        ),
        meta("fallback:search"),
        t0,
    )


# ───────────────────────────────────────────────────────────── prompt inputs


def _category_hint_list(catalog, limit: int = 40) -> list[str]:
    cats = list(catalog.index.categories.values())
    leaves = sorted((c for c in cats if c.parent != 0), key=lambda c: -c.count)
    tops = sorted((c for c in cats if c.parent == 0), key=lambda c: -c.count)
    names: list[str] = []
    for c in [*leaves, *tops]:
        if c.name not in names:
            names.append(c.name)
        if len(names) >= limit:
            break
    return names


def _screen_names(ids: list[int], catalog) -> list[str]:
    out = []
    for pid in ids:
        p = catalog.index.get(pid)
        out.append(p.name if p else f"product #{pid}")
    return out


def _screen_lines(ids: list[int], catalog) -> list[str]:
    out = []
    for pid in ids:
        p = catalog.index.get(pid)
        out.append(f"{p.name} — ₹{p.price:,}" if p else f"product #{pid}")
    return out


def _cart_lines(cart: list[dict], catalog) -> list[str]:
    return [f"{_item_name(i, catalog)} ×{i.get('qty', 1)}" for i in _cart_items(cart)]


def _pending_line(req: TurnRequest) -> str | None:
    p = req.ctx.pending
    if p is None:
        return None
    if p.type == "confirm":
        return f'Alice asked "{p.question}" (Yes / No)'
    if p.type == "choose":
        return (
            f"Alice asked which product to {'remove' if p.then == 'cart_remove' else 'add'}; options: "
            + " | ".join(p.options)
        )
    if p.type == "option":
        return f"Alice asked which {p.attribute}; options: " + " | ".join(p.options)
    return None


def _focus_line(req: TurnRequest, catalog) -> str | None:
    f = req.ctx.focus
    if f is None:
        return None
    p = catalog.index.get(f.product_id)
    if p is None:
        return None
    by = _by_attr(_matching(p, {}))
    opts = (
        "; ".join(f"{a}: {', '.join(v)}" for a, v in by.items()) or "no variation data"
    )
    chosen = ", ".join(f"{k}={v}" for k, v in f.chosen.items()) or "nothing yet"
    return f"{p.name} (₹{p.price:,}). Options: {opts}. Chosen so far: {chosen}."


# ───────────────────────────────────────────────────────────── shadow + build


def _log_shadow(req: TurnRequest, d: Decision, catalog, screen_ids: list[int]) -> None:
    names = [
        (catalog.index.get(pid).name if catalog.index.get(pid) else "")
        for pid in screen_ids
    ]
    rule = shadow_intent(req.msg, bool(screen_ids), names)
    shadow_log.info(
        json.dumps(
            {
                "msg": req.msg,
                "llm": d.intent,
                "rules": rule,
                "agree": None if rule is None else rule == d.intent,
            },
            ensure_ascii=False,
        )
    )


def _build(
    req: TurnRequest, patch: Patch, action, display: Display, m, t0: float
) -> TurnResponse:
    if "pending" not in patch.model_fields_set:
        patch.pending = None  # every turn clears stale pending unless it sets a new one
    m.ms = round((time.perf_counter() - t0) * 1000, 1)
    return TurnResponse(
        turn=req.turn, patch=patch, action=action, display=display, meta=m
    )
