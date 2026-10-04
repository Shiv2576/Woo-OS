# brain/app/graph/shadow.py
"""Shadow check. Runs the old rule-based reading of a message ALONGSIDE the LLM
and returns what the rules would have decided. The result is only logged, it
never changes what the shopper sees. After a week of real traffic the log shows
which message types the rules match the LLM on, and only those are safe to
promote to a no-LLM shortcut."""

from __future__ import annotations

import difflib
import re

_FILLER = {"the", "a", "an", "please", "to", "my", "it", "one", "this", "that"}
_CHECKOUT = {"checkout", "check", "out", "proceed", "go"}
_ADD = {"add", "put", "cart", "basket", "bag"}
_ORD = {"first", "second", "third", "fourth", "fifth", "last"}


def _tokens(t: str) -> list[str]:
    return re.findall(r"[a-z0-9]+", t.lower())


def _covered(tokens: list[str], known: set[str]) -> bool:
    return all(t in known or t in _FILLER or t in _ORD for t in tokens)


def _name_match(msg: str, names: list[str], threshold: float = 0.45) -> int | None:
    best_i, best = None, 0.0
    m = msg.lower()
    for i, name in enumerate(names):
        n = name.lower()
        ratio = difflib.SequenceMatcher(None, m, n).ratio()
        if n in m or any(w in m for w in n.split() if len(w) > 3):
            ratio += 0.2
        if ratio > best:
            best, best_i = ratio, i
    return best_i if best >= threshold else None


def shadow_intent(msg: str, has_screen: bool, screen_names: list[str]) -> str | None:
    tokens = _tokens(msg)
    if not tokens:
        return None
    if _covered(tokens, _CHECKOUT) and any(t in _CHECKOUT for t in tokens):
        return "checkout"
    has_add = any(t in _ADD for t in tokens)
    if has_add and screen_names and _name_match(msg, screen_names) is not None:
        return "cart_add"
    if has_add and _covered(tokens, _ADD) and has_screen:
        return "cart_add"
    return None
