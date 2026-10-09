# Woo-OS / Mercora: project context for agents

_Last updated: 2026-10-09. Read this before touching anything. It describes what the code **does
today**, not the aspirational design in `README.md` (see §12 for the difference)._

---

## 0. One-paragraph summary

**Mercora** is a WooCommerce store (INR, ~500 seeded products, 10 top-level departments) with an AI
shopping assistant called **Alice**. There are two halves:

- **The store**: WordPress + WooCommerce + our plugin `wordpress/mercora-plugin/` (PHP). It owns
  shoppers, the cart, orders, the chat session and the durable per-customer context.
- **The brain**: `brain/` (Python, FastAPI). It is **stateless**. It owns an in-memory copy of
  the catalog, the conversation logic and the recommendation logic, and makes LLM calls via OpenRouter.

They agree on one JSON contract: `contract/v1.schema.json`. The shopper's **browser** (`alice.js`)
sits in the middle. It calls the brain directly, executes cart actions against WooCommerce itself,
and saves the chat back to WordPress.

---

## 1. Repo map

```
Woo-OS/
├── brain/                         Python service ("Alice brain"), run with uv
│   ├── pyproject.toml             fastapi, httpx, pydantic, jsonschema, langgraph (unused so far); dev: pytest
│   └── app/
│       ├── main.py                FastAPI app; lifespan builds StoreAPI, OpenRouter, CatalogStore
│       ├── config.py              Settings from brain/.env (see §10)
│       ├── contract.py            Pydantic models + jsonschema validation against contract/v1.schema.json
│       ├── api/routes.py          GET /health, POST /search, POST /admin/reload, GET /debug/llm, POST /turn
│       ├── api/webhooks.py        POST /webhooks/woo (HMAC-signed product changes from the plugin)
│       ├── catalog/index.py       CatalogIndex: in-memory inverted index + scoring search
│       ├── catalog/loader.py      CatalogStore: initial load + incremental sync + 5-min full reload
│       ├── catalog/models.py      Product, Category, Variation dataclasses
│       ├── woo/store_api.py       async client for WooCommerce Store API (/wp-json/wc/store/v1)
│       ├── llm/openrouter.py      chat-completions client (JSON mode), returns tokens + latency
│       └── graph/
│           ├── pipeline.py        THE turn handler: gate → decide → validate → dispatch, then reco
│           ├── decide.py          the single LLM "decision" prompt + parser
│           ├── also_like.py       "You may also like": mission inference → complementary categories
│           └── shadow.py          shadow intent logging (comparison only, not on the answer path)
├── contract/
│   ├── v1.schema.json             THE wire contract (turn_request, turn_response, ctx, patch, reco…)
│   └── fixtures/{valid,pending}   sample payloads
├── wordpress/mercora-plugin/      the WP plugin (mounted live into the cluster, see §9)
│   ├── mercora.php                bootstrap: requires every class, calls ::init()
│   ├── config.local.php           UNCOMMITTED secrets/URLs (see §10)
│   ├── includes/
│   │   ├── class-assistant-route.php  /assistant page; localizes window.MERCORA for alice.js
│   │   ├── class-chat-state.php       chat session in the WooCommerce session + POST/DELETE /mercora/v1/chat
│   │   ├── class-context-store.php    durable per-tenant per-customer context in MariaDB (CAS writes)
│   │   ├── class-recommendations.php  GET /mercora/v1/recommendations + homepage tier resolution
│   │   ├── class-sync.php             pushes product/stock changes to the brain webhook
│   │   ├── class-brain.php            server-side turn handler: DEAD CODE, nothing calls Brain::turn
│   │   ├── class-launcher.php         floating "Ask Alice" button + [alice_button] shortcode
│   │   ├── class-catalog-controls.php extra shop sort options + category dropdown
│   │   ├── class-home.php             front-page CSS/fonts
│   │   ├── class-footer.php           replaces the theme footer with our own markup
│   │   ├── class-rest.php             GET /mercora/v1/health
│   │   └── mcp/                       MCP server at /wp-json/mercora/v1/mcp (see §8)
│   └── assets/
│       ├── alice.js                   the chat UI + client-side state owner
│       ├── recommendations.js/.css    homepage personalisation (progressive enhancement)
│       └── home.css, launcher.css …
├── infra/
│   ├── wordpress-values.yaml      Bitnami WordPress Helm values (k3d, MariaDB, plugin hostPath mount)
│   ├── ingress.yaml               Traefik ingress → mercora-wordpress:80
│   ├── setup-woo.sh               idempotent WooCommerce + plugin setup via wp-cli in the pod
│   └── pages/home.html, setup-home.sh   front page content (block HTML) + script that publishes it
├── db/seeds/seed.sh, seed-products.php   seeds 500 products in batches via wp eval-file
├── README.md                      design VISION (partly not implemented, see §12) + test users
├── manual_test_Brain.md, ASSISTANT_TEST_REPORT.md   manual test logs
└── context.md                     this file
```

