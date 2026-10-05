# brain/app/catalog/index.py
"""In-memory catalog index: inverted indexes + price filter + deterministic scoring.

500 products (or 50k) fit comfortably in memory; a search is set intersections
and a sort over a few hundred items — sub-millisecond, no network hop.
"""

from __future__ import annotations

import html
import re
import time
from dataclasses import dataclass, field

from pydantic import BaseModel, Field

from app.catalog.models import Category, Product, Variation

_TOKEN = re.compile(r"[a-z0-9]+")
_STOP = {
    "the",
    "a",
    "an",
    "and",
    "or",
    "for",
    "with",
    "of",
    "in",
    "to",
    "me",
    "show",
    "i",
    "want",
    "need",
    "some",
}


def _stem(t: str) -> str:
    """Treat plurals alike ("dates" = "date", "chips" = "chip"). Applied to the
    catalog AND to queries, so the two always agree."""
    return t[:-1] if len(t) > 3 and t.endswith("s") and not t.endswith("ss") else t


def tokenize(text: str) -> list[str]:
    return [
        _stem(t) for t in _TOKEN.findall(html.unescape(text).lower()) if t not in _STOP
    ]


_TAGS = re.compile(r"<[^>]+>")


def _minor_to_rupees(value: str | int | None, minor_unit: int) -> int:
    if value in (None, ""):
        return 0
    return round(int(value) / (10**minor_unit))


def _match_attr_name(name: str, value: str, opt_maps: dict[str, dict[str, str]]) -> str:
    """Map a variation's attribute name onto the parent's attribute name."""
    if name in opt_maps:
        return name
    for k in opt_maps:
        if k.lower() == name.lower():
            return k
    for k, labels in opt_maps.items():  # fall back to "which attribute has this value?"
        if value in labels:
            return k
    return name


def _build_variations(
    raw_vars: list[dict], opt_maps: dict[str, dict[str, str]]
) -> list[Variation]:
    out: list[Variation] = []
    for v in raw_vars:
        try:
            vid = int(v["id"])
        except (KeyError, TypeError, ValueError):
            continue
        attrs: dict[str, str] = {}
        for va in v.get("attributes") or []:
            value = va.get("value") or ""
            if not value:  # empty = "any value": no constraint
                continue
            name = _match_attr_name(va.get("name", ""), value, opt_maps)
            attrs[name] = opt_maps.get(name, {}).get(value, html.unescape(str(value)))
        out.append(Variation(id=vid, attrs=attrs))
    return out


class SearchQuery(BaseModel):
    text: str = ""
    category: str | None = None
    min_price: int | None = None
    max_price: int | None = None
    attrs: dict[str, str] = Field(default_factory=dict)
    tags: list[str] = Field(default_factory=list)
    in_stock_only: bool = True
    exclude_ids: list[int] = Field(default_factory=list)
    limit: int = Field(8, ge=1, le=50)


@dataclass
class SearchResult:
    products: list[Product]
    total_matched: int
    took_ms: float
    scores: dict[int, float] = field(default_factory=dict)


