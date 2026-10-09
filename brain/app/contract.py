# brain/app/contract.py
from __future__ import annotations

import json
from pathlib import Path
from typing import Annotated, Any, Literal, Optional, Union

import jsonschema
from pydantic import BaseModel, Field

_SCHEMA_PATH = Path(__file__).parents[2] / "contract" / "v1.schema.json"
_SCHEMA = json.loads(_SCHEMA_PATH.read_text())


class ContractError(ValueError):
    pass


def _validate(data: dict, definition: str) -> None:
    schema = {
        "$ref": f"#/definitions/{definition}",
        "definitions": _SCHEMA["definitions"],
    }
    errors = list(jsonschema.Draft7Validator(schema).iter_errors(data))
    if errors:
        e = errors[0]
        path = " -> ".join(str(p) for p in e.absolute_path)
        raise ContractError(
            f"{definition}: {path}: {e.message}"
            if path
            else f"{definition}: {e.message}"
        )


# ------------------------------------------------------------------ slots / pending


class Slots(BaseModel):
    model_config = {"extra": "allow"}
    cat: str | None = None
    max_price: int | None = None
    min_price: int | None = None


class PendingSlot(BaseModel):
    model_config = {"extra": "allow"}
    type: Literal["slot"]
    slot: str = ""
    product: str = ""
    expected: list[str] = Field(default_factory=list)


class PendingConfirm(BaseModel):
    model_config = {"extra": "allow"}
    type: Literal["confirm"]
    question: str = ""
    options: list[str] = Field(default_factory=lambda: ["Yes", "No"])
    product_id: int | None = None


class PendingChoose(BaseModel):
    """Alice asked "which one?" and offered buttons. A tap on a button is an
    exact match against `options`, so it resolves with no model call."""

    model_config = {"extra": "allow"}
    type: Literal["choose"]
    question: str = ""
    then: str = "cart_add"
    options: list[str] = Field(default_factory=list)
    product_ids: list[int] = Field(default_factory=list)


class PendingUndo(BaseModel):
    """Set right after an add-to-cart so the Undo button is one exact match."""

    model_config = {"extra": "allow"}
    type: Literal["undo"]
    product_id: int
    variation_id: int | None = None
    qty: int = 1


class PendingOption(BaseModel):
    """Alice asked "which colour / size?" and offered buttons for one attribute.
    A tap is an exact match against `options`, so it needs no model call."""

    model_config = {"extra": "allow"}
    type: Literal["option"]
    product_id: int
    attribute: str = ""
    options: list[str] = Field(default_factory=list)


Pending = Optional[
    Annotated[
        Union[PendingSlot, PendingConfirm, PendingChoose, PendingUndo, PendingOption],
        Field(discriminator="type"),
    ]
]


class Focus(BaseModel):
    """The product the shopper is currently deciding on. It persists across turns
    until they search for something else or finish the add, so a follow-up like
    "brown and 8" is understood as being about THIS product."""

    model_config = {"extra": "allow"}
    product_id: int
    chosen: dict[str, str] = Field(default_factory=dict)


class Ctx(BaseModel):
    on_screen: list[int] = Field(default_factory=list)
    viewed: list[int] = Field(default_factory=list)
    added: list[int] = Field(default_factory=list)
    rejected: list[int] = Field(default_factory=list)
    slots: Slots = Field(default_factory=Slots)
    pending: Pending = None
    focus: Optional[Focus] = None
    cart: list[dict] = Field(default_factory=list)
    page: str | None = None
    past_purchases: list[int] = Field(default_factory=list)
    category_history: list[str] = Field(default_factory=list)
    """Category slugs the shopper has discussed, most recent first, max 3.

    The brain is stateless: WordPress owns this list (durably, per customer) and
    sends it in with every turn. The brain pushes the current category onto the
    front and hands the new list back in `patch.category_history`."""


# ------------------------------------------------------------------ store


class StoreAttribute(BaseModel):
    model_config = {"extra": "allow"}
    slug: str
    label: str
    values: list[str] = Field(default_factory=list)


class StoreFAQ(BaseModel):
    model_config = {"extra": "allow"}
    topic: str
    answer: str


class Store(BaseModel):
    model_config = {"extra": "allow"}
    name: str = "Store"
    currency: str = "INR"
    locale: str = "en-IN"
    attributes: list[StoreAttribute] = Field(default_factory=list)
    faq: list[StoreFAQ] = Field(default_factory=list)