Git: branch `main`, 3 commits (`ff515ad` WP frontend, `d1a1416` stateless brain, `d6de421` chat
session). **Most of the context-store / recommendations / MCP / also_like work is uncommitted.**

---

## 2. Runtime topology

```
 Shopper's browser
   ├─ /assistant page → alice.js
   │     ├── POST  {MERCORA_BRAIN_PUBLIC_URL}/turn ──────────────▶ Brain (FastAPI :8000, on the Mac)
   │     ├── /wp-json/wc/store/v1/cart/*  (add/remove/read cart) ─▶ WooCommerce
   │     └── POST/DELETE /wp-json/mercora/v1/chat ───────────────▶ Chat_State → WC session
   │                                                                 └─▶ Context_Store → MariaDB
   └─ / (front page) → recommendations.js
         └── GET /wp-json/mercora/v1/recommendations ────────────▶ Recommendations (reads MariaDB)

 WordPress (k3d pod)  ── class-sync.php: HMAC POST {MERCORA_ALICE_SYNC_URL}/webhooks/woo ──▶ Brain
 Brain                ── httpx: Store API /wp-json/wc/store/v1/products … (catalog load) ──▶ WordPress
 Brain                ── OpenRouter chat/completions (JSON mode) ─────────────────────────▶ LLM
```

- Store URLs: `http://127.0.0.1` (storefront), `/assistant` (Alice), `/wp-admin` (admin / mercora-admin-123).
- Test shoppers (password `test1234`): priya.sharma, rohan.verma, ananya.iyer, dev.mehta, kavya.nair.
- Two brain URLs exist **on purpose**: `MERCORA_BRAIN_URL` (`http://host.k3d.internal:8000`, for PHP
  inside the cluster) and `MERCORA_BRAIN_PUBLIC_URL` (`http://127.0.0.1:8000`, for browser JS).
  Never collapse them.

---

## 3. The contract (`contract/v1.schema.json` ⇄ `brain/app/contract.py`)

Every turn request and response is validated against the schema **on both sides** of the brain
(`parse_request` in, `validate_response` out). `ctx`, `patch`, `reco` and `reco_tier` are
`additionalProperties: false`. **Adding a field means editing three places**: the schema,
`contract.py`, and `freshCtx()` / `saveChat()` in `alice.js` (plus `Chat_State::clean` if it
must persist). Otherwise turns fail with a 422.

**TurnRequest**
```jsonc
{ "v": 1, "turn": 3, "session": "js_abc", "catalog_version": 0,
  "msg": "vegan snacks under 500",          // 1..1000 chars
  "placeholders": [],
  "store": { "name": "Mercora", "currency": "INR", "attributes": [...], "faq": [...] },  // name REQUIRED
  "ctx": {
    "on_screen": [ids], "viewed": [ids], "added": [ids], "rejected": [ids],
    "slots": { "cat": null, "max_price": null, "min_price": null, ... },
    "pending": null | {type: confirm|choose|option|undo, ...},   // a question Alice asked
    "focus": null | {product_id, chosen: {...}},                // product whose options are being chosen
    "cart": [...], "page": "/assistant/", "past_purchases": [ids],
    "category_history": ["footwear-hiking-boots", "snacks-candy-chocolates"]   // newest first, max 3
  } }
```
Required in ctx: `on_screen, slots, pending, cart, past_purchases`.

**TurnResponse**: `{turn, patch, action, display, meta, reco}`
- `patch`: only the fields this turn changed. A field present with `null` **clears** it
  (`_patch_to_wire` sends explicitly-set nulls; do not "fix" that).
