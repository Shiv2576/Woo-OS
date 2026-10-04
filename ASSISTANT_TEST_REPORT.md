# Alice Assistant — Scenario Test Report (re-run)

**Context since the last report:** the brain's graph was rewritten
(`app/graph/parser.py`, `app/graph/pipeline.py`, new `app/llm_router.py`).
The old "price regex short-circuits search" bug and the "confirm discards
the cart action" bug are both fixed. This re-run exercises the same 10
scenarios against the new code, driven the same way as before — a script
that posts to the brain's `/turn` endpoint using the exact session/turn/ctx
bookkeeping `alice.js` does, **including the fact that `alice.js` does not
send a `store` field** (no FAQ list, attributes, name or currency) — so this
accurately reflects what `/assistant` actually does today, not an idealized
payload. Brain was healthy throughout (500 products, 40 categories, LLM
configured).

**Architecture change worth calling out up front:** the new pipeline routes
*every* message that the parser doesn't fully resolve (yes/no/checkout/
select/add_confirm/slot_answer) through one LLM call that both classifies
intent and extracts filters (`app/llm_router.py`). That means plain free-text
product search — which several scenarios below expect to cost "0 LLM
calls" — now always costs 1. This is a deliberate design change (comment in
`pipeline.py`: "LLM route + extract — the default path"), not a bug, but it
means the "0 LLM calls" expectations in scenarios 1 and 2 no longer hold
under the current implementation.

---

## 1. Basic product search

| Message | Path | LLM | Items | Verdict |
|---|---|---|---|---|
| `show me running shoes` | `llm:product` | **1** | 6, all running shoes | ⚠️ Results correct, but costs an LLM call (expected 0) |
| `waterproof hiking boots under 6000` | `llm:product` | **1** | 6 — hiking/mountain boots, `max_price` respected | ⚠️ Same — correct results, unexpected LLM call |
| `vegan snacks under 500` | `llm:product` | **1** | 6 — vegan cookies, quinoa puffs, etc. | ⚠️ Correct, unexpected LLM call |
| `sour candy` | `llm:product` | **1** | 6 sour candy items | ⚠️ Correct, unexpected LLM call |
| `dark chocolate gift box` | `llm:product` | **1** | 6 chocolate gift items | ⚠️ Correct, unexpected LLM call |

The **price-cutoff bug from the last report is fixed** — "under 6000" and
"under 500" now return real, correctly filtered results instead of zero
items. The only remaining mismatch against the scenario text is the LLM-call
count, which is architectural (see note above), not a defect in the result
quality itself.

---

## 2. Price refinement

Run as its own fresh session, starting from "waterproof hiking boots under 6000":

| Message | Path | Category resolved | Items |
|---|---|---|---|
| `waterproof hiking boots under 6000` | `llm:product` | `outdoor-hiking` | 6 — **hiking backpacks, a travel bag, a steel water bottle** |
| `cheaper ones` | `llm:product` | `outdoor-hiking` (kept) | 6 — same backpacks/bottle, now ≤6000 |
| `under 3000` | `llm:product` | `outdoor-hiking` (kept) | 6 — same set, now ≤3000 |

❌ **New bug found: category resolution is inconsistent for the identical
message.** In scenario 1 (run after "show me running shoes" had already set
`slots.cat = footwear`), the exact same query — "waterproof hiking boots
under 6000" — correctly returned boots. Here, in a **fresh session** with no
prior category context, the LLM router classified the identical message's
`category_text` as `outdoor-hiking` instead of `footwear`, which pulled
backpacks and water bottles, not boots. (`outdoor-hiking` is a real
top-level category that legitimately contains backpacks/tents/bottles, so
`_resolve_category`'s fuzzy match isn't wrong given what the LLM extracted —
the LLM's own classification of "hiking boots" as outdoor gear rather than
footwear is the inconsistency, and it's session/context-dependent for the
*same input text*.) The mechanics of price refinement itself are correct —
`cheaper ones` → ×0.7 (6000→4200, confirmed in the raw patch), `under 3000`
→ re-searches with the new ceiling, and the category is carried forward
correctly — it's just carrying forward the *wrong* category from turn 1.

---

## 3. Reference resolution

| Message | Path | Speech | Action |
|---|---|---|---|
| `show me running shoes` | `llm:product` | 6 results | — |
| `add the first one` | `parser:add_confirm` | **"Add Kicks Kulture Classic Running Shoes to your cart?"** | — |
| `yes` | `parser:yes` | **"Added Kicks Kulture Classic Running Shoes to your cart."** | **`{"type":"cart.add","product_id":934,...}`** |
| `show me the second one` | `llm:product` | "Here are 6 results for…" (sneakers/sandals/boots mix) | — |