class CatalogIndex:
    def __init__(self) -> None:
        self.products: dict[int, Product] = {}
        self.categories: dict[str, Category] = {}
        self.inv: dict[str, set[int]] = {}  # "cat:x" / "tag:x" / "brand:x" -> ids
        self.text: dict[str, set[int]] = {}  # token -> ids
        self.name_text: dict[
            str, set[int]
        ] = {}  # token -> ids (name only, weighted higher)
        self.loaded_at: float = 0.0

    @classmethod
    def build(
        cls, raw_products: list[dict], raw_categories: list[dict]
    ) -> "CatalogIndex":
        idx = cls()
        for c in raw_categories:
            cat = Category(
                id=c["id"],
                name=html.unescape(c["name"]),
                slug=c["slug"],
                parent=c.get("parent", 0),
                count=c.get("count", 0),
            )
            idx.categories[cat.slug] = cat

        n = max(len(raw_products), 1)
        for rank, raw in enumerate(raw_products):  # arrives in popularity order
            idx._add(idx._normalize(raw, popularity=1.0 - rank / n))
        idx.loaded_at = time.time()
        return idx

    @staticmethod
    def _normalize(raw: dict, popularity: float) -> Product:
        prices = raw.get("prices") or {}
        mu = int(prices.get("currency_minor_unit", 2))
        attrs: dict[str, set[str]] = {}
        labels: dict[str, list[str]] = {}
        opt_maps: dict[
            str, dict[str, str]
        ] = {}  # attribute name -> {slug: label}, variation attributes only
        for a in raw.get("attributes") or []:
            key = (a.get("taxonomy") or a.get("name", "")).removeprefix("pa_").lower()
            if key == "book-author":
                key = "author"
            terms = a.get("terms") or []
            attrs[key] = {t["slug"] for t in terms}
            labels[key] = [html.unescape(t["name"]) for t in terms]
            if a.get("has_variations"):
                opt_maps[a.get("name") or key] = {
                    t["slug"]: html.unescape(t["name"]) for t in terms
                }

        cats, tags, images = (
            raw.get("categories") or [],
            raw.get("tags") or [],
            raw.get("images") or [],
        )
        name = html.unescape(raw.get("name", ""))
        blurb = _TAGS.sub(
            " ",
            (raw.get("short_description") or "") + " " + (raw.get("description") or ""),
        )
        token_src = " ".join(
            [
                name,
                " ".join(html.unescape(c["name"]) for c in cats),
                " ".join(html.unescape(t["name"]) for t in tags),
                " ".join(v for vs in labels.values() for v in vs),
                html.unescape(blurb)[:600],  # "naturally sweet" in a description counts
            ]
        )
        return Product(
            id=raw["id"],
            name=name,
            slug=raw.get("slug", ""),
            url=raw.get("permalink", ""),
            price=_minor_to_rupees(prices.get("price"), mu),
            regular_price=_minor_to_rupees(prices.get("regular_price"), mu),
            on_sale=bool(raw.get("on_sale")),
            in_stock=bool(raw.get("is_in_stock")),
            low_stock=raw.get("low_stock_remaining"),
            type=raw.get("type", "simple"),
            categories={c["slug"] for c in cats},
            category_names=[html.unescape(c["name"]) for c in cats],
            tags={t["slug"] for t in tags},
            attrs=attrs,
            attr_labels=labels,
            image=(images[0].get("thumbnail") or images[0].get("src"))
            if images
            else None,
            rating=float(raw.get("average_rating") or 0),
            popularity=popularity,
            tokens=frozenset(tokenize(token_src)),
            variations=_build_variations(raw.get("variations") or [], opt_maps),
        )

    def _keys_for(self, p: Product) -> list[str]:
        keys = [f"cat:{c}" for c in p.categories] + [f"tag:{t}" for t in p.tags]
        return keys + [f"{k}:{v}" for k, vs in p.attrs.items() for v in vs]

    def _add(self, p: Product) -> None:
        self.products[p.id] = p
        for k in self._keys_for(p):
            self.inv.setdefault(k, set()).add(p.id)
        for t in p.tokens:
            self.text.setdefault(t, set()).add(p.id)
        for t in tokenize(p.name):
            self.name_text.setdefault(t, set()).add(p.id)

    # ------------------------------------------------------------ variations
    def apply_variation_details(self, rows: list[dict]) -> int:
        """Enrich variations with live stock and price from Store API variation rows
        (`/products?type=variation`). Rows must carry `parent`; others are skipped."""
        n = 0
        for r in rows:
            try:
                parent, vid = int(r.get("parent") or 0), int(r["id"])
            except (KeyError, TypeError, ValueError):
                continue
            p = self.products.get(parent)
            if p is None:
                continue
            for v in p.variations:
                if v.id == vid:
                    v.in_stock = bool(r.get("is_in_stock", True))
                    pr = r.get("prices") or {}
                    if pr.get("price") not in (None, ""):
                        v.price = _minor_to_rupees(
                            pr["price"], int(pr.get("currency_minor_unit", 2))
                        )
                    n += 1
        return n

    # ------------------------------------------------------------ search
    def unmatched_terms(self, text: str) -> list[str]:
        """Words in `text` that NO product in the store contains."""
        return [t for t in tokenize(text) if t not in self.text]

    def search(self, q: SearchQuery) -> SearchResult:
        t0 = time.perf_counter()
        pool: set[int] | None = None

        def narrow(ids: set[int]) -> None:
            nonlocal pool
            pool = set(ids) if pool is None else pool & ids

        if q.category:
            narrow(self.inv.get(f"cat:{q.category}", set()))
        for k, v in q.attrs.items():
            narrow(self.inv.get(f"{k}:{v}", set()))
        for t in q.tags:
            narrow(self.inv.get(f"tag:{t}", set()))

        q_tokens = tokenize(q.text)
        if q_tokens:
            hits: set[int] = set()
            for t in q_tokens:
                hits |= self.text.get(t, set())
            narrow(hits)

        candidates = self.products.keys() if pool is None else pool
        excluded = set(q.exclude_ids)

        scored: list[tuple[float, Product]] = []
        for pid in candidates:
            if pid in excluded:
                continue
            p = self.products[pid]
            if q.in_stock_only and not p.in_stock:
                continue
            if q.max_price is not None and p.price > q.max_price:
                continue
            if q.min_price is not None and p.price < q.min_price:
                continue
            scored.append((self._score(p, q_tokens, q), p))

        scored.sort(key=lambda sp: sp[0], reverse=True)
        top = scored[: q.limit]
        return SearchResult(
            products=[p for _, p in top],
            total_matched=len(scored),
            took_ms=round((time.perf_counter() - t0) * 1000, 3),
            scores={p.id: round(s, 4) for s, p in top},
        )

    def _score(self, p: Product, q_tokens: list[str], q: SearchQuery) -> float:
        if q_tokens:
            name_hits = sum(1 for t in q_tokens if p.id in self.name_text.get(t, ()))
            any_hits = sum(1 for t in q_tokens if t in p.tokens)
            text = (0.7 * name_hits + 0.3 * any_hits) / len(q_tokens)
        else:
            text = 0.0
        price_fit = 0.0
        if q.max_price:
            price_fit = max(
                0.0, min(1.0, 1.0 - abs(q.max_price * 0.7 - p.price) / q.max_price)
            )
        return (
            0.45 * text
            + 0.25 * p.popularity
            + 0.10 * (p.rating / 5)
            + 0.10 * price_fit
            + 0.05 * (1.0 if p.on_sale else 0.0)
            + 0.05 * (1.0 if p.in_stock else 0.0)
        )

    # ------------------------------------------------------- incremental sync
    def remove(self, pid: int) -> bool:
        p = self.products.pop(pid, None)
        if p is None:
            return False
        for k in self._keys_for(p):
            ids = self.inv.get(k)
            if ids is not None:
                ids.discard(pid)
                if not ids:
                    del self.inv[k]
        for t in p.tokens:
            ids = self.text.get(t)
            if ids is not None:
                ids.discard(pid)
                if not ids:
                    del self.text[t]
        for t in tokenize(p.name):
            ids = self.name_text.get(t)
            if ids is not None:
                ids.discard(pid)
                if not ids:
                    del self.name_text[t]
        return True

    def upsert(self, raw: dict) -> Product:
        """Replace one product in place, keeping its popularity rank if it already existed."""
        old = self.products.get(raw["id"])
        p = self._normalize(raw, popularity=old.popularity if old else 0.5)
        self.remove(raw["id"])
        self._add(p)
        return p

    # --------------------------------------------------------------- lookups
    def get(self, pid: int) -> Product | None:
        return self.products.get(pid)

    def children_of(self, slug: str) -> list[Category]:
        parent = self.categories.get(slug)
        if not parent:
            return []
        return sorted(
            (
                c
                for c in self.categories.values()
                if c.parent == parent.id and c.count > 0
            ),
            key=lambda c: -c.count,
        )

    def stats(self) -> dict:
        variable = sum(1 for p in self.products.values() if p.variations)
        return {
            "products": len(self.products),
            "categories": len(self.categories),
            "variable_products": variable,
            "index_keys": len(self.inv),
            "tokens": len(self.text),
            "loaded_at": self.loaded_at,
        }