- `action`: `cart.add {product_id, variation_id, qty}` | `cart.remove` | `navigate {url}` | null.
- `display`: `{template, speech, items, buttons, table}`. Text comes from templates, **never from the LLM**.
- `meta`: `{path, llm_calls, tokens_in, tokens_out, ms}`. `path` is e.g. `gate:yes`, `decide:search`.
- `reco`: `null` or `{v:1, category_history, tiers:{tier_1, tier_2, tier_3, also_like?}}`.
  A tier is `{category, product_ids (≤12), categories? (≤4, also_like only), title? (≤60)}`.

---

## 4. One chat turn, end to end

1. **Browser** (`alice.js submitMessage`): `turnNum++`, builds the request from its in-memory `ctx`,
   `POST ${BRAIN}/turn` with header `Authorization: Bearer brainToken` (see §11: not verified).
2. **Brain** `routes.turn` → `parse_request` (schema) → `pipeline.handle_turn`.
3. `_turn` runs four stages:
   1. **GATE** (`_gate`): exact, model-free answers. Yes/no to a `pending.confirm`, picking from a
      `pending.choose`, answering a `pending.option`, "undo", option words for the focused product
      ("brown and 8" when every word maps to one of its options), the "Checkout" chip.
   2. **DECIDE** (`decide.py`): **one** LLM call, JSON mode, `max_tokens=220`, temp 0. The prompt
      includes the store's categories (≤40), attributes, FAQ topics, numbered on-screen products, cart, focus,
      pending, active filters and the message. It returns a `Decision`: `intent ∈ {search, cart_add,
      cart_remove, checkout, faq, order_status, clarify, other}`, `target {source: screen|cart|focus,
      index}`, `quantity`, `options`, `filters {keywords, category_text, min/max_price,
      soft_preference, related[]}`, `faq_topic`, `candidates`, `clarify_for`. `parse_decision` never
      raises: junk becomes `intent=other`.
   3. **VALIDATE**: every reference is checked against real state (the index is on screen, the option
      exists, the FAQ topic exists, the category exists).
   4. **DISPATCH** (`_dispatch`): a plain switch per intent. Search runs `catalog.index.search`. Adds
      produce a `cart.add` action plus an `undo` pending and the chips `["Undo","Checkout"]`. If the LLM
      call throws, `_fallback_search` handles the turn.
4. **Recommendations** are attached **after** the turn, in one place, so all ~25 response paths get them:
   `resp.reco = _reco(...)`, then `_attach_also_like(...)` (see §6).
5. `validate_response` (schema), then return.
6. **Browser**: checks `resp.turn === turnNum`, `applyPatch` (lists merged and capped at 20,
   `category_history` capped at 3), `lastReco = resp.reco`, then `executeAction`:
   - `cart.add` / `cart.remove` go through the **WooCommerce Store API** from the browser (rotating
     `Nonce` header, retried once on 403; remove looks up the cart item key first).
   - `navigate` sets `location`.
   - A failed cart write produces an honest error message and clears `pending`/`focus`.
   - `ctx.cart` is **always re-read from WooCommerce**, never taken from the brain.
7. `saveChat()` (debounced 400 ms, `keepalive`) → `POST /mercora/v1/chat` with
   `{v, turn, ctx (no cart), reco: lastReco, msgs}` and the `X-WP-Nonce` header. Without the nonce a
   logged-in user is treated as a guest and the chat lands in the wrong session.

The model **never** writes shopper-visible text and never touches the cart. Paying always needs the
shopper's own click on WooCommerce checkout.

---

## 5. Context management: three layers

| Layer | Code | Storage | Lifetime | Contents | Who |
|---|---|---|---|---|---|
| Working ctx | `alice.js` `ctx` | JS memory | page | full ctx + live cart | all |
| Chat session | `Chat_State` | WooCommerce session, ONE JSON string, ≤20 KB | ~48 h idle; **wiped at checkout** | transcript (≤24 msgs, text ≤600, product **ids only**, last buttons), ctx minus cart, reco | guests (cookie) + users |
| Durable context | `Context_Store` | MariaDB `{base_prefix}mercora_context`, `UNIQUE(tenant_id, user_key)` | 1 year untouched (daily GC cron `mercora_context_gc`) | `category_history` (3), `slots`, `rejected` (40), `reco`, `reco_at` | **logged-in only** |

