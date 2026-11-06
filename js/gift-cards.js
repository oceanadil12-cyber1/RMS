/*
 * Gift cards page (gift-cards.html): pick a design (or make your own), an amount and
 * who it's for, then pay with Square's card field, or, while card payments are off,
 * send a request the team takes payment for. Also checks balances, and shows a card
 * from an emailed link (gift-cards.html#card=<token>). Talks to api/gift-cards.php.
 */
(function () {
  const form = document.getElementById('gc-form');
  if (!form) return;

  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = (cents) => '$' + (cents / 100).toFixed(cents % 100 ? 2 : 0);
  const uid = () => (crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2));
  const today = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10); };

  const state = { cfg: null, design: '', amount: 0, custom: false, forMe: false, photo: '', photoUrl: '', busy: false, card: null, square: null, payKey: '' };

  async function api(body) {
    const r = await fetch('api/gift-cards.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    let res;
    try { res = await r.json(); } catch (e) { throw new Error('Something went wrong. Please try again or call us at (954) 565-2526.'); }
    if (!res.ok) throw new Error(res.error || 'Something went wrong. Please try again.');
    return res;
  }

  // ---------- the card picture ----------

  /** A gift card as HTML: the design's picture (or the buyer's photo and words) with the amount. */
  function cardHtml(c) {
    const custom = c.custom;
    const bg = c.image ? ` style="background-image:url('${esc(c.image)}')"` : '';
    return `<div class="gc-card-face${custom ? ' is-custom' : ''}${c.image ? '' : ' no-image'}"${bg}>`
      + (custom ? `<span class="gc-card-brand">RMS</span>${c.title ? `<span class="gc-card-title">${esc(c.title)}</span>` : ''}` : '')
      + (c.amount ? `<span class="gc-card-amount">${money(c.amount)}</span>` : '')
      + '</div>';
  }

  function currentAmount() {
    if (!state.custom) return state.amount;
    const n = parseInt(($('gc-amount-input').value || '').replace(/[^\d]/g, ''), 10);
    return isNaN(n) ? 0 : n * 100;
  }

  function currentDesign() {
    if (state.design === 'custom') return { custom: true, image: state.photoUrl, title: $('gc-title').value.trim() };
    const d = state.cfg.designs.find((x) => x.id === state.design) || {};
    return { custom: false, image: d.image, title: d.title, name: d.name };
  }

  function renderPreview() {
    const d = currentDesign();
    const amount = currentAmount();
    $('gc-preview').innerHTML = cardHtml({ ...d, amount });
    const lines = [
      ['Design', d.custom ? 'Your own design' : (d.name || '')],
      ['To', state.forMe ? 'You' : ($('gc-to-name').value.trim() || '—')],
      ['Delivery', state.forMe ? 'Emailed to you' : ($('gc-date').value && $('gc-date').value > today() ? new Date($('gc-date').value + 'T12:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) : 'Right away')],
    ];
    $('gc-lines').innerHTML = lines.map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`).join('')
      + `<div class="gc-total"><dt>Total</dt><dd>${amount ? money(amount) : '—'}</dd></div>`;
    const verb = state.cfg.mode === 'square' ? 'Buy Gift Card' : 'Request Gift Card';
    $('gc-buy').textContent = state.busy ? (state.cfg.mode === 'square' ? 'Processing…' : 'Sending…') : (amount ? `${verb} — ${money(amount)}` : verb);
    $('gc-buy').disabled = state.busy;
  }

  // ---------- choices ----------

  function renderDesigns() {
    const c = state.cfg;
    let html = c.designs.map((d) => `<button type="button" class="gc-design" role="radio" aria-checked="false" data-design="${esc(d.id)}">`
      + `<span class="gc-card">${cardHtml({ image: d.image })}</span><span class="gc-design-name">${esc(d.name)}</span></button>`).join('');
    if (c.allowCustomDesign) {
      html += '<button type="button" class="gc-design" role="radio" aria-checked="false" data-design="custom">'
        + '<span class="gc-card"><span class="gc-card-face gc-own"><span class="gc-own-plus" aria-hidden="true">+</span><span>Your photo &amp; words</span></span></span>'
        + '<span class="gc-design-name">Design Your Own</span></button>';
    }
    $('gc-designs').innerHTML = html;
    pickDesign(c.designs[0] ? c.designs[0].id : (c.allowCustomDesign ? 'custom' : ''));
  }

  function pickDesign(id) {
    state.design = id;
    $('gc-designs').querySelectorAll('.gc-design').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.design === id)));
    $('gc-custom').hidden = id !== 'custom';
    renderPreview();
  }

  function renderAmounts() {
    const c = state.cfg;
    $('gc-amount-sub').textContent = c.allowCustomAmount
      ? `Pick an amount, or enter any amount from ${money(c.min)} to ${money(c.max)}.`
      : 'Pick an amount.';
    $('gc-amounts').innerHTML = c.amounts.map((a) => `<button type="button" class="gc-amount" role="radio" aria-checked="false" data-amount="${a}">${money(a)}</button>`).join('')
      + (c.allowCustomAmount ? '<button type="button" class="gc-amount gc-amount-custom" role="radio" aria-checked="false" data-amount="custom">Custom</button>' : '');
    pickAmount(c.amounts[1] != null ? String(c.amounts[1]) : (c.amounts[0] != null ? String(c.amounts[0]) : 'custom'));
  }

  function pickAmount(v) {
    state.custom = v === 'custom';
    state.amount = state.custom ? 0 : parseInt(v, 10);
    $('gc-amounts').querySelectorAll('.gc-amount').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.amount === v)));
    $('gc-custom-amount').hidden = !state.custom;
    if (state.custom) $('gc-amount-input').focus();
    renderPreview();
  }

  function pickFor(who) {
    state.forMe = who === 'me';
    form.querySelectorAll('[data-for]').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.for === who)));
    $('gc-recipient').hidden = state.forMe;
    renderPreview();
  }

  /** The buyer's picture, cropped to the card's shape (1200 × 750) as a JPEG. */
  function loadPhoto(file) {
    if (!file) { state.photo = ''; state.photoUrl = ''; renderPreview(); return; }
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { showError('Please choose a JPG, PNG or WebP picture.'); $('gc-photo').value = ''; return; }
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      const W = 1200, H = 750;
      const canvas = document.createElement('canvas');
      canvas.width = W; canvas.height = H;
      const scale = Math.max(W / img.naturalWidth, H / img.naturalHeight);
      const w = img.naturalWidth * scale, h = img.naturalHeight * scale;
      canvas.getContext('2d').drawImage(img, (W - w) / 2, (H - h) / 2, w, h);
      URL.revokeObjectURL(url);
      let q = 0.86, data = canvas.toDataURL('image/jpeg', q);
      while (data.length > 1900000 && q > 0.4) { q -= 0.12; data = canvas.toDataURL('image/jpeg', q); }
      state.photo = data;
      state.photoUrl = data;
      showError('');
      renderPreview();
    };
    img.onerror = () => { URL.revokeObjectURL(url); showError('That picture could not be opened. Please choose another.'); };
    img.src = url;
  }

  // ---------- paying ----------

  function loadSquare() {
    if (window.Square) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = state.cfg.environment === 'production' ? 'https://web.squarecdn.com/v1/square.js' : 'https://sandbox.web.squarecdn.com/v1/square.js';
      s.onload = resolve;
      s.onerror = () => reject(new Error('Could not load the secure payment form.'));
      document.head.appendChild(s);
    });
  }

  async function mountCard() {
    try {
      await loadSquare();
      state.square = window.Square.payments(state.cfg.applicationId, state.cfg.locationId);
      state.card = await state.square.card({
        style: {
          input: { color: '#FFFFFF', backgroundColor: '#141414', fontSize: '15px' },
          'input::placeholder': { color: '#888888' },
          '.input-container': { borderColor: '#333333', borderRadius: '0px' },
          '.input-container.is-focus': { borderColor: '#C8A84E' },
        },
      });
      await state.card.attach('#gc-card-field');
    } catch (e) {
      showError(e.message || 'The secure payment form could not be loaded.');
    }
  }

  function showError(msg) {
    const el = $('gc-error');
    el.textContent = msg;
    el.hidden = !msg;
    if (msg) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function validate() {
    const c = state.cfg;
    const email = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
    if (!state.design) return 'Please choose a design.';
    if (state.design === 'custom' && !state.photo && !$('gc-title').value.trim()) return 'Add a picture or a few words for your own design.';
    const amount = currentAmount();
    if (!amount) return 'Please choose an amount.';
    if (state.custom && (amount < c.min || amount > c.max)) return `Please enter an amount from ${money(c.min)} to ${money(c.max)}.`;
    if (!state.forMe) {
      if (!$('gc-to-name').value.trim()) return 'Please enter the recipient\'s name.';
      if (!email($('gc-to-email').value.trim())) return 'Please enter a valid email address for the recipient.';
    }
    if (!$('gc-from-name').value.trim()) return 'Please enter your name.';
    if (!email($('gc-from-email').value.trim())) return 'Please enter a valid email address for your receipt.';
    if (c.mode === 'request' && $('gc-from-phone').value.replace(/\D/g, '').length < 10) return 'Please enter your phone number so we can arrange payment.';
    return '';
  }

  async function buy(e) {
    e.preventDefault();
    if (state.busy) return;
    const problem = validate();
    if (problem) return showError(problem);
    showError('');
    const body = {
      action: 'buy',
      design: state.design,
      title: state.design === 'custom' ? $('gc-title').value.trim() : '',
      photo: state.design === 'custom' ? state.photo : '',
      amount: currentAmount(),
      buyer: { name: $('gc-from-name').value.trim(), email: $('gc-from-email').value.trim(), phone: $('gc-from-phone').value.trim() },
      recipient: state.forMe ? null : { name: $('gc-to-name').value.trim(), email: $('gc-to-email').value.trim() },
      message: state.forMe ? '' : $('gc-message').value.trim(),
      deliverOn: state.forMe ? '' : $('gc-date').value,
    };
    state.busy = true;
    renderPreview();
    try {
      if (state.cfg.mode === 'square') {
        if (!state.card) throw new Error('The payment form is still loading.');
        const tok = await state.card.tokenize();
        if (tok.status !== 'OK') throw new Error((tok.errors && tok.errors[0] && tok.errors[0].message) || 'Please check your card details.');
        state.payKey = state.payKey || uid(); // the same key on a retry = never charged twice
        body.sourceId = tok.token;
        body.idempotencyKey = state.payKey;
      }
      showDone(await api(body), body);
    } catch (err) {
      state.busy = false;
      renderPreview();
      showError(err.message);
    }
  }

  function showDone(res, body) {
    const shop = $('gc-shop');
    const to = body.recipient ? body.recipient.name : '';
    let html;
    if (res.mode === 'request') {
      html = `<h2 class="section-title">Request <em>received</em></h2>`
        + `<p>Thanks, ${esc(body.buyer.name)}! We'll call you at ${esc(body.buyer.phone)} to take payment for your ${money(body.amount)} gift card`
        + (to ? `, then email it to ${esc(to)}${body.deliverOn > today() ? ' on ' + esc(new Date(body.deliverOn + 'T12:00').toLocaleDateString('en-US', { month: 'long', day: 'numeric' })) : ''}.` : ', then email it to you.')
        + `</p><p class="gc-hint">Reference: card ending ${esc(res.reference)}. A copy of this request is on its way to ${esc(body.buyer.email)}.</p>`;
    } else if (res.status !== 'active') {
      html = `<h2 class="section-title">Payment <em>received</em></h2><p>Thank you! Your payment went through. Our team is finishing your gift card and will email it shortly.</p>`;
    } else if (res.card && res.card.code) {
      html = `<h2 class="section-title">Your gift card is <em>ready</em></h2>`
        + `<div class="gc-card gc-card-lg gc-done-card">${cardHtml({ custom: res.card.custom, image: res.card.image, title: res.card.title, amount: res.card.amount })}</div>`
        + `<p class="gc-code">Card code <b>${esc(res.card.code)}</b></p><p>We've emailed it to ${esc(body.buyer.email)}. Show the code at the bar to use it.</p>`;
    } else {
      html = `<h2 class="section-title">Gift card <em>sent</em></h2><p>Thank you, ${esc(body.buyer.name)}! Your ${money(body.amount)} gift card `
        + (body.deliverOn > today() ? `will be emailed to ${esc(to)} on ${esc(new Date(body.deliverOn + 'T12:00').toLocaleDateString('en-US', { month: 'long', day: 'numeric' }))}.` : `is on its way to ${esc(to)}.`)
        + ` Your receipt is in your inbox.</p>`;
    }
    if (res.receipt) html += `<p><a class="btn-outline" href="${esc(res.receipt)}" target="_blank" rel="noopener">View receipt</a></p>`;
    html += '<p><a class="btn-primary" href="gift-cards.html">Buy another gift card</a></p>';
    shop.innerHTML = `<div class="gc-done">${html}</div>`;
    shop.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // ---------- balance and emailed links ----------

  async function checkBalance(e) {
    e.preventDefault();
    const out = $('gc-balance-out');
    const code = $('gc-balance-code').value.replace(/\D/g, '');
    if (code.length < 8) { out.textContent = 'Please enter the code from your gift card.'; return; }
    out.textContent = 'Checking…';
    try {
      const res = await api({ action: 'balance', code });
      out.innerHTML = res.status === 'void'
        ? `The card ending ${esc(res.last4)} is no longer active. Please call us at (954) 565-2526.`
        : `The card ending ${esc(res.last4)} has <b>${money(res.balance)}</b> left.`;
    } catch (err) {
      out.textContent = err.message;
    }
  }

  async function showCard(token) {
    const view = $('gc-view');
    $('gc-shop').hidden = true;
    view.hidden = false;
    view.innerHTML = '<p class="gc-hint">Loading your gift card…</p>';
    try {
      const { card } = await api({ action: 'view', token });
      view.innerHTML = '<div class="gc-view-inner">'
        + (card.to ? `<span class="section-label">For ${esc(card.to)}</span>` : '<span class="section-label">Your Gift Card</span>')
        + `<div class="gc-card gc-card-lg">${cardHtml({ custom: card.custom, image: card.image, title: card.title, amount: card.amount })}</div>`
        + (card.from ? `<p class="gc-from">From <b>${esc(card.from)}</b></p>` : '')
        + (card.message ? `<blockquote class="gc-message">${esc(card.message)}</blockquote>` : '')
        + (card.code
          ? `<p class="gc-code">Card code <b>${esc(card.code)}</b></p><p class="gc-balance-big">Balance <b>${money(card.balance)}</b></p><p class="gc-hint">Show this page or the code to your server or bartender.</p>`
          : `<p class="gc-hint">This card isn't active yet. ${card.status === 'void' ? 'Please call us at (954) 565-2526.' : 'It will work as soon as payment is confirmed.'}</p>`)
        + '<p><a class="btn-outline" href="gift-cards.html">Buy a gift card</a></p></div>';
    } catch (err) {
      view.innerHTML = `<p class="gc-note">${esc(err.message)}</p><p><a class="btn-outline" href="gift-cards.html">Gift cards</a></p>`;
    }
  }

  // ---------- start ----------

  async function start() {
    $('gc-balance-form').addEventListener('submit', checkBalance);
    const m = location.hash.match(/^#card=([a-f0-9]{32})$/);
    if (m) showCard(m[1]);

    let cfg;
    try {
      cfg = await (await fetch('api/gift-cards.php', { headers: { Accept: 'application/json' } })).json();
    } catch (e) { cfg = null; }
    if (!cfg || !cfg.ok || !cfg.enabled || (!cfg.designs.length && !cfg.allowCustomDesign)) { $('gc-unavailable').hidden = false; return; }
    state.cfg = cfg;
    form.hidden = false;

    const d = $('gc-date');
    d.min = today();
    const max = new Date(); max.setFullYear(max.getFullYear() + 1);
    d.max = max.toISOString().slice(0, 10);

    renderDesigns();
    renderAmounts();
    pickFor('someone');
    $('gc-phone-wrap').hidden = cfg.mode !== 'request';
    $('gc-pay-request').hidden = cfg.mode !== 'request';
    $('gc-pay-square').hidden = cfg.mode !== 'square';
    $('gc-secure').hidden = cfg.mode !== 'square';
    if (cfg.mode === 'square') mountCard();

    $('gc-designs').addEventListener('click', (e) => { const b = e.target.closest('[data-design]'); if (b) pickDesign(b.dataset.design); });
    $('gc-amounts').addEventListener('click', (e) => { const b = e.target.closest('[data-amount]'); if (b) pickAmount(b.dataset.amount); });
    form.querySelectorAll('[data-for]').forEach((b) => b.addEventListener('click', () => pickFor(b.dataset.for)));
    $('gc-photo').addEventListener('change', (e) => loadPhoto(e.target.files[0]));
    ['gc-title', 'gc-amount-input', 'gc-to-name', 'gc-date'].forEach((id) => $(id).addEventListener('input', renderPreview));
    form.addEventListener('submit', buy);
  }

  start();
})();
