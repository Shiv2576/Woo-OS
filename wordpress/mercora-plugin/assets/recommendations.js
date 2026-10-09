/* wordpress/mercora-plugin/assets/recommendations.js
 *
 * Upgrades the homepage's three default product sections into the brain's three
 * personalised tiers.
 *
 * The page already renders Featured / On sale / Bestsellers server-side — that
 * is the default recommendation context, and it is what every guest and every
 * shopper with no chat history sees. This script only *replaces* a section when
 * the brain actually has something better for that tier.
 *
 * A tier section comes in one of two shapes.
 *
 * WITH a default (tier 1) — visible from the start, swapped when personalised:
 *
 *   <section data-mercora-tier="tier_1">
 *     ... <div data-mercora-head><h2>…</h2><p>…</p></div> ...
 *     <div data-mercora-slot hidden></div>
 *     <div data-mercora-default> [products ...] </div>
 *   </section>
 *
 * WITHOUT a default (tiers 2 and 3) — the whole section carries `hidden` and
 * only ever appears once the brain has a second or third category for this
 * customer. There is deliberately nothing generic to fall back to:
 *
 *   <section data-mercora-tier="tier_2" hidden>
 *     ... <div data-mercora-head>…</div> ...
 *     <div data-mercora-slot hidden></div>
 *   </section>
 *
 * Nothing is touched until the data arrives, so there is no flash of empty
 * state and no layout shift if the request fails or returns nothing.
 */
(function () {
  'use strict';

  var cfg = window.MERCORA_RECO || {};
  if (!cfg.endpoint) return;

  var sections = document.querySelectorAll('[data-mercora-tier]');
  if (!sections.length) return;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function cardHtml(p) {
    return (
      '<li class="mercora-reco__card">' +
        '<a href="' + esc(p.url) + '">' +
          (p.image
            ? '<img src="' + esc(p.image) + '" alt="" loading="lazy" width="300" height="300">'
            : '<span class="mercora-reco__ph" aria-hidden="true"></span>') +
          '<span class="mercora-reco__name">' + esc(p.name) + '</span>' +
          '<span class="mercora-reco__price">' + esc(p.price) + '</span>' +
        '</a>' +
      '</li>'
    );
  }

  function upgrade(section, tier) {
    if (!tier.products || !tier.products.length) return;

    var slot = section.querySelector('[data-mercora-slot]');
    var fallback = section.querySelector('[data-mercora-default]');
    if (!slot) return;

    slot.innerHTML =
      '<ul class="mercora-reco__grid">' + tier.products.map(cardHtml).join('') + '</ul>';

    // Swap the heading so the shopper understands WHY these products are here.
    var head = section.querySelector('[data-mercora-head]');
    if (head) {
      var h = head.querySelector('h1,h2,h3');
      var sub = head.querySelector('p');
      if (h && tier.title) h.textContent = tier.title;
      if (sub && tier.subtitle) sub.textContent = tier.subtitle;
    }

    // Reveal ours, retire the default. `hidden` (not style.display) so the
    // theme's own CSS keeps control of how the visible one is laid out.
    slot.hidden = false;
    if (fallback) fallback.hidden = true;

    // Tiers 2 and 3 have no default, so the whole section starts hidden. This
    // is the only thing that ever reveals them.
    section.hidden = false;

    section.setAttribute('data-mercora-state', 'personalised');
  }

  fetch(cfg.endpoint, {
    credentials: 'same-origin',
    headers: { 'X-WP-Nonce': cfg.nonce || '' },
  })
    .then(function (r) {
      return r.ok ? r.json() : null;
    })
    .then(function (d) {
      var tiers = (d && d.tiers) || [];
      if (!tiers.length) return; // no context yet — the defaults are correct

      var byKey = {};
      tiers.forEach(function (t) {
        byKey[t.key] = t;
      });

      Array.prototype.forEach.call(sections, function (section) {
        var t = byKey[section.getAttribute('data-mercora-tier')];
        if (t) upgrade(section, t);
      });
    })
    .catch(function () {
      /* defaults stay exactly as rendered */
    });
})();
