(() => {
  const cfg = window.MERCORA || {};
  const $ = (id) => document.getElementById(id);

  const scroller = $('alice-scroll');   // the scrolling area
  const thread   = $('alice-thread');   // where messages are appended
  const status   = $('alice-status');
  const form     = $('alice-composer');
  const input    = $('alice-input');
  const send     = $('alice-send');

  const scrollToBottom = () => { scroller.scrollTop = scroller.scrollHeight; };

  const add = (text, who) => {
    const el = document.createElement('div');
    el.className = `alice__msg alice__msg--${who}`;
    el.textContent = text;
    thread.appendChild(el);
    scrollToBottom();
    return el;
  };

  // Auto-grow the textarea up to its max-height, and toggle the send button
  const resize = () => {
    input.style.height = 'auto';
    input.style.height = `${Math.min(input.scrollHeight, 200)}px`;
    send.disabled = input.value.trim() === '';
  };
  input.addEventListener('input', resize);

  // Enter sends, Shift+Enter adds a new line
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
      e.preventDefault();
      form.requestSubmit();
    }
  });

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    add(text, 'user');
    input.value = '';
    resize();
    input.focus();
    add("I'm not connected to the Alice API yet.", 'assistant');
  });

  // Store API connectivity check
  fetch(`${cfg.storeApi}/products?per_page=1`, { credentials: 'same-origin' })
    .then((r) => {
      if (!r.ok) throw new Error(r.status);
      status.textContent = `Store API ok · ${r.headers.get('X-WP-Total') ?? '?'} products`;
      status.classList.add('is-ok');
    })
    .catch((e) => {
      status.textContent = `Store API unreachable (${e.message})`;
      status.classList.add('is-bad');
    });

  input.focus();
})();