# ------------------------------------------------------------------ request


class TurnRequest(BaseModel):
    v: Literal[1]
    turn: int = Field(ge=1)
    session: str
    catalog_version: int
    msg: str = Field(min_length=1, max_length=1000)
    placeholders: list[Any] = Field(default_factory=list)
    store: Store = Field(default_factory=Store)
    ctx: Ctx


# ------------------------------------------------------------------ response


class Patch(BaseModel):
    on_screen: list[int] | None = None
    viewed: list[int] | None = None
    added: list[int] | None = None
    rejected: list[int] | None = None
    slots: Slots | None = None
    pending: Any = None
    focus: Any = None
    page: str | None = None
    category_history: list[str] | None = None


class RecoTier(BaseModel):
    category: str | None = None
    product_ids: list[int] = Field(default_factory=list)
    # also_like only: the complementary categories, and the inferred plan
    # ("trekking & camping trip") that explains why they are here.
    categories: list[str] | None = None
    title: str | None = None


class Reco(BaseModel):
    """Tiered recommendation payload for the store's homepage.

    `product_ids` is ranked *intent*, not a promise: the brain's catalog snapshot
    can lag the store, so WordPress re-validates every id against live stock and
    visibility and backfills from `category` before rendering."""

    v: Literal[1] = 1
    category_history: list[str] = Field(default_factory=list)
    tiers: dict[str, RecoTier] = Field(default_factory=dict)


class CartAddAction(BaseModel):
    type: Literal["cart.add"]
    product_id: int
    variation_id: int | None = None
    qty: int = Field(1, ge=1, le=20)


class CartRemoveAction(BaseModel):
    type: Literal["cart.remove"]
    product_id: int
    variation_id: int | None = None


class NavigateAction(BaseModel):
    type: Literal["navigate"]
    url: str


Action = Optional[Union[CartAddAction, CartRemoveAction, NavigateAction]]


class Display(BaseModel):
    template: str
    speech: str
    items: list[dict] = Field(default_factory=list)
    buttons: list[str] = Field(default_factory=list)
    table: dict | None = None


class Meta(BaseModel):
    path: str
    llm_calls: int = 0
    tokens_in: int = 0
    tokens_out: int = 0
    ms: float = 0.0


def _patch_to_wire(p: Patch) -> dict:
    """Serialise a patch for the wire.

    Fields the pipeline explicitly set are sent even when null — that is how a
    patch CLEARS `pending` or a slot (e.g. dropping a price ceiling when the
    shopper changes topic). Dropping None values here was the cause of stale
    pending questions that never went away.
    """
    out: dict[str, Any] = {}
    for name in p.model_fields_set:
        v = getattr(p, name)
        if name == "slots":
            if v is not None:
                out["slots"] = v.model_dump(mode="json")  # full set, nulls included
        elif name in ("pending", "focus"):
            out[name] = v.model_dump(mode="json") if isinstance(v, BaseModel) else v
        elif name == "page":
            out["page"] = v
        elif v is not None:
            out[name] = v
    return out


class TurnResponse(BaseModel):
    turn: int
    patch: Patch
    action: Action = None
    display: Display
    meta: Meta
    reco: Reco | None = None

    def to_dict(self) -> dict:
        out = {
            "turn": self.turn,
            "patch": _patch_to_wire(self.patch),
            "action": self.action.model_dump(mode="json") if self.action else None,
            "display": self.display.model_dump(exclude_none=True, mode="json"),
            "meta": self.meta.model_dump(mode="json"),
        }
        if self.reco is not None:
            out["reco"] = self.reco.model_dump(mode="json")
        return out


# ------------------------------------------------------------------ helpers


def parse_request(raw: dict) -> TurnRequest:
    _validate(raw, "turn_request")
    return TurnRequest.model_validate(raw)


def validate_response(resp: TurnResponse) -> None:
    _validate(resp.to_dict(), "turn_response")


def meta(
    path: str,
    *,
    llm_calls: int = 0,
    tokens_in: int = 0,
    tokens_out: int = 0,
    ms: float = 0.0,
) -> Meta:
    return Meta(
        path=path,
        llm_calls=llm_calls,
        tokens_in=tokens_in,
        tokens_out=tokens_out,
        ms=ms,
    )
