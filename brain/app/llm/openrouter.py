# brain/app/llm/openrouter.py
"""Chat LLM client via OpenRouter. Every call returns usage + latency."""

from __future__ import annotations

import time
from dataclasses import dataclass
from typing import Any

import httpx

from app.config import settings


class LLMError(RuntimeError):
    pass


@dataclass(slots=True)
class LLMResult:
    text: str
    model: str
    input_tokens: int
    output_tokens: int
    latency_ms: float
    raw: dict

    def metrics(self) -> dict:
        return {
            "model": self.model,
            "input_tokens": self.input_tokens,
            "output_tokens": self.output_tokens,
            "latency_ms": self.latency_ms,
        }


class OpenRouter:
    def __init__(self, client: httpx.AsyncClient):
        self._c = client

    @classmethod
    def create(cls) -> "OpenRouter":
        return cls(
            httpx.AsyncClient(
                base_url=settings.openrouter_base_url,
                timeout=settings.llm_timeout_s,
                headers={
                    "Authorization": f"Bearer {settings.openrouter_api_key}",
                    "HTTP-Referer": "http://localhost",
                    "X-Title": "Mercora Alice",
                },
            )
        )

    async def close(self) -> None:
        await self._c.aclose()

    @property
    def configured(self) -> bool:
        return bool(settings.openrouter_api_key)

    async def chat(
        self,
        model: str,
        messages: list[dict[str, Any]],
        *,
        max_tokens: int = 256,
        temperature: float = 0.0,
        response_format: dict | None = None,
    ) -> LLMResult:
        if not self.configured:
            raise LLMError("OPENROUTER_API_KEY is not set in .env")
        if not model:
            raise LLMError("model id is empty — set LLM_MODEL in .env")

        body: dict[str, Any] = {
            "model": model,
            "messages": messages,
            "max_tokens": max_tokens,
            "temperature": temperature,
        }
        if response_format:
            body["response_format"] = response_format

        t0 = time.perf_counter()
        try:
            r = await self._c.post("/chat/completions", json=body)
        except httpx.TransportError as e:
            raise LLMError(f"OpenRouter unreachable: {e}") from e
        latency = round((time.perf_counter() - t0) * 1000, 1)

        if r.status_code >= 400:
            raise LLMError(f"OpenRouter {r.status_code}: {r.text[:300]}")

        data = r.json()
        usage = data.get("usage") or {}
        choice = (data.get("choices") or [{}])[0]
        return LLMResult(
            text=(choice.get("message") or {}).get("content") or "",
            model=data.get("model", model),
            input_tokens=int(usage.get("prompt_tokens", 0)),
            output_tokens=int(usage.get("completion_tokens", 0)),
            latency_ms=latency,
            raw=data,
        )
