# brain/app/config.py
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env", env_file_encoding="utf-8", extra="ignore"
    )

    # WooCommerce
    woo_base_url: str = "http://127.0.0.1"
    woo_timeout_s: float = 10.0
    catalog_refresh_s: int = 300  # full-reload safety net
    webhook_secret: str = "change-me"  # shared with the Mercora plugin

    # API
    cors_origins: str = "http://127.0.0.1,http://localhost"
    debug: bool = False

    # OpenRouter — chat LLM
    openrouter_api_key: str = ""
    openrouter_base_url: str = "https://openrouter.ai/api/v1"
    llm_model: str = ""
    llm_timeout_s: float = 8.0

    # OpenRouter — Jev (separate endpoint)
    openrouter_decisions_url: str = "https://openrouter.ai/api/alpha/decisions"
    jev_model: str = "typesafe/jev-1.13"
    jev_timeout_s: float = 3.0

    @property
    def store_api(self) -> str:
        return f"{self.woo_base_url.rstrip('/')}/wp-json/wc/store/v1"

    @property
    def cors_list(self) -> list[str]:
        return [o.strip() for o in self.cors_origins.split(",") if o.strip()]


settings = Settings()