**Why one JSON string in the session:** WooCommerce loads the session on every request, and PHP
arrays cannot tell `{}` from `[]` (the schema can).

**Write path:** `Chat_State::rest_save` → `clean()` → session → `Context_Store::record_turn(null,
history, slots, rejected, reco)`. The user id comes from the **authenticated request**, never from
the body, so nobody can write another customer's row. Guests are a no-op (`user_key()` returns null).

**CAS writes (`Context_Store::mutate`)**, with no transactions and no `SELECT … FOR UPDATE`:
1. `INSERT IGNORE` materialises the row (the UNIQUE key makes racing inserts no-ops).
2. Read `rev` with the data.
3. `UPDATE … WHERE rev = <read rev>`. Only one racing writer matches.
4. The loser backs off, re-reads and **re-runs the callback on the winner's data**. Up to 8 tries.

All mutations in `record_turn` are append-style (history replaced by the brain's merged list, slots
merged, rejected unioned), which is what makes retry safe. Measured on MariaDB 11 with 12 concurrent
writers: CAS kept 12/12 writes (worst case 5 attempts), while a plain read-modify-write kept 4/12.
**Do not reintroduce `$wpdb->replace` read-modify-write.**

`context_data` is **LONGTEXT, not JSON**: MariaDB's JSON type is a LONGTEXT alias with a CHECK, and
`dbDelta` cannot parse it (it would re-add the column on every upgrade check). Validation is in PHP
(`clean_history`, `clean_ids`, `clean_slots`, `clean_reco`). `encode_capped` keeps rows ≤16 KB by
dropping `rejected` first, then `reco`.

**Why a table and not user_meta:** the tenant id travels **with the row**, so context stays
identifiable when installs share a DB (multisite, or a staging clone next to prod). `base_prefix` is
deliberate: one table per network, partitioned by `tenant_id`.

**Read path / warm start:** `Chat_State::get()` falls back to `seed()` when there is no session (or an
old one without history). `seed()` rebuilds ctx from MariaDB with **no transcript** but with history,
slots and rejected items. `restoreChat()` in `alice.js` adopts `saved.ctx` **even with zero messages**:
that is how a seeded context reaches the next turn. Don't add an early return there.

**Resets:**
- "New chat" (`DELETE /chat`) clears the session; the JS **keeps `category_history`**. Taste survives,
  the conversation doesn't.
- Checkout (`woocommerce_checkout_order_processed`, store-api variant, `woocommerce_thankyou`) clears
  the session but **not** `Context_Store`, because a customer who just bought is the best person to
  recommend to.

**Brain side of history:** `_push_category(history, this_turn_cat)` puts the category first, removes
duplicates and keeps 3. The category comes from `patch.slots.cat`, else `ctx.slots.cat`. The merged list
is returned in `patch.category_history`, and WordPress persists exactly that.

---

## 6. Recommendation system

### 6.1 Produced by the brain (every turn, `pipeline._reco` + `_attach_also_like`)
- **tier_1..3**: one per `category_history` slot (tier_1 is the newest). Each is
  `catalog.index.search(category, current price slots, in_stock_only, exclude cart + rejected + earlier tiers, limit 8)`.
  A product appears in at most one tier. If no tier has products, `reco = null` and WordPress keeps
  the previous payload.
- **also_like** (`graph/also_like.py`): "You may also like", meaning **complementary** categories, not more
  of the same. Example: chocolate + hiking boots suggests a trekking & camping trip, so tents, backpacks
  and water bottles.
  - One LLM call (`max_tokens=120`, JSON) gets the recent categories plus the store's real category list
    (`slug: name`, ≤80) and returns `{"mission": "...", "categories": [slugs]}`.
  - `parse_mission` keeps only real slugs with count > 0, **excluding** the history slugs, their parents
    and their children. Max 4.
  - Cached in process by the **sorted set** of history (`_cache`, max 512). The same set means zero LLM cost.
  - Budget: `asyncio.wait_for(shield(task), 2.5s)`. On timeout or error it falls back to **sibling
    categories** (same parent) this turn, and the shielded call still finishes and fills the cache for
    the next turn. `_inflight` de-dupes concurrent calls.
  - Products: alternate across the chosen categories (no category filling the row), excluding cart,
    rejected and everything already in tiers 1–3. 8 items.
  - When the LLM was called, `meta.llm_calls/tokens_*` are incremented.