✅ **The confirm → cart-add bug from the last report is fixed.** The confirm
prompt now names the actual product, and the follow-up "yes" returns a real
`cart.add` action carrying the right `product_id` — exactly the expected
behavior.

⚠️ **"show me the second one" still isn't treated as a reference.** Rather
than acknowledging product #2 from the on-screen list (as scenario 7 proves
the brain *can* do via the bare-ordinal path in `parser.py`), this phrasing
routes through the LLM product search as free text and returns an unrelated
mixed set. The bare-ordinal parser path only fires when the message is
*just* the ordinal ("the second one"), not when wrapped in "show me ___" —
worth widening if this phrasing is expected to work.

---

## 4. Vague query → clarifying question

| Message | Path | Items |
|---|---|---|
| `shoes` | `llm:product` | 6 (sneakers, sandals, hiking boots mixed) |
| `Sneakers` (chip click) | `llm:product` | 6 sneakers |

❌ **Still not implemented.** No clarifying-question/category-chip node
exists anywhere in `parser.py`, `pipeline.py`, or `llm_router.py` — "shoes"
goes straight to a mixed product list instead of "What kind of shoes? —
Sneakers / Hiking Boots / Sandals". Functionally harmless (the shopper still
gets shoes), but the described disambiguation UX doesn't exist.

---

## 5. Cart and checkout

| Message | Path | Action |
|---|---|---|
| `show me running shoes` | `llm:product` | — |
| `add the first one to my cart` | `parser:add_confirm` | — (names the product correctly) |
| `yes` | `parser:yes` | `{"type":"cart.add","product_id":934,...}` |
| `checkout` | `parser:checkout` | `{"type":"navigate","url":"/checkout/"}` |

✅ **Full pass.** This scenario now works end-to-end exactly as described —
confirm, add, and checkout-navigate all fire correctly in sequence.

---

## 6. Multi-word natural language (LLM call expected)

| Message | Path | LLM | Category resolved | Items |
|---|---|---|---|---|
| `I need something comfortable for long distance running, budget around 4000` | `llm:product` | 1 | `sports-fitness` | 6 — **foam roller, adjustable bench, yoga strap** (not running shoes) |
| `something for a monsoon trek, waterproof, not too heavy` | `llm:product` | 1 | `sports-fitness` | 6 — same foam roller/bench/yoga strap set (not hiking boots) |
| `gift for my mom, she likes skincare, under 1500` | `llm:product` | 1 | `beauty-personal-care` | 6 — hair oil, vitamin-C serum, night cream | ✅ matches expectation |

✅ The "1 LLM call" expectation is now met for all three (unlike scenarios 1–2,
this is genuinely the LLM-extraction path).

❌ **Two of three misclassify the category.** "Long distance running" and
"monsoon trek, waterproof" both got bucketed into `sports-fitness` and
returned gym equipment, not running shoes or hiking boots. Only the
skincare-gift query resolved correctly. This is an LLM classification
accuracy issue (same model, same temperature=0, same prompt shape) rather
than a code defect — but it means two of the three "expected" results in
this scenario did not happen.

---

## 7. Follow-up context (session memory)

| Message | Path | Patch |
|---|---|---|
| `show me yoga mats` | `llm:product` | `on_screen: [465, 859, 238, 240, 828, 986]` |
| `add the second one` | `parser:add_confirm` | question: **"Add FitNest 8mm Yoga Mat to your cart?"** |

✅ **Pass, and now verifiable** (the earlier report flagged this as
unverifiable because the confirm text didn't name a product — that's fixed).
`on_screen[1]` (id 859, "FitNest 8mm Yoga Mat") is exactly what got confirmed,
proving session context is correctly driving reference resolution.

---

## 8. FAQ and support stubs

| Message | Path | Speech (actual) |
|---|---|---|
| `what is your return policy` | `llm:other` | "I'm here to help you shop — try asking me to find a product." |
| `where is my order` | `llm:order` | "Sure — what's your order number? You can find it in your confirmation email." |
| `how long does shipping take` | `llm:other` | "I'm here to help you shop — try asking me to find a product." |

❌ **The FAQ node exists in code now (`pipeline.py:_faq`) but can never
fire from `/assistant` today.** It looks up the answer by matching
`rr.faq_topic` against `req.store.faq` — and `req.store` is **never
populated**, because `alice.js` doesn't send a `store` field in its payload
at all (confirmed by reading the current `alice.js`), so every request gets
the contract's default `Store()` with an empty FAQ list. The router's own
prompt tells the LLM "FAQ topics this store has answers for: none", so it
correctly never picks `route="faq"` — return-policy and shipping questions
fall through to the generic `other` deflection instead of a stub. "Where is
my order" is handled by a separate, working `order` route that doesn't
depend on store data, which is why it alone gives a sensible reply.
**This is a plugin-side gap, not a brain bug:** `class-brain.php`/`alice.js`
need to build and send a `store` object (name, currency, FAQ topic/answer
pairs, attribute hints) for the FAQ path to ever be reachable.

