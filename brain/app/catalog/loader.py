# brain/app/catalog/loader.py
"""Keeps the in-memory index in sync with WooCommerce.

Three paths, cheapest first:
1. Stock patch:  the plugin sends the new stock state with the push → applied in
                 memory instantly, no extra request (orders, refunds, stock edits).
2. Detail fetch: name/price/attribute/variation changes → product IDs are queued and
                 fetched in one batched Store API request every SYNC_DEBOUNCE_S.
3. Full reload:  every CATALOG_REFRESH_S as a safety net for anything missed.

Variation stock comes from a second call (`type=variation`). If a store doesn't
support it, the brain still works: variations are assumed in stock and WooCommerce
has the final say when the item is actually added to the cart.
"""

from __future__ import annotations

import asyncio
import logging
import time

from app.catalog.index import CatalogIndex
from app.config import settings
from app.woo.store_api import StoreAPI

log = logging.getLogger("brain.catalog")

FULL_RELOAD_THRESHOLD = 50
SYNC_DEBOUNCE_S = 0.25
LOW_STOCK_AT = 5


class CatalogStore:
    def __init__(self, api: StoreAPI):
        self.api = api
        self.index = CatalogIndex()
        self._lock = asyncio.Lock()
        self._task: asyncio.Task | None = None
        self._pending: set[int] = set()
        self._pending_deleted: set[int] = set()
        self._flush_task: asyncio.Task | None = None
        self.sync = {
            "pushes": 0,
            "stock_patches": 0,
            "products_updated": 0,
            "products_removed": 0,
            "detail_fetches": 0,
            "full_reloads": 0,
            "variation_stock": None,
            "last_push_at": None,
            "last_full_reload_at": None,
        }

    async def reload(self) -> dict:
        async with self._lock:
            t0 = time.perf_counter()
            products, categories = await asyncio.gather(
                self.api.all_products(), self.api.categories()
            )
            index = CatalogIndex.build(products, categories)

            try:
                rows = await self.api.all_variations()
                enriched = index.apply_variation_details(rows)
                self.sync["variation_stock"] = True
                log.info("variation details: %s variations enriched", enriched)
            except Exception as e:  # keep working without per-variation stock
                self.sync["variation_stock"] = False
                log.warning(
                    "variation stock unavailable (%s); assuming variations are in stock",
                    e,
                )

            self.index = index  # atomic swap
            took = round((time.perf_counter() - t0) * 1000)
            self.sync["full_reloads"] += 1
            self.sync["last_full_reload_at"] = time.time()
            log.info("catalog loaded: %s products in %sms", len(products), took)
            return {**self.index.stats(), "took_ms": took}

    async def refresh_product(self, pid: int):
        """Re-read ONE product (and its variations) straight from WooCommerce.

        Used right before an add-to-cart so a stale in-memory stock flag can
        never make Alice refuse something that is actually in stock.
        Returns the fresh Product, or None if it no longer exists."""
        raws = await self.api.products_by_ids([pid])
        rows: list[dict] = []
        is_variable = bool(raws) and raws[0].get("type") == "variable"
        if is_variable and self.sync.get("variation_stock") is not False:
            try:
                rows = await self.api.variations_of(pid)
            except Exception:
                log.warning("could not refresh variations of %s", pid)
        async with self._lock:
            if not raws:
                self.index.remove(pid)
                return None
            self.index.upsert(raws[0])
            if rows:
                self.index.apply_variation_details(rows)
            return self.index.get(pid)

    def apply_push(
        self, stock: dict[int, dict], details: list[int], deleted: list[int]
    ) -> dict:
        self.sync["pushes"] += 1
        self.sync["last_push_at"] = time.time()
        patched = 0
        for pid, s in stock.items():
            p = self.index.get(pid)
            if p is None:
                details.append(pid)
                continue
            p.in_stock = bool(s.get("in_stock"))
            qty = s.get("qty")
            p.low_stock = (
                qty if isinstance(qty, int) and 0 < qty <= LOW_STOCK_AT else None
            )
            patched += 1
            if p.variations:  # a size/colour may have sold out: refresh variations too
                details.append(pid)
        self.sync["stock_patches"] += patched

        self._pending.update(details)
        self._pending_deleted.update(deleted)
        if (self._pending or self._pending_deleted) and (
            self._flush_task is None or self._flush_task.done()
        ):
            self._flush_task = asyncio.create_task(self._flush_later())
        return {
            "stock_patched": patched,
            "queued": len(self._pending) + len(self._pending_deleted),
        }

    async def _flush_later(self) -> None:
        await asyncio.sleep(SYNC_DEBOUNCE_S)
        changed, deleted = (
            self._pending - self._pending_deleted,
            set(self._pending_deleted),
        )
        self._pending.clear()
        self._pending_deleted.clear()
        try:
            if len(changed) > FULL_RELOAD_THRESHOLD:
                await self.reload()
                return
            await self._fetch_and_apply(sorted(changed), deleted)
        except Exception:
            log.exception("detail sync failed; the next full reload will catch up")

    async def _fetch_and_apply(self, changed: list[int], deleted: set[int]) -> None:
        t0 = time.perf_counter()
        raws = await self.api.products_by_ids(changed) if changed else []

        variation_rows: list[dict] = []
        if self.sync.get("variation_stock") is not False:
            for raw in raws:
                if raw.get("type") == "variable":
                    try:
                        variation_rows.extend(await self.api.variations_of(raw["id"]))
                    except Exception:
                        log.warning("could not refresh variations of %s", raw.get("id"))

        async with self._lock:
            returned = set()
            for raw in raws:
                self.index.upsert(raw)
                returned.add(raw["id"])
            if variation_rows:
                self.index.apply_variation_details(variation_rows)
            gone = deleted | (set(changed) - returned)
            removed = sum(1 for pid in gone if self.index.remove(pid))
        self.sync["detail_fetches"] += 1
        self.sync["products_updated"] += len(returned)
        self.sync["products_removed"] += removed
        log.info(
            "detail sync: %s updated, %s removed in %sms",
            len(returned),
            removed,
            round((time.perf_counter() - t0) * 1000),
        )

    async def _loop(self) -> None:
        while True:
            await asyncio.sleep(settings.catalog_refresh_s)
            try:
                await self.reload()
            except Exception:
                log.exception("catalog refresh failed")

    def start(self) -> None:
        self._task = asyncio.create_task(self._loop())

    async def stop(self) -> None:
        for t in (self._task, self._flush_task):
            if t:
                t.cancel()