- The catalog has **no stove/burner category** (closest: `outdoor-hiking-tents-camping`,
  `-backpacks`, `-water-bottles`, `clothing-jackets`), so such items can't be recommended until they exist.

### 6.2 Transport and storage
brain → browser (`lastReco`) → `POST /chat` → `Context_Store::clean_reco` (sanitised slugs, ≤12 ids
per tier, `also_like` kept only with categories or ids, title `sanitize_text_field`, ≤60) → MariaDB
with `reco_at = now`.

### 6.3 Rendering (`Recommendations::tiers()` + `recommendations.js`)
The homepage HTML (`infra/pages/home.html`, published by `infra/pages/setup-home.sh`) is **identical for
everyone and cacheable**:

| Section | `data-mercora-tier` | Default (guests / no history) |
|---|---|---|
| Recommended for you | `tier_1` | `[products visibility="featured"]` |
| More for you | `tier_2` | section `hidden`, no default |
| More for you | `tier_3` | section `hidden`, no default |
| You may also like these… | `also_like` | `[products best_selling="true"]` |

Each section has `[data-mercora-head]` (h2 + p), `[data-mercora-slot] hidden` and optionally
`[data-mercora-default]`.

`recommendations.js` is enqueued **only on the front page for logged-in users**. It calls
`GET /mercora/v1/recommendations` once (`no-store, private`, logged-in only, always the current user).
For each returned tier with products, it fills the slot, swaps the heading, unhides the slot and hides
the default. Nothing returned means the page stays exactly as rendered: no empty states, no layout shift.
`hidden` is pinned with `display:none !important` in `recommendations.css` because themes set `display`.

`Recommendations::tiers($user_id)`:
1. Loads the context. `fresh = reco_at > now − 30 days` (`RECO_TTL`). **A stale payload keeps its
   categories but loses its ids.**
2. Validates **all** ids from all tiers (including also_like) in **one** `get_posts` query: published,
   not `exclude-from-catalog`, not `outofstock`.
3. Keeps the brain's order, drops dead ids, de-dupes across tiers (the higher tier wins).
4. Backfills short tiers from their category by `total_sales`. **Only tier_1** falls back further to
   store bestsellers. `also_like` backfills by alternating across its categories, with no bestseller
   fallback (the HTML default already is Bestsellers).
5. Builds cards with **live** name, permalink, `price_text()` (strips the screen-reader span and the `<del>`
   sale price, decodes entities) and image.
6. Labels: tier_1 "More {Category} for you", tier_2/3 "Back to {Category}", also_like "You may also
   like" / "Picked for your {mission}."

**Rule:** never print personalised recommendations into the cached HTML. Personalisation happens after load.

---

## 7. Catalog sync (keeping the brain's index fresh)

- Brain startup: `CatalogStore.reload()` pulls all products, categories and variations via the Store API into
  `CatalogIndex` (inverted index `cat:/tag:/attr:` plus token → ids, with name tokens weighted higher; popularity
  comes from load order).
- `class-sync.php` hooks `woocommerce_new/update_product(_variation)`, `*_set_stock`,
  `*_set_stock_status`, deletes, etc. Changes are batched per request and sent as an HMAC-SHA256 signed
  POST (`X-Mercora-Signature`, secret `MERCORA_ALICE_SYNC_SECRET` = brain `webhook_secret`) to
  `/webhooks/woo`. Plain WooCommerce webhooks are not used because order stock reductions don't fire
  `product.updated` reliably.
- The brain verifies the HMAC, replies immediately and applies changes in the background: stock patches in
  memory, detail refetch batched every 250 ms (full reload above 50 changes), plus a **full reload every
  `catalog_refresh_s` (300 s)** as a safety net.
- This lag is why WordPress always re-validates ids before display, and why `pipeline._fresh()`
  re-fetches a product before acting on its options.

---

## 8. Other plugin features

