# brain/app/catalog/models.py
from __future__ import annotations

from dataclasses import dataclass, field


@dataclass(slots=True)
class Variation:
    id: int
    attrs: dict[str, str]  # {"Colour": "Brown", "Size": "UK 8"} — display labels
    in_stock: bool = True
    price: int | None = None


@dataclass(slots=True)
class Product:
    id: int
    name: str
    slug: str
    url: str
    price: int  # whole rupees
    regular_price: int
    on_sale: bool
    in_stock: bool
    low_stock: int | None
    type: str  # simple | variable
    categories: set[str] = field(default_factory=set)  # slugs (parent + leaf)
    category_names: list[str] = field(default_factory=list)
    tags: set[str] = field(default_factory=set)  # slugs
    attrs: dict[str, set[str]] = field(
        default_factory=dict
    )  # "brand" -> {"summit-peak"}
    attr_labels: dict[str, list[str]] = field(default_factory=dict)
    image: str | None = None
    rating: float = 0.0
    popularity: float = 0.0  # 0..1, from Woo popularity order
    tokens: frozenset[str] = frozenset()
    variations: list[Variation] = field(default_factory=list)

    def card(self) -> dict:
        """Compact shape sent to the UI."""
        return {
            "id": self.id,
            "name": self.name,
            "url": self.url,
            "price": self.price,
            "regular_price": self.regular_price,
            "on_sale": self.on_sale,
            "in_stock": self.in_stock,
            "low_stock": self.low_stock,
            "image": self.image,
            "brand": (self.attr_labels.get("brand") or [None])[0],
            "type": self.type,
        }


@dataclass(slots=True)
class Category:
    id: int
    name: str
    slug: str
    parent: int
    count: int