---

## 9. Out of scope

| Message | Path | Speech |
|---|---|---|
| `what is the weather today` | `llm:other` | "I'm here to help you shop — try asking me to find a product." |
| `tell me a joke` | `llm:other` | same |
| `who are you` | `llm:other` | same |

✅ **Full pass, and the earlier bug is fixed.** All three now get the same
fixed, deterministic deflection (`pipeline.py`'s `OFF_TOPIC_REPLY` — the LLM
only classifies, never writes the reply), including "tell me a joke", which
previously complied and told an actual joke. The wording differs slightly
from the scenario's suggested text but the behavior — consistently refusing
off-topic requests — now matches intent exactly.

---

## 10. Edge cases

| # | Message | Path | Speech | Verdict |
|---|---|---|---|---|
| a | `the cheapest thing you have` | `llm:product_empty` | "I couldn't find anything for \"the cheapest thing you have\"." | ❌ Fail — no "sort by price" capability exists in `catalog/index.py`'s search, and with no category/keyword to match, it returns zero results instead of either a sorted list or a clarifying question. |
| b | `something under 100 rupees` | `llm:product_empty` | "I couldn't find anything for \"something under 100 rupees\"." + "Browse all" button | ✅ Pass — exactly the expected graceful "couldn't find" behavior (previously this silently zeroed out with no explanation; now it's explicit). |
| c | `add it` (fresh session, nothing on screen) | `llm:other` | "I'm here to help you shop — try asking me to find a product." | ⚠️ Partial — doesn't crash, but the generic deflection replaces the more helpful expected "Which product would you like to add?" `parser.py` step 4 correctly detects "nothing on screen" and intentionally falls through (`return ParseResult("none")  # let pipeline ask`), but nothing in `pipeline.py` actually asks — it just goes to the generic LLM router, which classifies a bare "add it" as off-topic. |
| d | `yes` (no pending confirm, fresh session) | `llm:other` | "I'm here to help you shop — try asking me to find a product." | ✅ Pass — doesn't crash, degrades gracefully. |

---

## Summary

| Scenario | Last report | This run |
|---|---|---|
| 1. Basic search | 3/5 pass, 2 returned zero results | All 5 return correct results; now always costs 1 LLM call (architecture change, not a bug) |
| 2. Price refinement | Slot math correct, never re-searched | Re-search now works, but **wrong category resolved for the same query depending on session history** |
| 3. Reference resolution | Cart-add silently broken; bare ordinals unresolved | **Cart-add fixed** ✅; bare-ordinal-in-a-sentence ("show me the second one") still unresolved |
| 4. Vague query clarification | Not implemented | Still not implemented |
| 5. Cart + checkout | Cart-add no-op | **Full pass** ✅ |
| 6. Multi-word NL | 0/3 used an LLM call as expected | 3/3 use an LLM call ✅, but 2/3 misclassify category and return irrelevant products |
| 7. Follow-up context | Unverifiable | **Verified correct** ✅ |
| 8. FAQ stubs | No stub node; fabricated LLM answers | Real `faq` node exists but is **unreachable — plugin never sends store/FAQ data** |
| 9. Out of scope | Inconsistent, sometimes complied | **Full pass** ✅ — consistent, deterministic deflection |
| 10. Edge cases | a/b fail, c/d pass by accident | b now explicit ✅; a and c still don't match the expected behavior |

**What's fixed since last time:** cart confirm→add (the most important one),
the price-regex short-circuit, out-of-scope deflection consistency, and
empty-search messaging.

**What's still open, in priority order:**
1. **Category classification is inconsistent/inaccurate** for identical or
   closely related queries ("hiking boots" → `footwear` in one session,
   `outdoor-hiking` in another; "long distance running" and "waterproof
   trek" both → `sports-fitness` instead of `footwear`/`outdoor-hiking`).
   This is the one most likely to visibly confuse a real shopper.
2. **FAQ node is dead code until the plugin sends `store.faq`.** Needs
   `class-brain.php`/`alice.js` to build and include a `store` object.
3. Vague-query clarifying chips (§4) and "cheapest thing" sort-by-price (§10a)
   are still unimplemented features, not regressions.
4. "add it" with nothing on screen deflects instead of asking which product
   (§10c) — a small, low-risk gap in `pipeline.py`'s handling of the
   parser's intentional `"none"` fallthrough.