- **MCP server** (`includes/mcp/`) at `/wp-json/mercora/v1/mcp`: stateless JSON-RPC (Streamable HTTP),
  supporting both the modern (`2026-07-28`, no handshake) and legacy (`2025-11-25` and older,
  initialize) protocol eras. Read-only tools: `search_products`, `get_product`, `list_categories`,
  `get_store_info`. It never calls a model; external hosts (Claude, ChatGPT…) do. Guards: optional
  `MERCORA_MCP_TOKEN`, origin allowlist, a rate limit filter (120/min/IP), `MERCORA_MCP_DISABLED`,
  `MERCORA_MCP_LOG`. Hooks: `mercora_mcp_register_tools`, `mercora_mcp_call`. **Not part of Alice's loop.**
- `MERCORA_UCP_*` constants exist for a planned Universal Commerce Protocol integration.
- Launcher (floating "Ask Alice" button), shop sort and category controls, custom footer, homepage CSS.

---

## 9. Infra and how to run

- **Cluster:** k3d, namespace `mercora`, Bitnami WordPress chart (`infra/wordpress-values.yaml`) +
  MariaDB, Traefik ingress (`infra/ingress.yaml`). Plugin source is a **hostPath mount**
  (`/mnt/mercora-plugin`) symlinked into `wp-content/plugins/mercora-plugin` by `setup-woo.sh`, so
  **PHP edits on the Mac are live immediately**, with no deploy.
- `infra/setup-woo.sh`: idempotent setup (permalinks, WooCommerce, INR/IN, pages, plugin activate).
- `db/seeds/seed.sh [count] [reset]`: seeds products in batches.
- `infra/pages/setup-home.sh`: publishes `home.html` as the static front page. **Re-run it after
  editing `home.html`**, otherwise the live page is stale.
- wp-cli: `kubectl -n mercora exec deploy/mercora-wordpress -c wordpress -- wp …`
- PHP lint (no local PHP): `kubectl -n mercora exec -i deploy/mercora-wordpress -c wordpress -- php -l < file.php`
- **Brain:** `cd brain && uv run uvicorn app.main:app --port 8000` (restart after Python changes).
  Health: `GET /health` (catalog stats, sync state, `llm_configured`).
- Imports for ad-hoc scripts: `cd brain && PYTHONPATH=. uv run python script.py`.
- No automated test suite exists yet (pytest is only a dev dependency). Testing so far is manual
  (`manual_test_Brain.md`, `ASSISTANT_TEST_REPORT.md`) plus scratch scripts.

---

## 10. Configuration

**`wordpress/mercora-plugin/config.local.php`** (uncommitted; `define()` constants):
- `MERCORA_BRAIN_PUBLIC_URL`: brain URL the **browser** uses (`http://127.0.0.1:8000`).
- `MERCORA_BRAIN_URL`: brain URL from inside the cluster (`http://host.k3d.internal:8000`).
- `MERCORA_BRAIN_TOKEN`: sent by alice.js as a Bearer token (not verified by the brain, see §11).
- `MERCORA_ALICE_SYNC_URL`, `MERCORA_ALICE_SYNC_SECRET`: webhook target + HMAC secret.
- `MERCORA_TENANT_ID`: must match the brain's tenant id. Unset, it falls back to
  `site_<sha1(host+blog id)>`. Filter: `mercora_tenant_id`.
- `MERCORA_MCP_TOKEN`, `MERCORA_MCP_LOG`, `MERCORA_MCP_DISABLED`, `MERCORA_UCP_*`.

**`brain/.env`** (`app/config.py`): `WOO_BASE_URL` (Store API = `{WOO_BASE_URL}/wp-json/wc/store/v1`),
`CATALOG_REFRESH_S=300`, `WEBHOOK_SECRET`, `CORS_ORIGINS` (comma list), `DEBUG`, `OPENROUTER_API_KEY`,
`OPENROUTER_BASE_URL`, `LLM_MODEL`, `LLM_TIMEOUT_S=8`, `JEV_MODEL` / `OPENROUTER_DECISIONS_URL` (shadow).

`window.MERCORA` (localized for alice.js by `class-assistant-route.php`): `aliceApi`, `brainToken`,
`store`, `chat` (saved state), `chatApi`, `storeApi`, `restNonce`, `catalogVersion`.
`window.MERCORA_RECO` (homepage): `endpoint`, `nonce`.

