// wordpress/mercora-plugin/assets/alice.js
(() => {
  const cfg = window.MERCORA || {};

  // ── DOM refs ──────────────────────────────────────────────────────────────
  const scroller = document.getElementById('alice-scroll');
  const thread   = document.getElementById('alice-thread');
  const status   = document.getElementById('alice-status');
  const form     = document.getElementById('alice-composer');
  const input    = document.getElementById('alice-input');
  const send     = document.getElementById('alice-send');
  const newBtn   = document.getElementById('alice-new');

  const GREETING = "Hi, I'm Alice. Ask me to find products, compare options, or manage your cart.";

  // ── Session ───────────────────────────────────────────────────────────────
  let sessionId = sessionStorage.getItem('alice_session');
  if (!sessionId) {
    sessionId = 'js_' + Math.random().toString(36).slice(2);
    sessionStorage.setItem('alice_session', sessionId);
  }

  // The context we carry across turns and send with every request.
  // The brain is stateless — we own this.
  const freshCtx = () => ({
    on_screen:      [],
    viewed:         [],
    added:          [],
    rejected:       [],
    slots:          { cat: null, max_price: null, min_price: null },
    pending:        null,
    focus:          null,
    cart:           [],
    page:           window.location.pathname,
    past_purchases: [],
  });
  let ctx = freshCtx();
  let turnNum = 0;

  const BRAIN = (cfg.aliceApi || 'http://127.0.0.1:8000').replace(/\/$/, '');

  // ── Transcript (what the shopper sees), saved in the WooCommerce session ──
  // Entries: { r: 'u'|'a', t: text, ids?: [productIds], b?: [button labels] }.
  // Product cards are stored as IDS only and re-read live when restored, so a
  // restored card always shows the current price and stock.
  const MAX_MSGS = 24;
  let transcript = [];
  let restoring = false;

  function remember(entry) {
    if (restoring) return;
    transcript.push(entry);
    if (transcript.length > MAX_MSGS) transcript.shift();
  }

  // Cards and buttons belong to the assistant message they were shown with.
  function attachToLast(key, value) {
    if (restoring) return;
    const last = transcript[transcript.length - 1];
    if (last && last.r === 'a') last[key] = value;
    else remember({ r: 'a', t: '', [key]: value });
  }

  // ── Helpers ───────────────────────────────────────────────────────────────
  const scrollToBottom = () => { scroller.scrollTop = scroller.scrollHeight; };

  function addMessage(text, who) {
    const el = document.createElement('div');
    el.className = `alice__msg alice__msg--${who}`;
    el.textContent = text;
    thread.appendChild(el);
    scrollToBottom();
    remember({ r: who === 'user' ? 'u' : 'a', t: String(text).slice(0, 600) });
    return el;
  }

  function showGreeting() {
    const el = document.createElement('div');
    el.className = 'alice__msg alice__msg--assistant';
    el.textContent = GREETING;
    thread.appendChild(el);
  }

  function setStatus(text, cls = '') {
    status.textContent = text;
    status.className = 'alice__status' + (cls ? ' ' + cls : '');
  }

  function escHtml(str) {
    return String(str).replace(/[&<>"']/g, c =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function decodeHtml(str) {
    const t = document.createElement('textarea');
    t.innerHTML = String(str || '');
    return t.value;
  }

  // ── Saving the chat to the WooCommerce session ────────────────────────────
  let saveTimer = null;

  function saveChat(now = false) {
    clearTimeout(saveTimer);
    const run = () => {
      if (!cfg.chatApi) return;
      const body = JSON.stringify({
        v: 1,
        turn: turnNum,
        ctx: {
          on_screen: ctx.on_screen, viewed: ctx.viewed, added: ctx.added, rejected: ctx.rejected,
          slots: ctx.slots, pending: ctx.pending, focus: ctx.focus,        // never the cart
        },
        msgs: transcript,
      });
      // The REST nonce matters for logged-in shoppers: without it WordPress treats the
      // request as a guest's and the chat would be saved to the wrong session.
      fetch(cfg.chatApi, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,                      // still delivered if the page is unloading
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce || '' },
        body,
      }).catch(() => {});
    };
    if (now) run(); else saveTimer = setTimeout(run, 400);
  }

  // ── WooCommerce Store API ─────────────────────────────────────────────────
  // The Store API issues its own rotating nonce in a 'Nonce' response header.
  // Echo it back on the next call; update it from every response.
  let storeNonce = null;

  async function storeApiFetch(path, options = {}, retried = false) {
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    if (storeNonce) headers['Nonce'] = storeNonce;
    const r = await fetch(cfg.storeApi + path, { ...options, credentials: 'same-origin', headers });
    const fresh = r.headers.get('Nonce');
    if (fresh) storeNonce = fresh;

    // A stale or missing nonce is the most common silent failure: re-prime it and retry once.
    if (r.status === 403 && !retried) {
      await storeApiFetch('/cart', {}, true);
      return storeApiFetch(path, options, true);
    }
    return r;
  }

  // The real reason a Store API call failed, in WooCommerce's own words.
  async function storeError(r) {
    try {
      const j = await r.json();
      const text = String((j && j.message) || '').replace(/<[^>]+>/g, '').trim();
      console.warn('Store API error', r.status, j);
      return text || `HTTP ${r.status}`;
    } catch (_) {
      return `HTTP ${r.status}`;
    }
  }

  // Keep ctx.cart in step with the REAL WooCommerce cart (never from the brain).
  // Also primes the Store API nonce on first call.
  async function refreshCart() {
    try {
      const r = await storeApiFetch('/cart');
      if (!r.ok) return;
      const cart = await r.json();
      ctx.cart = (cart.items || []).map(i => ({
        product_id: i.id, name: i.name, qty: i.quantity, key: i.key,
      }));
    } catch (_) { /* leave ctx.cart as is */ }
  }

  // Store API product -> the card shape the brain sends (used when restoring a chat).
  function cardFromStore(p) {
    const pr = p.prices || {};
    const mu = Number(pr.currency_minor_unit ?? 2);
    const rupees = v => Math.round(Number(v || 0) / Math.pow(10, mu));
    const img = (p.images && p.images[0]) ? (p.images[0].thumbnail || p.images[0].src) : null;
    return {
      id: p.id,
      name: decodeHtml(p.name),
      url: p.permalink,
      price: rupees(pr.price),
      regular_price: rupees(pr.regular_price),
      on_sale: !!p.on_sale,
      in_stock: !!p.is_in_stock,
      low_stock: p.low_stock_remaining ?? null,
      image: img,
      type: p.type,
    };
  }

  async function fetchCards(ids) {
    if (!ids.length) return {};
    try {
      const r = await storeApiFetch(`/products?include=${ids.join(',')}&per_page=100`);
      if (!r.ok) return {};
      const out = {};
      (await r.json()).forEach(p => { out[p.id] = cardFromStore(p); });
      return out;
    } catch (_) {
      return {};
    }
  }

  // ── Product cards ─────────────────────────────────────────────────────────
  function renderCards(items) {
    if (!items || !items.length) return;
    attachToLast('ids', items.map(p => p.id).slice(0, 8));

    const wrap = document.createElement('div');
    wrap.className = 'alice__cards';

    items.forEach((p, i) => {
      const card = document.createElement('div');
      card.className = 'alice__card';
      card.dataset.productId = p.id;

      const stockNote = p.in_stock
        ? (p.low_stock ? `Only ${p.low_stock} left` : 'In stock')
        : 'Out of stock';
      const saleTag = p.on_sale ? `<span class="alice__card-sale">Sale</span>` : '';

      // Variable products (sizes/colours) can't be added without choosing options.
      const addControl = p.type === 'variable'
        ? `<a class="alice__card-btn alice__card-btn--add" href="${escHtml(p.url)}" target="_blank">Choose options</a>`
        : `<button class="alice__card-btn alice__card-btn--add" data-idx="${i}" ${p.in_stock ? '' : 'disabled'}>Add to cart</button>`;

      card.innerHTML = `
        ${p.image ? `<img class="alice__card-img" src="${p.image}" alt="${escHtml(p.name)}" loading="lazy">` : '<div class="alice__card-img alice__card-img--empty"></div>'}
        <div class="alice__card-body">
          <p class="alice__card-name">${escHtml(p.name)}</p>
          <p class="alice__card-price">₹${Number(p.price).toLocaleString('en-IN')} ${saleTag}</p>
          <p class="alice__card-stock ${p.in_stock ? '' : 'alice__card-stock--out'}">${stockNote}</p>
        </div>
        <div class="alice__card-actions">
          ${addControl}
          <a class="alice__card-btn alice__card-btn--view" href="${escHtml(p.url)}" target="_blank">View</a>
        </div>`;

      wrap.appendChild(card);
    });

    thread.appendChild(wrap);
    scrollToBottom();

    wrap.querySelectorAll('button.alice__card-btn--add').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = parseInt(btn.dataset.idx, 10);
        addToCart(items[idx], btn);
      });
    });
  }

  // ── Chips (buttons the brain offers) ──────────────────────────────────────
  function renderButtons(buttons) {
    if (!buttons || !buttons.length) return;
    attachToLast('b', buttons.slice(0, 6));

    const row = document.createElement('div');
    row.className = 'alice__chips';
    buttons.forEach(label => {
      const chip = document.createElement('button');
      chip.className = 'alice__chip';
      chip.textContent = label;
      chip.addEventListener('click', () => {
        row.remove();
        submitMessage(label);
      });
      row.appendChild(chip);
    });
    thread.appendChild(row);
    scrollToBottom();
  }

  // ── Cart: shopper clicks "Add to cart" on a card ──────────────────────────
  async function addToCart(product, btn) {
    btn.disabled = true;
    btn.textContent = 'Adding…';
    try {
      const r = await storeApiFetch('/cart/add-item', {
        method: 'POST',
        body: JSON.stringify({ id: product.id, quantity: 1 }),
      });
      if (!r.ok) throw new Error(await storeError(r));
      btn.textContent = 'Added ✓';
      btn.classList.add('alice__card-btn--added');
      ctx.added = [...ctx.added, product.id].slice(-20);
      addMessage(`Added ${product.name} to your cart.`, 'assistant');
      await refreshCart();
    } catch (e) {
      btn.textContent = 'Failed';
      btn.disabled = false;
      addMessage(`I couldn't add that to your cart: ${e.message}`, 'assistant');
    }
    saveChat();
  }

  // ── Execute an action the brain decided on ────────────────────────────────
  // Returns { ok, error }. `error` is WooCommerce's own message, so a failed add
  // is never mislabelled as "sold out" when the real cause is something else.
  async function executeAction(action) {
    if (!action) return { ok: true };

    if (action.type === 'navigate') {
      saveChat(true);                                   // keep the chat across the page change
      window.location.href = action.url;
      return { ok: true };
    }

    if (action.type === 'cart.add') {
      try {
        // For a variation, the Store API takes the VARIATION id as the item id.
        const r = await storeApiFetch('/cart/add-item', {
          method: 'POST',
          body: JSON.stringify({
            id: action.variation_id || action.product_id,
            quantity: action.qty || 1,
          }),
        });
        return r.ok ? { ok: true } : { ok: false, error: await storeError(r) };
      } catch (e) {
        return { ok: false, error: 'the store could not be reached' };
      }
    }

    if (action.type === 'cart.remove') {
      try {
        // The Store API removes by cart item KEY, not product id: look it up first.
        const r = await storeApiFetch('/cart');
        if (!r.ok) return { ok: false, error: await storeError(r) };
        const cart = await r.json();
        // Cart lines for variations carry the variation id.
        const wanted = action.variation_id || action.product_id;
        const item = (cart.items || []).find(i => i.id === wanted);
        if (!item) return { ok: true };               // already gone
        const rr = await storeApiFetch('/cart/remove-item', {
          method: 'POST',
          body: JSON.stringify({ key: item.key }),
        });
        return rr.ok ? { ok: true } : { ok: false, error: await storeError(rr) };
      } catch (e) {
        return { ok: false, error: 'the store could not be reached' };
      }
    }

    return { ok: true };
  }

  // ── Apply the brain's patch to local context ──────────────────────────────
  // A field present with value null means "clear it" (pending, focus, slots).
  function applyPatch(patch) {
    if (!patch) return;
    if (patch.on_screen !== undefined) ctx.on_screen = patch.on_screen;
    if (patch.pending   !== undefined) ctx.pending   = patch.pending;
    if (patch.focus     !== undefined) ctx.focus     = patch.focus;
    if (patch.page      !== undefined) ctx.page      = patch.page;
    if (patch.slots)     ctx.slots    = { ...ctx.slots, ...patch.slots };
    if (patch.viewed)    ctx.viewed   = [...ctx.viewed,   ...patch.viewed].slice(-20);
    if (patch.added)     ctx.added    = [...ctx.added,    ...patch.added].slice(-20);
    if (patch.rejected)  ctx.rejected = [...ctx.rejected, ...patch.rejected].slice(-20);
    // ctx.cart is NEVER taken from the brain — it is read from WooCommerce.
  }

  // ── Restore a saved chat (from the WooCommerce session) ───────────────────
  async function restoreChat(saved) {
    if (!saved || saved.v !== 1 || !Array.isArray(saved.msgs) || !saved.msgs.length) return;

    ctx = { ...freshCtx(), ...(saved.ctx || {}), cart: [] };       // the cart is re-read live, never restored
    turnNum = saved.turn || 0;

    const ids = [...new Set(saved.msgs.flatMap(m => m.ids || []))];
    const cards = await fetchCards(ids);

    restoring = true;                                   // replay without re-recording
    thread.innerHTML = '';
    const last = saved.msgs.length - 1;
    saved.msgs.forEach((m, i) => {
      if (m.t) addMessage(m.t, m.r === 'u' ? 'user' : 'assistant');
      const items = (m.ids || []).map(id => cards[id]).filter(Boolean);
      if (items.length) renderCards(items);
      if (i === last && m.b && m.b.length) renderButtons(m.b);   // only the newest buttons are still live
    });
    restoring = false;
    transcript = saved.msgs.slice(-MAX_MSGS);
    scrollToBottom();
  }

  // ── Start over ────────────────────────────────────────────────────────────
  async function newChat() {
    clearTimeout(saveTimer);
    try {
      await fetch(cfg.chatApi, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': cfg.restNonce || '' },
      });
    } catch (_) { /* local reset still happens */ }
    transcript = [];
    turnNum = 0;
    const cart = ctx.cart;
    ctx = { ...freshCtx(), cart };
    thread.innerHTML = '';
    showGreeting();
    input.focus();
  }

  // ── One turn ──────────────────────────────────────────────────────────────
  async function submitMessage(text) {
    if (!text.trim()) return;
    await restored;                                     // never interleave with a restore in progress
    turnNum++;
    document.querySelectorAll('.alice__chips').forEach(row => row.remove());   // old chips are stale now
    addMessage(text, 'user');
    setStatus('Thinking…');
    send.disabled = true;

    const payload = {
      v: 1,
      turn: turnNum,
      session: sessionId,
      catalog_version: cfg.catalogVersion || 0,
      msg: text,
      store: cfg.store || {},
      ctx: { ...ctx, page: window.location.pathname },
    };

    try {
      const r = await fetch(`${BRAIN}/turn`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json',
                   'Authorization': `Bearer ${cfg.brainToken || ''}` },
        body: JSON.stringify(payload),
      });

      if (!r.ok) throw new Error(`Brain returned ${r.status}: ${await r.text()}`);

      const resp = await r.json();
      if (resp.turn !== turnNum) throw new Error(`Turn mismatch: expected ${turnNum}, got ${resp.turn}`);

      applyPatch(resp.patch);

      const isCartAction = resp.action && resp.action.type.startsWith('cart.');
      const result = await executeAction(resp.action);

      if (isCartAction && !result.ok) {
        // The cart write failed: don't claim success, and say WHY.
        ctx.pending = null;
        ctx.focus = null;
        addMessage(`I couldn't update your cart: ${result.error || 'something went wrong'}.`, 'assistant');
      } else {
        const d = resp.display || {};
        if (d.speech)                      addMessage(d.speech, 'assistant');
        if (d.items && d.items.length)     renderCards(d.items);
        if (d.buttons && d.buttons.length) renderButtons(d.buttons);
        if (isCartAction) await refreshCart();
      }

      const m = resp.meta || {};
      const callsNote = m.llm_calls > 0 ? ` · ${m.llm_calls} LLM call(s)` : ' · 0 LLM calls';
      setStatus(`${m.path || 'ok'} · ${m.ms || 0}ms${callsNote}`, 'is-ok');

    } catch (e) {
      console.error('Alice turn error:', e);
      addMessage('Something went wrong — try again or browse the shop.', 'assistant');
      setStatus('Error', 'is-bad');
    } finally {
      send.disabled = !input.value.trim();
      input.focus();
      saveChat();
    }
  }

  // ── Input handling ────────────────────────────────────────────────────────
  const resize = () => {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 200) + 'px';
    send.disabled = !input.value.trim();
  };
  input.addEventListener('input', resize);

  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
      e.preventDefault();
      form.requestSubmit();
    }
  });

  form.addEventListener('submit', e => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    resize();
    submitMessage(text);
  });

  if (newBtn) newBtn.addEventListener('click', newChat);

  // Last chance to save when the shopper leaves the page.
  window.addEventListener('pagehide', () => saveChat(true));

  // ── Startup: connectivity, the real cart, then the saved conversation ─────
  fetch(cfg.storeApi + '/products?per_page=1', { credentials: 'same-origin' })
    .then(r => setStatus(`Store OK · ${r.headers.get('X-WP-Total') ?? '?'} products`, 'is-ok'))
    .catch(() => setStatus('Store API unreachable', 'is-bad'));

  refreshCart();
  const restored = restoreChat(cfg.chat);

  input.focus();
})();
