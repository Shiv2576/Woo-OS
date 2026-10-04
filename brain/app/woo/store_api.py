# brain/app/woo/store_api.py
"""Thin async client for the WooCommerce Store API (public, customer-facing)."""

from __future__ import annotations

import asyncio
from typing import Any

import httpx

from app.config import settings


class StoreAPIError(RuntimeError):
    pass


class StoreAPI:
    def __init__(self, client: httpx.AsyncClient):
        self._c = client

    @classmethod
    def create(cls) -> "StoreAPI":
        return cls(
            httpx.AsyncClient(
                base_url=settings.store_api, timeout=settings.woo_timeout_s
            )
        )

    async def close(self) -> None:
        await self._c.aclose()

    async def _get(
        self, path: str, params: dict[str, Any] | None = None, retries: int = 2
    ) -> httpx.Response:
        for attempt in range(retries + 1):
            try:
                r = await self._c.get(path, params=params)
            except httpx.TransportError as e:
                if attempt >= retries:
                    raise StoreAPIError(f"GET {path} failed: {e}") from e
                await asyncio.sleep(0.3 * (2**attempt))
                continue
            if 400 <= r.status_code < 500:  # our request is wrong: retrying won't help
                raise StoreAPIError(f"GET {path} -> {r.status_code}: {r.text[:200]}")
            if r.status_code >= 500:
                if attempt >= retries:
                    raise StoreAPIError(f"GET {path} -> {r.status_code}")
                await asyncio.sleep(0.3 * (2**attempt))
                continue
            return r
        raise StoreAPIError("unreachable")

    async def _all_pages(self, params: dict[str, Any]) -> list[dict]:
        first = await self._get("/products", {**params, "page": 1})
        pages = int(first.headers.get("X-WP-TotalPages", "1"))
        rest = await asyncio.gather(
            *[
                self._get("/products", {**params, "page": p})
                for p in range(2, pages + 1)
            ]
        )
        out: list[dict] = list(first.json())
        for r in rest:
            out.extend(r.json())
        return out

    async def all_products(self, orderby: str = "popularity") -> list[dict]:
        """Every published product (100 per page, pages fetched concurrently)."""
        return await self._all_pages(
            {"per_page": 100, "orderby": orderby, "order": "desc"}
        )

    async def all_variations(self) -> list[dict]:
        """Every variation with its own stock + price (Store API `type=variation`).
        Raises StoreAPIError if this store/WooCommerce version does not support it."""
        return await self._all_pages({"per_page": 100, "type": "variation"})

    async def variations_of(self, parent_id: int) -> list[dict]:
        r = await self._get(
            "/products", {"type": "variation", "parent": parent_id, "per_page": 100}
        )
        return [row for row in r.json() if int(row.get("parent") or 0) == parent_id]

    async def products_by_ids(self, ids: list[int]) -> list[dict]:
        if not ids:
            return []
        r = await self._get(
            "/products", {"include": ",".join(map(str, ids[:100])), "per_page": 100}
        )
        return r.json()

    async def categories(self) -> list[dict]:
        r = await self._get("/products/categories", {"per_page": 100})
        return r.json()