---

## 11. Known issues and risks (verified in code, 2026-10-09)

1. **The brain does not authenticate `/turn`.** alice.js sends `Authorization: Bearer <brainToken>`,
   but nothing in `brain/app` checks it. CORS only stops browsers, so anyone who can reach :8000 can spend
   LLM budget. (An earlier note here claimed it was enforced; it is not.)
2. **The `reco` payload travels through the browser** (brain → JS → `/chat` → MariaDB), so a logged-in
   user can forge their **own** homepage recommendations. This is mitigated by `clean_reco` plus live-id
   validation. Hardening: the brain POSTs reco server-to-server with a token.
3. **`decide.py` `_JSON_SHAPE` is malformed**: the `soft_preference` line is duplicated and
   `"filters"` is closed three times, and `_RULES` repeats the `keywords` rule. The model copes, but it
   wastes tokens and invites drift. Clean it up so `filters` lists `related` once.
4. **Only chat feeds `category_history`.** Category page views, product views and storefront search do
   not. The proposed fix is a `template_redirect` hook + `Context_Store::push_category()` (not built).
5. **Guests get no durable context and no personalised homepage** (by design so far).
6. `class-brain.php` is dead code from the earlier server-side design. Delete it or wire it up.
7. The also_like PHP rendering path (`Recommendations::also_like`) was lint-checked and its payload
   cleaning tested in the pod, but not run end-to-end against a real stored row (writes to the
   cluster DB were blocked in that session). The brain side was tested with a fake catalog and LLM.
8. After a section is personalised, the "View all →" link still points at the generic default URL.

### Past bug: "Alice turn error" on every turn (fixed 2026-10-08)
`config.local.php` had a malformed `define()` (the ngrok URL was passed as the constant **name**), so
`MERCORA_BRAIN_PUBLIC_URL` was undefined. The route then fell back to the cluster-internal
`MERCORA_BRAIN_URL`, which the browser can't resolve, so every `fetch` failed. Fix:
`define("MERCORA_BRAIN_PUBLIC_URL", "http://127.0.0.1:8000");`. Hardening: `aliceApi` no longer falls
back to `MERCORA_BRAIN_URL`. It is the public URL or `""`, and alice.js then defaults to
`http://127.0.0.1:8000`.

### Gotchas
- `store.name` is schema-required: `store: {}` returns a 422.
- If the storefront is served over **https** (ngrok), add that origin to `CORS_ORIGINS` and serve the
  brain over https too (mixed content is blocked).
- `ctx.cart` is never taken from the brain patch.
- New ctx fields need the schema + `contract.py` + `alice.js` (and `Chat_State::clean` to persist).
- `reco_tier.category` is **required** (nullable), so don't dump reco with `exclude_none=True`.
- Asset versions are file mtimes (`mercora_asset_ver`), so a normal refresh picks up JS/CSS edits.

---

## 12. Vision (README) vs reality

`README.md` describes a fuller design. **Not implemented yet:** a rule parser/lexicon before the LLM
(today GATE handles only exact replies, and everything else is one LLM decide call), embeddings / taste
vectors, co-purchase ("bought together") data, a ~1 s brain-down fallback in the store, shipping-aware
help, LangGraph orchestration (the dependency is present but unused), a Postgres store registry /
multi-store registration, voice, LangSmith tracing, Ragas evals, and UCP checkout.
**Implemented:** the stateless brain with the schema contract, the in-memory catalog with webhook
sync, gate → decide → validate → dispatch, cart via the Store API, the session plus durable multi-tenant
context, tiered + also_like homepage recommendations, and the read-only MCP server.

---

## 13. Change log (recent)
- 2026-10-09: `also_like` tier ("You may also like", mission-based complementary categories). Touched
  `brain/app/graph/also_like.py` (new), `pipeline.py`, `contract.py`, `contract/v1.schema.json`,
  `class-context-store.php` (`clean_reco`), `class-recommendations.php`, `infra/pages/home.html`.
- 2026-10-09: multi-tenant durable context (`Context_Store`, CAS), tiered homepage recommendations.
- 2026-10-08: fixed the "Alice turn error" (config define typo).
