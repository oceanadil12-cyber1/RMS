/* Online ordering for the menu page.
 *
 * Menu, sizes and options come from api/menu.php (live Square data; falls back
 * to data/menu.json). The cart lives in this browser; checkout posts to
 * api/checkout.php, which re-prices everything and creates the Square order
 * and payment. Card details are entered in Square's own secure field
 * (Web Payments SDK) and never touch our server.
 */
(function () {
  const root = document.getElementById('order-menu');
  if (!root) return;

  const CART_KEY = 'rms-cart-v1';
  const TIP_OPTIONS = [0, 15, 18, 20];
  const DAYS = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

  const state = {
    menu: null,
    cart: [],
    fulfillment: { type: 'pickup', address: { line1: '', line2: '', city: 'Wilton Manors', state: 'FL', zip: '', instructions: '' } },
    customer: { name: '', phone: '', email: '' },
    tipPercent: 18,
    tipCustom: '',
    quote: null,
    quoteError: '',
    view: 'cart',
    placing: false,
    payKey: null,
    square: null,
    card: null,
  };

  // ---------- helpers ----------
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = (cents) => '$' + (cents / 100).toFixed(2);
  const $ = (sel, el = document) => el.querySelector(sel);
  const uid = () => (crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2));

  function loadCart() {
    try { return JSON.parse(localStorage.getItem(CART_KEY)) || []; } catch (e) { return []; }
  }
  function saveCart() {
    try { localStorage.setItem(CART_KEY, JSON.stringify(state.cart)); } catch (e) { /* storage unavailable */ }
    state.payKey = null; // a changed cart is a new payment attempt
  }

  function item(id) { return state.menu && state.menu.items[id]; }

  /** Price + labels for a cart line; null if it no longer matches the menu. */
  function describe(line) {
    const it = item(line.itemId);
    if (!it) return null;
    const v = it.variations.find((x) => x.id === line.variationId);
    if (!v) return null;
    const mods = [];
    it.modifiers.forEach((g) => g.choices.forEach((c) => { if (line.modifiers.includes(c.id)) mods.push(c); }));
    if (mods.length !== line.modifiers.length) return null;
    const unit = v.price + mods.reduce((s, c) => s + c.price, 0);
    return { item: it, variation: v, mods, unit, total: unit * line.qty };
  }

  // Square pre-selects placeholder choices like "None"; they're sent but not worth showing.
  const shownMods = (d) => d.mods.filter((m) => !/^(none|no thanks)$/i.test(m.name.trim()));

  const validLines = () => state.cart.filter((l) => describe(l));

  // Distinct names of cart items that are pickup-only, when delivery is chosen.
  function deliveryBlocked() {
    if (state.fulfillment.type !== 'delivery') return [];
    const names = state.cart.map((l) => item(l.itemId)).filter((it) => it && it.pickupOnly).map((it) => it.name);
    return [...new Set(names)];
  }
  // Distinct names of cart items that are delivery-only, when pickup is chosen.
  function pickupBlocked() {
    if (state.fulfillment.type !== 'pickup') return [];
    const names = state.cart.map((l) => item(l.itemId)).filter((it) => it && it.deliveryOnly).map((it) => it.name);
    return [...new Set(names)];
  }
  const subtotal = () => validLines().reduce((s, l) => s + describe(l).total, 0);
  const cartCount = () => validLines().reduce((s, l) => s + l.qty, 0);

  function tipAmount() {
    if (state.tipPercent === 'custom') return Math.max(0, Math.round(parseFloat(state.tipCustom || '0') * 100) || 0);
    return Math.round(subtotal() * state.tipPercent / 100);
  }

  // Store hours in the restaurant's time zone.
  function storeClock() {
    const tz = (state.menu && state.menu.store.timezone) || 'America/New_York';
    const parts = new Intl.DateTimeFormat('en-US', { timeZone: tz, weekday: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).formatToParts(new Date());
    const get = (t) => parts.find((p) => p.type === t).value;
    return { day: get('weekday').toUpperCase().slice(0, 3), time: `${get('hour')}:${get('minute')}:${get('second')}` };
  }
  // Store settings can switch pickup (takeaway) or delivery off.
  const typeEnabled = (type) => (type === 'delivery' ? !!state.menu.store.deliveryEnabled : state.menu.store.pickupEnabled !== false);
  // Delivery zones (admin → Delivery): how delivery is described and what the postal code is called.
  const dz = () => state.menu.store.delivery || { summary: 'Delivery', note: 'Delivery', postalLabel: 'ZIP code', postalUsed: true, postalRequired: true, postalNumeric: true };

  function openInfo(type) {
    const s = state.menu.store;
    if (!typeEnabled(type)) return { open: false, text: `${type === 'delivery' ? 'Delivery' : 'Pickup'} is not available right now` };
    const hours = type === 'delivery' ? s.deliveryHours : s.pickupHours;
    const { day, time } = storeClock();
    const today = hours[day] || [];
    const slot = today.find((h) => time >= h.open && time < h.close);
    const fmt = (t) => { const [h, m] = t.split(':').map(Number); const hr = ((h + 11) % 12) + 1; return `${hr}${m ? ':' + String(m).padStart(2, '0') : ''} ${h < 12 || h === 24 ? 'AM' : 'PM'}`; };
    if (slot) return { open: true, text: `Open now until ${fmt(slot.close)}` };
    const later = today.find((h) => time < h.open);
    if (later) return { open: false, text: `Opens today at ${fmt(later.open)}` };
    for (let i = 1; i <= 7; i++) {
      const d = DAYS[(DAYS.indexOf(day) + i) % 7];
      if ((hours[d] || [])[0]) return { open: false, text: `Opens ${i === 1 ? 'tomorrow' : d.charAt(0) + d.slice(1).toLowerCase()} at ${fmt(hours[d][0].open)}` };
    }
    return { open: false, text: 'Online ordering is currently unavailable' };
  }

  function priceLabel(it) {
    const prices = it.variations.map((v) => v.price);
    const min = Math.min(...prices);
    return prices.some((p) => p !== min) ? `from ${money(min)}` : money(min);
  }

  // ---------- menu ----------
  async function loadMenu() {
    try {
      const r = await fetch('api/menu.php', { headers: { Accept: 'application/json' } });
      if (r.ok && (r.headers.get('content-type') || '').includes('json')) return await r.json();
    } catch (e) { /* fall back below */ }
    const r = await fetch('data/menu.json');
    const menu = await r.json();
    menu.paymentsEnabled = false;
    menu.offline = true; // no PHP here: browse only
    return menu;
  }

  function renderMenu() {
    const m = state.menu;
    const nav = document.getElementById('menu-nav');
    if (nav) {
      // "Happy Hour" goes first and opens the full Happy Hour menu section on the page.
      const hasHappyHour = document.getElementById('happy-hour');
      const links = m.categories
        .filter((c) => !(hasHappyHour && c.id === 'happy-hour'))
        .map((c) => `<a href="#cat-${esc(c.id)}">${esc(c.name.replace('Cold Sandwiches & Wraps', 'Cold Sandwiches').replace('Dressings & Sauces', 'Sauces'))}</a>`);
      if (hasHappyHour) links.unshift('<a href="#happy-hour">Happy Hour</a>');
      nav.insertAdjacentHTML('afterbegin', links.join(''));
    }
    root.innerHTML = m.categories.map((c) => `
      <div class="menu-category order-category" id="cat-${esc(c.id)}">
        <div class="menu-category-header"><h3>${esc(c.name)}</h3><div class="line"></div></div>
        <div class="order-grid">
          ${c.itemIds.map((id) => {
            const it = m.items[id];
            const sold = it.soldOut || it.variations.every((v) => v.soldOut);
            return `
              <button type="button" class="order-card${sold ? ' is-sold-out' : ''}" data-item="${esc(id)}" ${sold ? 'disabled' : ''}>
                <span class="order-card-body">
                  <span class="order-card-name">${esc(it.name)}${it.pickupOnly ? '<span class="order-tag-pickup">Pickup only</span>' : ''}${it.deliveryOnly ? '<span class="order-tag-pickup">Delivery only</span>' : ''}</span>
                  ${it.description ? `<span class="order-card-desc">${esc(it.description)}</span>` : ''}
                  <span class="order-card-price">${sold ? 'Sold out' : priceLabel(it)}</span>
                </span>
                ${it.image ? `<img class="order-card-img" src="${esc(it.image)}" alt="" loading="lazy">` : ''}
                ${sold ? '' : '<span class="order-card-add" aria-hidden="true">+</span>'}
              </button>`;
          }).join('')}
        </div>
      </div>`).join('');
    root.addEventListener('click', (e) => {
      const card = e.target.closest('.order-card');
      if (card && !card.disabled) openItem(card.dataset.item);
    });
    renderStatus();
  }

  function renderStatus() {
    const el = document.getElementById('order-status');
    if (!el || !state.menu) return;
    const s = state.menu.store;
    const p = openInfo(typeEnabled('pickup') || !typeEnabled('delivery') ? 'pickup' : 'delivery');
    const offers = [
      typeEnabled('pickup') ? `Pickup at ${esc(s.address.split(',')[0])}` : '',
      typeEnabled('delivery') ? esc(dz().summary) : '',
    ].filter(Boolean);
    el.innerHTML = `
      <div class="order-status-main">
        <span class="order-status-dot ${p.open ? 'is-open' : ''}"></span>
        <div>
          <strong>${p.open ? 'Taking online orders' : 'Online ordering closed'}</strong>
          <span>${offers.length ? `${esc(p.text)} &middot; ${offers.join(' &middot; ')} &middot; Ready in about ${s.prepMinutes} min` : 'Online ordering is not available right now. Please call us to order.'}</span>
        </div>
      </div>
      ${state.menu.offline ? '<p class="order-status-note">Preview mode: ordering works on the live website.</p>' : ''}`;
  }

  // ---------- item dialog ----------
  let modal = null;

  function openItem(id, editIndex = null) {
    const it = item(id);
    if (!it) return;
    const editing = editIndex !== null ? state.cart[editIndex] : null;
    const sel = {
      variationId: editing ? editing.variationId : (it.variations.find((v) => !v.soldOut) || it.variations[0]).id,
      mods: new Set(editing ? editing.modifiers : it.modifiers.flatMap((g) => g.choices.filter((c) => c.default && !c.soldOut).map((c) => c.id))),
      qty: editing ? editing.qty : 1,
      note: editing ? editing.note : '',
    };
    const maxQty = it.maxQty || 50;

    closeModal();
    modal = document.createElement('div');
    modal.className = 'order-modal';
    modal.innerHTML = `
      <div class="order-modal-backdrop" data-close></div>
      <div class="order-modal-panel" role="dialog" aria-modal="true" aria-labelledby="order-modal-title">
        <button type="button" class="order-modal-close" data-close aria-label="Close">&times;</button>
        <div class="order-modal-scroll">
          ${it.image ? `<img class="order-modal-img" src="${esc(it.image)}" alt="">` : ''}
          <h3 id="order-modal-title">${esc(it.name)}</h3>
          ${it.description ? `<p class="order-modal-desc">${esc(it.description)}</p>` : ''}
          ${it.variations.length > 1 ? `
            <fieldset class="order-group" data-group="__variation">
              <legend><span>Choose one</span><em class="order-req">Required</em></legend>
              ${it.variations.map((v) => `
                <label class="order-choice${v.soldOut ? ' is-disabled' : ''}">
                  <input type="radio" name="variation" value="${esc(v.id)}" ${v.id === sel.variationId ? 'checked' : ''} ${v.soldOut ? 'disabled' : ''}>
                  <span class="order-choice-name">${esc(v.name)}${v.soldOut ? ' (sold out)' : ''}</span>
                  <span class="order-choice-price">${money(v.price)}</span>
                </label>`).join('')}
            </fieldset>` : ''}
          ${it.modifiers.map((g) => {
            const single = g.max === 1;
            const rule = g.min > 0
              ? (single ? 'Required' : `Required &middot; choose ${g.min}${g.max && g.max !== g.min ? `–${g.max}` : g.max ? '' : '+'}`)
              : (g.max > 0 ? `Optional &middot; up to ${g.max}` : 'Optional');
            return `
              <fieldset class="order-group" data-group="${esc(g.id)}">
                <legend><span>${esc(g.name)}</span><em class="${g.min > 0 ? 'order-req' : 'order-opt'}">${rule}</em></legend>
                <p class="order-group-error" hidden>Please make a selection.</p>
                ${g.choices.map((c) => `
                  <label class="order-choice${c.soldOut ? ' is-disabled' : ''}">
                    <input type="${single ? 'radio' : 'checkbox'}" name="g-${esc(g.id)}" value="${esc(c.id)}" ${sel.mods.has(c.id) ? 'checked' : ''} ${c.soldOut ? 'disabled' : ''}>
                    <span class="order-choice-name">${esc(c.name)}${c.soldOut ? ' (sold out)' : ''}</span>
                    ${c.price ? `<span class="order-choice-price">+${money(c.price)}</span>` : ''}
                  </label>`).join('')}
              </fieldset>`;
          }).join('')}
          <label class="order-note">
            <span>Special instructions</span>
            <textarea maxlength="200" rows="2" placeholder="Allergies, extra sauce on the side…">${esc(sel.note)}</textarea>
          </label>
        </div>
        <div class="order-modal-footer">
          <div class="order-qty" role="group" aria-label="Quantity">
            <button type="button" data-qty="-1" aria-label="Decrease quantity">&minus;</button>
            <span aria-live="polite">${sel.qty}</span>
            <button type="button" data-qty="1" aria-label="Increase quantity">+</button>
          </div>
          <button type="button" class="btn-primary order-add">${editing ? 'Update order' : 'Add to order'} &middot; <span></span></button>
        </div>
      </div>`;
    document.body.appendChild(modal);
    document.body.classList.add('order-lock');

    const panel = $('.order-modal-panel', modal);
    const update = () => {
      // enforce max selections for multi-choice groups
      it.modifiers.forEach((g) => {
        if (g.max <= 1) return;
        const boxes = [...panel.querySelectorAll(`[data-group="${CSS.escape(g.id)}"] input`)];
        const count = boxes.filter((b) => b.checked).length;
        boxes.forEach((b) => { if (!b.checked) b.disabled = count >= g.max || g.choices.find((c) => c.id === b.value).soldOut; });
      });
      const v = it.variations.find((x) => x.id === sel.variationId);
      let unit = v.price;
      it.modifiers.forEach((g) => g.choices.forEach((c) => { if (sel.mods.has(c.id)) unit += c.price; }));
      $('.order-add span', panel).textContent = money(unit * sel.qty);
      $('.order-qty span', panel).textContent = sel.qty;
    };

    panel.addEventListener('change', (e) => {
      const input = e.target;
      if (input.name === 'variation') sel.variationId = input.value;
      else if (input.name && input.name.startsWith('g-')) {
        const group = it.modifiers.find((g) => 'g-' + g.id === input.name);
        if (input.type === 'radio') group.choices.forEach((c) => sel.mods.delete(c.id));
        if (input.checked) sel.mods.add(input.value); else sel.mods.delete(input.value);
        const fs = input.closest('.order-group');
        fs.classList.remove('has-error');
        $('.order-group-error', fs).hidden = true;
      }
      update();
    });
    panel.addEventListener('input', (e) => { if (e.target.tagName === 'TEXTAREA') sel.note = e.target.value; });
    panel.addEventListener('click', (e) => {
      const q = e.target.closest('[data-qty]');
      if (q) { sel.qty = Math.min(maxQty, Math.max(1, sel.qty + Number(q.dataset.qty))); update(); }
      if (e.target.closest('.order-add')) {
        const missing = it.modifiers.find((g) => g.choices.filter((c) => sel.mods.has(c.id)).length < g.min);
        if (missing) {
          const fs = panel.querySelector(`[data-group="${CSS.escape(missing.id)}"]`);
          fs.classList.add('has-error');
          const err = $('.order-group-error', fs);
          err.textContent = `Please choose ${missing.min > 1 ? missing.min + ' options' : 'an option'} for ${missing.name}.`;
          err.hidden = false;
          fs.scrollIntoView({ behavior: 'smooth', block: 'center' });
          return;
        }
        const line = { key: editing ? editing.key : uid(), itemId: it.id, variationId: sel.variationId, qty: sel.qty, modifiers: [...sel.mods], note: sel.note.trim() };
        if (editing) state.cart[editIndex] = line; else state.cart.push(line);
        saveCart();
        closeModal();
        renderCart();
        pulseCartButton();
      }
    });
    modal.addEventListener('click', (e) => { if (e.target.hasAttribute('data-close')) closeModal(); });
    update();
    setTimeout(() => $('.order-modal-close', modal).focus(), 30);
  }

  function closeModal() {
    if (modal) { modal.remove(); modal = null; }
    if (!drawer.classList.contains('is-open')) document.body.classList.remove('order-lock');
  }

  // ---------- cart drawer ----------
  const cartBtn = document.createElement('button');
  cartBtn.type = 'button';
  cartBtn.className = 'order-cart-btn';
  cartBtn.hidden = true;
  document.body.appendChild(cartBtn);

  const drawer = document.createElement('aside');
  drawer.className = 'order-drawer';
  drawer.setAttribute('aria-label', 'Your order');
  drawer.innerHTML = '<div class="order-drawer-backdrop" data-close-drawer></div><div class="order-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="order-drawer-title"></div>';
  document.body.appendChild(drawer);
  const panel = $('.order-drawer-panel', drawer);

  function pulseCartButton() {
    cartBtn.classList.remove('is-pulse');
    void cartBtn.offsetWidth;
    cartBtn.classList.add('is-pulse');
  }

  function openDrawer(view = 'cart') {
    state.view = view;
    drawer.classList.add('is-open');
    document.body.classList.add('order-lock');
    renderCart();
    setTimeout(() => { const f = $('.order-drawer-close', panel); if (f) f.focus(); }, 30);
  }
  function closeDrawer() {
    drawer.classList.remove('is-open');
    if (!modal) document.body.classList.remove('order-lock');
    if (state.view === 'done') state.view = 'cart';
    renderCart(); // bring back (or hide) the "View order" button to match the cart
  }

  cartBtn.addEventListener('click', () => openDrawer('cart'));
  drawer.addEventListener('click', (e) => { if (e.target.hasAttribute('data-close-drawer')) closeDrawer(); });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (modal) closeModal(); else if (drawer.classList.contains('is-open')) closeDrawer();
  });

  function renderCart() {
    const count = cartCount();
    cartBtn.hidden = count === 0 || drawer.classList.contains('is-open');
    cartBtn.innerHTML = `<span class="order-cart-count">${count}</span> View order <strong>${money(subtotal())}</strong>`;
    if (!drawer.classList.contains('is-open')) return;
    if (state.view === 'checkout') return renderCheckout();
    if (state.view === 'done') return; // success view stays until closed

    const s = state.menu.store;
    const lines = state.cart.map((l, i) => ({ l, i, d: describe(l) }));
    const f = state.fulfillment.type;
    const info = openInfo(f);
    panel.innerHTML = `
      <div class="order-drawer-head">
        <h3 id="order-drawer-title">Your Order</h3>
        <button type="button" class="order-drawer-close" data-close-drawer aria-label="Close">&times;</button>
      </div>
      <div class="order-drawer-body">
        <div class="order-toggle" role="radiogroup" aria-label="Pickup or delivery">
          <button type="button" role="radio" aria-checked="${f === 'pickup'}" data-ftype="pickup" class="${f === 'pickup' ? 'is-active' : ''}" ${typeEnabled('pickup') ? '' : 'disabled'}>Pickup</button>
          <button type="button" role="radio" aria-checked="${f === 'delivery'}" data-ftype="delivery" class="${f === 'delivery' ? 'is-active' : ''}" ${s.deliveryEnabled ? '' : 'disabled'}>Delivery</button>
        </div>
        <p class="order-fulfill-note">${f === 'pickup'
          ? `Pickup at ${esc(s.address)} &middot; ready in about ${s.prepMinutes} min`
          : esc(dz().note)}
          <br><span class="${info.open ? 'is-open' : 'is-closed'}">${esc(info.text)}</span></p>
        ${lines.length ? `<ul class="order-lines">${lines.map(({ l, i, d }) => d ? `
          <li class="order-line">
            <div class="order-line-info">
              <button type="button" class="order-line-name" data-edit="${i}">${esc(d.item.name)}</button>
              ${d.item.variations.length > 1 ? `<span>${esc(d.variation.name)}</span>` : ''}
              ${shownMods(d).length ? `<span>${esc(shownMods(d).map((m) => m.name).join(', '))}</span>` : ''}
              ${l.note ? `<span class="order-line-note">&ldquo;${esc(l.note)}&rdquo;</span>` : ''}
              <div class="order-qty order-qty--sm">
                ${l.qty > 1
                  ? `<button type="button" data-line-qty="-1" data-i="${i}" aria-label="Decrease">&minus;</button>`
                  : `<button type="button" data-remove="${i}" aria-label="Remove ${esc(d.item.name)}" title="Remove"><i class="far fa-trash-alt" aria-hidden="true"></i></button>`}
                <span>${l.qty}</span>
                <button type="button" data-line-qty="1" data-i="${i}" aria-label="Increase">+</button>
              </div>
            </div>
            <div class="order-line-side">
              <strong>${money(d.total)}</strong>
              <button type="button" class="order-line-remove" data-remove="${i}">Remove</button>
            </div>
          </li>` : `
          <li class="order-line is-unavailable">
            <div class="order-line-info"><span>This item is no longer available.</span></div>
            <div class="order-line-side"><button type="button" class="order-line-remove" data-remove="${i}">Remove</button></div>
          </li>`).join('')}</ul>` : '<p class="order-empty">Your order is empty. Tap any dish to add it.</p>'}
      </div>
      ${lines.length ? `
        <div class="order-drawer-foot">
          <div class="order-total-row"><span>Subtotal</span><strong>${money(subtotal())}</strong></div>
          ${f === 'delivery' && subtotal() < s.deliveryMinimum ? `<p class="order-warn">Add ${money(s.deliveryMinimum - subtotal())} more for delivery.</p>` : ''}
          ${deliveryBlocked().length ? `<p class="order-warn">${esc(deliveryBlocked().join(', '))} ${deliveryBlocked().length > 1 ? 'are' : 'is'} pickup only. Switch to Pickup, or remove to order for delivery.</p>` : ''}
          ${pickupBlocked().length ? `<p class="order-warn">${esc(pickupBlocked().join(', '))} ${pickupBlocked().length > 1 ? 'are' : 'is'} delivery only. Switch to Delivery, or remove to order for pickup.</p>` : ''}
          <button type="button" class="btn-primary order-go" data-go-checkout ${(!info.open || (f === 'delivery' && subtotal() < s.deliveryMinimum) || !validLines().length || deliveryBlocked().length || pickupBlocked().length) ? 'disabled' : ''}>Checkout</button>
        </div>` : ''}`;
  }

  panel.addEventListener('click', (e) => {
    const t = e.target;
    const ftype = t.closest('[data-ftype]');
    if (ftype && !ftype.disabled) { state.fulfillment.type = ftype.dataset.ftype; state.quote = null; state.payKey = null; renderCart(); if (state.view === 'checkout') requestQuote(); return; }
    if (t.closest('[data-line-qty]')) {
      const b = t.closest('[data-line-qty]');
      const l = state.cart[+b.dataset.i];
      const max = (item(l.itemId) || {}).maxQty || 50;
      l.qty = Math.min(max, Math.max(1, l.qty + Number(b.dataset.lineQty)));
      saveCart(); renderCart(); return;
    }
    if (t.closest('[data-remove]')) { state.cart.splice(+t.closest('[data-remove]').dataset.remove, 1); saveCart(); renderCart(); return; }
    if (t.closest('[data-edit]')) { const i = +t.closest('[data-edit]').dataset.edit; openItem(state.cart[i].itemId, i); return; }
    if (t.closest('[data-go-checkout]')) { state.view = 'checkout'; state.quote = null; renderCart(); requestQuote(); return; }
    if (t.closest('[data-back]')) { state.view = 'cart'; renderCart(); return; }
    if (t.closest('[data-tip]')) {
      const v = t.closest('[data-tip]').dataset.tip;
      state.tipPercent = v === 'custom' ? 'custom' : Number(v);
      renderTotals(); markTips();
      if (v === 'custom') { const inp = $('#order-tip-custom', panel); if (inp) inp.focus(); }
      return;
    }
    if (t.closest('[data-pay]')) { placeOrder(); return; }
    if (t.closest('[data-done]')) { closeDrawer(); }
  });

  // ---------- checkout ----------
  function field(id, label, value, attrs = '') {
    return `<label class="order-field"><span>${label}</span><input id="${id}" value="${esc(value)}" ${attrs}></label>`;
  }

  function renderCheckout() {
    const s = state.menu.store;
    const f = state.fulfillment;
    const a = f.address;
    const c = state.customer;
    const payments = state.menu.paymentsEnabled;
    const cod = state.menu.codMode;
    panel.innerHTML = `
      <div class="order-drawer-head">
        <button type="button" class="order-back" data-back>&larr; Order</button>
        <h3 id="order-drawer-title">Checkout</h3>
        <button type="button" class="order-drawer-close" data-close-drawer aria-label="Close">&times;</button>
      </div>
      <form class="order-drawer-body order-checkout" novalidate>
        <div class="order-toggle" role="radiogroup" aria-label="Pickup or delivery">
          <button type="button" role="radio" aria-checked="${f.type === 'pickup'}" data-ftype="pickup" class="${f.type === 'pickup' ? 'is-active' : ''}" ${typeEnabled('pickup') ? '' : 'disabled'}>Pickup</button>
          <button type="button" role="radio" aria-checked="${f.type === 'delivery'}" data-ftype="delivery" class="${f.type === 'delivery' ? 'is-active' : ''}" ${s.deliveryEnabled ? '' : 'disabled'}>Delivery</button>
        </div>
        <p class="order-fulfill-note">${f.type === 'pickup' ? `Pickup ASAP (about ${s.prepMinutes} min) at ${esc(s.address)}` : `Delivery ASAP &middot; ${esc(dz().summary)}`}</p>

        <h4>Contact</h4>
        ${field('order-name', 'Full name', c.name, 'autocomplete="name" required')}
        <div class="order-field-row">
          ${field('order-phone', 'Phone', c.phone, 'type="tel" autocomplete="tel" required')}
          ${field('order-email', cod ? 'Email (optional)' : 'Email', c.email, 'type="email" autocomplete="email"' + (cod ? '' : ' required'))}
        </div>

        ${f.type === 'delivery' ? `
          <h4>Delivery address</h4>
          ${field('order-line1', 'Street address', a.line1, 'autocomplete="address-line1" required')}
          ${field('order-line2', 'Apt, suite (optional)', a.line2, 'autocomplete="address-line2"')}
          <div class="order-field-row">
            ${field('order-city', 'City', a.city, 'autocomplete="address-level2" required')}
            ${dz().postalUsed ? field('order-zip', dz().postalLabel + (dz().postalRequired ? '' : ' (optional)'), a.zip,
              `inputmode="${dz().postalNumeric ? 'numeric' : 'text'}" maxlength="12" autocomplete="postal-code"${dz().postalRequired ? ' required' : ''}`) : ''}
          </div>
          ${field('order-instr', 'Delivery instructions (optional)', a.instructions, 'maxlength="200"')}` : ''}

        ${payments ? `
        <h4>Add a tip</h4>
        <div class="order-tips">
          ${TIP_OPTIONS.map((p) => `<button type="button" data-tip="${p}">${p ? p + '%' : 'No tip'}</button>`).join('')}
          <button type="button" data-tip="custom">Custom</button>
        </div>
        <label class="order-field order-tip-custom" ${state.tipPercent === 'custom' ? '' : 'hidden'}>
          <span>Tip amount ($)</span><input id="order-tip-custom" inputmode="decimal" value="${esc(state.tipCustom)}">
        </label>` : ''}

        <div class="order-summary"></div>

        ${payments ? `
          <h4>Payment</h4>
          <div id="order-card" class="order-card-field"></div>
          <p class="order-secure">Payments are processed securely by Square. We never see your card number.</p>`
        : (cod ? `
          <div class="order-cod">
            <strong>&#128181; Cash on delivery</strong>
            <p>No online payment needed &mdash; pay with cash when your order ${f.type === 'delivery' ? 'is delivered' : 'is picked up'}. Sales tax is added at the restaurant.</p>
          </div>`
        : `
          <div class="order-setup">
            <strong>Online payment is being set up.</strong>
            <p>You can still call in this order &mdash; we&rsquo;ll have it ready.</p>
            <a class="btn-outline" href="tel:${esc(s.phone.replace(/\D/g, ''))}">Call ${esc(s.phone)}</a>
          </div>`)}
        <p class="order-error" role="alert" hidden></p>
      </form>
      <div class="order-drawer-foot">
        <button type="button" class="btn-primary order-go" data-pay ${(payments || cod) ? '' : 'disabled'}>Place order</button>
      </div>`;

    const form = $('.order-checkout', panel);
    form.addEventListener('input', (e) => {
      const id = e.target.id;
      const map = { 'order-name': ['customer', 'name'], 'order-phone': ['customer', 'phone'], 'order-email': ['customer', 'email'], 'order-line1': ['addr', 'line1'], 'order-line2': ['addr', 'line2'], 'order-city': ['addr', 'city'], 'order-zip': ['addr', 'zip'], 'order-instr': ['addr', 'instructions'] };
      if (map[id]) {
        const [scope, key] = map[id];
        (scope === 'customer' ? state.customer : state.fulfillment.address)[key] = e.target.value;
        if (id === 'order-zip' && e.target.value.length === 5) { state.payKey = null; requestQuote(); }
      }
      if (id === 'order-tip-custom') { state.tipCustom = e.target.value; renderTotals(); }
    });
    form.addEventListener('submit', (e) => { e.preventDefault(); placeOrder(); });
    markTips();
    renderTotals();
    if (payments) mountCard();
  }

  function markTips() {
    panel.querySelectorAll('[data-tip]').forEach((b) => b.classList.toggle('is-active', String(state.tipPercent) === b.dataset.tip));
    const custom = $('.order-tip-custom', panel);
    if (custom) custom.hidden = state.tipPercent !== 'custom';
  }

  function renderTotals() {
    const box = $('.order-summary', panel);
    if (!box) return;
    const cod = state.menu.codMode;
    const blocked = deliveryBlocked();
    const blockedPickup = pickupBlocked();
    const q = state.quote && state.quote.totals;
    const sub = q ? q.subtotal : subtotal();
    const tip = state.menu.paymentsEnabled ? tipAmount() : 0;
    const fee = q ? q.deliveryFee : 0;
    const tax = q ? q.tax : null;
    const total = (q ? q.total : sub + fee) + tip;
    box.innerHTML = `
      ${validLines().map((l) => { const d = describe(l); return `<div class="order-total-row is-item"><span>${l.qty} &times; ${esc(d.item.name)}</span><span>${money(d.total)}</span></div>`; }).join('')}
      <div class="order-total-row"><span>Subtotal</span><span>${money(sub)}</span></div>
      ${state.fulfillment.type === 'delivery' ? (() => {
        const dq = state.quote && state.quote.delivery;
        const label = dq && dq.zone ? `Delivery &middot; ${esc(dq.zone)}` : 'Delivery';
        const value = dq && dq.pending ? 'Enter your address' : (fee ? money(fee) : 'Free');
        const eta = dq && dq.etaMinutes ? `<p class="order-cod-note">Arrives in about ${dq.etaMinutes} min${dq.freeOver && fee ? ` &middot; free delivery over ${money(dq.freeOver)}` : ''}.</p>` : '';
        return `<div class="order-total-row"><span>${label}</span><span>${value}</span></div>${eta}`;
      })() : ''}
      <div class="order-total-row"><span>Tax</span><span>${tax == null ? (state.menu.paymentsEnabled ? '&hellip;' : (cod ? 'Added on delivery' : 'Calculated at payment')) : money(tax)}</span></div>
      ${state.menu.paymentsEnabled ? `<div class="order-total-row"><span>Tip</span><span>${money(tip)}</span></div>` : ''}
      <div class="order-total-row is-total"><span>${cod ? 'To pay in cash' : 'Total'}</span><strong>${money(total)}</strong></div>
      ${cod ? `<p class="order-cod-note">Plus sales tax, paid in cash on ${state.fulfillment.type === 'delivery' ? 'delivery' : 'pickup'}.</p>` : ''}
      ${blocked.length ? `<p class="order-warn">${esc(blocked.join(', '))} ${blocked.length > 1 ? 'are' : 'is'} pickup only. Switch to Pickup, or remove to order for delivery.</p>` : ''}
      ${blockedPickup.length ? `<p class="order-warn">${esc(blockedPickup.join(', '))} ${blockedPickup.length > 1 ? 'are' : 'is'} delivery only. Switch to Delivery, or remove to order for pickup.</p>` : ''}
      ${state.quoteError ? `<p class="order-warn">${esc(state.quoteError)}</p>` : ''}`;
    const pay = $('[data-pay]', panel);
    if (pay) {
      if (cod) {
        pay.textContent = state.placing ? 'Placing order…' : 'Place order';
        pay.disabled = state.placing || !validLines().length || !!state.quoteError || blocked.length > 0 || blockedPickup.length > 0;
      } else if (state.menu.paymentsEnabled) {
        pay.textContent = state.placing ? 'Placing order…' : `Pay ${money(total)}`;
        pay.disabled = state.placing || !q || !!state.quoteError || blocked.length > 0 || blockedPickup.length > 0;
      }
    }
  }

  let quoteTimer = null;
  function requestQuote() {
    clearTimeout(quoteTimer);
    quoteTimer = setTimeout(async () => {
      state.quoteError = '';
      if (state.menu.offline) { renderTotals(); return; }
      try {
        const res = await post({ action: 'quote', cart: payloadCart(), fulfillment: payloadFulfillment() });
        state.quote = res.ok ? res : null;
        state.quoteError = res.ok ? '' : res.error;
      } catch (e) {
        state.quote = null;
        state.quoteError = 'Could not reach the ordering system. Please try again.';
      }
      renderTotals();
    }, 250);
  }

  const payloadCart = () => validLines().map(({ itemId, variationId, qty, modifiers, note }) => ({ itemId, variationId, qty, modifiers, note }));
  const payloadFulfillment = () => ({ type: state.fulfillment.type, address: state.fulfillment.type === 'delivery' ? state.fulfillment.address : undefined });

  async function post(body) {
    const r = await fetch('api/checkout.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    return r.json();
  }

  // Square Web Payments SDK (card field hosted by Square).
  function loadSquare() {
    if (window.Square) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = state.menu.environment === 'production' ? 'https://web.squarecdn.com/v1/square.js' : 'https://sandbox.web.squarecdn.com/v1/square.js';
      s.onload = resolve;
      s.onerror = () => reject(new Error('Could not load the secure payment form.'));
      document.head.appendChild(s);
    });
  }

  async function mountCard() {
    try {
      await loadSquare();
      if (!state.square) state.square = window.Square.payments(state.menu.applicationId, state.menu.locationId);
      if (state.card) { try { await state.card.destroy(); } catch (e) { /* already gone */ } }
      state.card = await state.square.card({
        style: {
          input: { color: '#FFFFFF', backgroundColor: '#141414', fontSize: '15px' },
          'input::placeholder': { color: '#888888' },
          '.input-container': { borderColor: '#333333', borderRadius: '0px' },
          '.input-container.is-focus': { borderColor: '#C8A84E' },
        },
      });
      await state.card.attach('#order-card');
    } catch (e) {
      showError(e.message || 'The secure payment form could not be loaded.');
    }
  }

  function showError(msg) {
    const el = $('.order-error', panel);
    if (!el) return;
    el.textContent = msg;
    el.hidden = !msg;
    if (msg) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function validateCheckout() {
    const c = state.customer;
    if (!c.name.trim()) return 'Please enter your name.';
    if (c.phone.replace(/\D/g, '').length < 7) return 'Please enter a valid phone number.';
    const email = c.email.trim();
    const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    if (state.menu.codMode) { if (email && !emailOk) return 'Please enter a valid email address, or leave it blank.'; }
    else if (!emailOk) return 'Please enter a valid email address.';
    if (state.fulfillment.type === 'delivery') {
      const a = state.fulfillment.address;
      if (!a.line1.trim() || !a.city.trim() || (dz().postalRequired && !a.zip.trim())) return 'Please complete your delivery address.';
    }
    if (state.tipPercent === 'custom' && isNaN(parseFloat(state.tipCustom || '0'))) return 'Please enter a valid tip amount.';
    return '';
  }

  async function placeOrder() {
    if (state.placing) return;
    if (!state.menu.codMode && !state.menu.paymentsEnabled) return;
    const problem = validateCheckout();
    if (problem) return showError(problem);
    showError('');
    const customer = { name: state.customer.name.trim(), phone: state.customer.phone.trim(), email: state.customer.email.trim() };
    state.placing = true;
    renderTotals();
    try {
      // Cash on delivery: just record the order, no card.
      if (state.menu.codMode) {
        const res = await post({ action: 'place', cart: payloadCart(), fulfillment: payloadFulfillment(), customer });
        if (!res.ok) throw new Error(res.error || 'Your order could not be placed. Please try again.');
        showDone(res);
        return;
      }
      if (!state.card) { state.placing = false; renderTotals(); return showError('The payment form is still loading.'); }
      const tok = await state.card.tokenize();
      if (tok.status !== 'OK') throw new Error((tok.errors && tok.errors[0] && tok.errors[0].message) || 'Please check your card details.');
      state.payKey = state.payKey || uid(); // same key on retry = no double charge
      const res = await post({
        action: 'pay',
        cart: payloadCart(),
        fulfillment: payloadFulfillment(),
        customer,
        tip: tipAmount(),
        sourceId: tok.token,
        idempotencyKey: state.payKey,
      });
      if (!res.ok) throw new Error(res.error || 'Payment could not be completed.');
      showDone(res);
    } catch (e) {
      state.placing = false;
      renderTotals();
      showError(e.message);
    }
  }

  function showDone(res) {
    const s = state.menu.store;
    state.placing = false;
    state.cart = [];
    saveCart();
    state.view = 'done';
    state.quote = null;
    panel.innerHTML = `
      <div class="order-drawer-head">
        <h3 id="order-drawer-title">Order placed!</h3>
        <button type="button" class="order-drawer-close" data-close-drawer aria-label="Close">&times;</button>
      </div>
      <div class="order-drawer-body order-done">
        <div class="order-done-check" aria-hidden="true">&#10003;</div>
        <p class="order-done-num">Order #${esc(res.orderNumber)}</p>
        <p>We&rsquo;ve received your order${res.codMode ? ` and called it in to the kitchen${state.customer.email.trim() ? ', and emailed you a copy' : ''}.` : ` and sent a receipt to ${esc(state.customer.email)}.`}</p>
        <p><strong>${res.fulfillment === 'delivery' ? `Estimated delivery in about ${res.readyInMinutes + 20} minutes.` : `Ready for pickup in about ${res.readyInMinutes} minutes at ${esc(s.address)}.`}</strong></p>
        ${res.fulfillment === 'pickup' && s.pickupInstructions ? `<p class="order-done-note">${esc(s.pickupInstructions)}</p>` : ''}
        ${res.codMode
          ? `<div class="order-total-row is-total"><span>To pay in cash</span><strong>${money(res.totals.total)}</strong></div><p class="order-done-note">Please have cash ready (plus sales tax) when your order ${res.fulfillment === 'delivery' ? 'is delivered' : 'is picked up'}.</p>`
          : `<div class="order-total-row is-total"><span>Charged</span><strong>${money(res.totals.charged)}</strong></div>${res.receiptUrl ? `<a class="btn-outline" href="${esc(res.receiptUrl)}" target="_blank" rel="noopener">View receipt</a>` : ''}`}
      </div>
      <div class="order-drawer-foot"><button type="button" class="btn-primary order-go" data-done>Done</button></div>`;
    renderCart();
  }

  // ---------- start ----------
  (async () => {
    try {
      state.menu = await loadMenu();
    } catch (e) {
      root.innerHTML = `<p class="order-loading">Our online menu is temporarily unavailable. Please call ${esc('(954) 565-2526')} to order.</p>`;
      return;
    }
    state.cart = loadCart();
    // Start on whichever of pickup / delivery is switched on.
    if (!typeEnabled(state.fulfillment.type) && typeEnabled('delivery')) state.fulfillment.type = 'delivery';
    renderMenu();
    renderCart();
    setInterval(renderStatus, 60000);

    // The chat assistant (js/chat.js) adds dishes here and opens the cart.
    // Lines are { itemId, variationId, qty, modifiers, note }; checkout re-prices them.
    window.rmsOrder = {
      add(lines) {
        lines.forEach((l) => state.cart.push({ key: uid(), itemId: l.itemId, variationId: l.variationId, qty: l.qty, modifiers: l.modifiers || [], note: l.note || '' }));
        saveCart();
        renderCart();
        pulseCartButton();
      },
      open() { openDrawer('cart'); },
    };
    // Arriving from the chat's "Review & pay" on another page.
    if (location.hash === '#order' && state.cart.length) openDrawer('cart');
  })();
})();
