# brain/app/main.py
from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.api.routes import router
from app.api.webhooks import router as webhook_router
from app.catalog.loader import CatalogStore
from app.config import settings
from app.llm.openrouter import OpenRouter
from app.woo.store_api import StoreAPI

logging.basicConfig(
    level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s"
)


@asynccontextmanager
async def lifespan(app: FastAPI):
    woo = StoreAPI.create()
    llm = OpenRouter.create()
    catalog = CatalogStore(woo)
    await catalog.reload()
    catalog.start()

    app.state.woo = woo
    app.state.llm = llm
    app.state.catalog = catalog
    yield
    await catalog.stop()
    await llm.close()
    await woo.close()


app = FastAPI(title="Alice Brain", version="0.2.0", lifespan=lifespan)
app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.cors_list,
    allow_methods=["*"],
    allow_headers=["*"],
    allow_credentials=True,
)
app.include_router(router)
app.include_router(webhook_router)
