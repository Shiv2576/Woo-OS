You should see HTTP/1.1 200 OK. Then open these in your browser:

http://127.0.0.1 for the storefront
http://127.0.0.1/assistant for Alice
http://127.0.0.1/wp-admin for the admin (admin / mercora-admin-123)

Here's each challenge at a low level, in the final hybrid setup: **the store (PHP plugin)** owns shoppers, cart and orders, and **the brain (Python service)** owns the catalog, AI and decisions.

## 1. Natural language search

```text
Shopper types → Store → Brain → (LLM only if needed) → Brain → Store → cards
```

1. **Store** receives the message and attaches the profile (session ID, viewed/added/rejected IDs, slots, cart), with personal data scrubbed.
2. **Brain: expected reply.** Is this an answer to a pending question ("9," "yes")? Resolve it, done.
3. **Brain: parser + lexicon.** Extract category, price, brand, size, colour from the text. Fully understood? Go to step 5.
4. **Brain: decide call** (only when the parser can't cope): compact snapshot in (~250–350 tokens), tiny JSON out: `{"r":"product","max":3000,"soft":["comfort"]}`. Grounding check drops any slot not found in the message or state.
5. **Brain: search.** Hard filters on the in-memory catalog, then embedding similarity for soft preferences, then ranking.
6. **Brain → store:** product IDs + reply template key.
7. **Store:** live stock and price check on those IDs, renders cards, saves `on_screen` to the session.

## 2. AI-powered recommendations

```text
Store profile → Brain: taste vector + co-purchase + rules → IDs → Store verifies → cards
```

1. **At sync time (background):** brain embeds every product once; store sends anonymous co-purchase pair counts nightly.
2. **Trigger:** the shopper asks ("show me something I'd like"), adds to cart, or opens a product page.
3. **Brain: taste vector.** Average embeddings of viewed and added products, minus rejected ones, then find the nearest products.
4. **Brain: collaborative layer.** Merge in "bought together" and "viewed together" candidates for the cart and page items.
5. **Brain: rules.** Remove out-of-stock, wrong size, over budget or already in cart. Apply store boosts.
6. **Brain: LLM (rare).** Only for vague needs ("gift for my dad") or "why" questions: top 6 candidates in, ranking out.
7. **Store:** verifies stock and price, renders "You might also like" or "Bought together" cards.

Usually **0 LLM tokens**.

## 3. Context-aware assistant

```text
Every turn: Store session → profile → Brain uses it → Store updates session
```

1. **Store** keeps the session in its database: products on screen, pending question and expected replies, slots, cart, viewed/rejected IDs, current page.
2. **Each turn**, the store sends the profile to the brain. The brain stores nothing about the shopper.
3. **Brain** uses the context to resolve references ("the Puma one" → `on_screen`), refine searches ("cheaper" → keep last filters, lower price), and personalize ranking and recommendations.
4. **Only a slice goes to the LLM:** the positions and 3–4 attributes it needs, never history.
5. **Brain** returns any session updates (new pending question, new slots). **Store** writes them.
6. **Cart and orders** are handled in the store: cart actions go straight to the shopper's real WooCommerce cart; order lookups happen locally, and the brain only sees `[ORDER_NO]`.

## 4. Real-time inventory (and fulfillment-aware help)

```text
Stock change → WooCommerce hook → Store → webhook → Brain index updated
Before showing / adding → Store live check
```

1. **WooCommerce hook** fires on a product save or stock change.
2. **Store** sends a small webhook to the brain: `{"id":"p_101","var":1019,"stock":3,"price":2799}`.
3. **Brain** updates its in-memory catalog instantly, with no embedding call for stock or price changes; only text changes are re-embedded.
4. **Before showing cards**, the store re-checks live stock and price, so nothing wrong is ever displayed.
5. **Before adding to cart**, the store checks again; if something sold out, it suggests the closest in-stock alternative.
6. **Fulfillment-aware:** the store reads WooCommerce shipping zones (or a shipping plugin) to show delivery estimates and prefer in-stock, ship-together items.

## If the brain is down

The store waits at most about 1 second, then switches to code-only mode: basic search on its own product table, cart actions, order lookups and filter buttons. The assistant slows down in capability, but never breaks.

That's the whole system: **the store holds the shopper, the brain holds the catalog and the intelligence, and the LLM sees only a tiny slice, only when code can't decide.**

Username Email Password
priya.sharma | priya.sharma@example.com | test1234
rohan.verma | rohan.verma@example.com | test1234
ananya.iyer | ananya.iyer@example.com | test1234
dev.mehta | dev.mehta@example.com | test1234
kavya.nair | kavya.nair@example.com | test1234

Phase A — fix correctness (this week)

Narrow the parser so it only matches complete patterns
Make the LLM the default extractor with store-aware prompts
Add FAQ, order and deflection nodes
Fix the cart confirm/add bug

Phase B — multi-store (next)

Store registry + registration endpoint
Store ID in every request
Postgres table for registry persistence

Phase C — optimise (after real usage data)

Parser rules for the top patterns from logs
Query result caching
Measure the real zero-LLM percentage
