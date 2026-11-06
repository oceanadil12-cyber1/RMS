/* RMS admin panel: small helpers, no libraries. Every page works without this
   file; it adds hover text on charts, "are you sure?" prompts, item filtering,
   print, and live badges for new orders / reservations / messages. */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  // ----- Hover text for chart columns and bars (their title="" becomes a tooltip; also on keyboard focus) -----
  const tip = $('#tip');
  if (tip) {
    const show = (el) => {
      tip.textContent = el.dataset.tip; // text only, never HTML
      tip.hidden = false;
      const r = el.getBoundingClientRect();
      const t = tip.getBoundingClientRect();
      tip.style.left = Math.max(8, Math.min(innerWidth - t.width - 8, r.left + r.width / 2 - t.width / 2)) + 'px';
      tip.style.top = (r.top - t.height - 8 < 8 ? r.bottom + 8 : r.top - t.height - 8) + 'px';
    };
    $$('.chart [title], .bars [title]').forEach((el) => {
      el.dataset.tip = el.getAttribute('title');
      el.removeAttribute('title');
      el.addEventListener('pointerenter', () => show(el));
      el.addEventListener('focus', () => show(el));
      ['pointerleave', 'blur'].forEach((ev) => el.addEventListener(ev, () => { tip.hidden = true; }));
    });
  }

  // ----- "Are you sure?" on buttons marked data-confirm -----
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-confirm]');
    if (el && !window.confirm(el.dataset.confirm)) { e.preventDefault(); e.stopPropagation(); }
  }, true);

  // ----- Print button -----
  document.addEventListener('click', (e) => { if (e.target.closest('[data-print]')) window.print(); });

  // ----- Filter a table as you type (the menu list) -----
  $$('input[data-filter]').forEach((input) => {
    const table = $(input.dataset.filter);
    if (!table) return;
    const counter = $('[data-filter-count="' + input.dataset.filter + '"]');
    const total = $$('tbody tr[data-name]', table).length;
    const original = counter ? counter.textContent : '';
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      let shown = 0;
      let cat = null;
      let catHas = false;
      const flush = () => { if (cat) cat.hidden = !catHas; };
      $$('tbody tr', table).forEach((tr) => {
        if (tr.classList.contains('cat')) { flush(); cat = tr; catHas = false; return; }
        const hit = !q || (tr.dataset.name || '').includes(q);
        tr.hidden = !hit;
        if (hit) { shown++; catHas = true; }
      });
      flush();
      if (counter) counter.textContent = q ? shown + ' of ' + total + ' items shown' : original;
    });
  });

  // ----- Tables turn into stacked cards on phones and tablets (.tbl.stacked in admin.css) -----
  // Each cell gets its column's title, shown as the label on its line of the card.
  $$('table.tbl:not(.items):not(.hours)').forEach((table) => {
    const heads = $$('thead th', table).map((th) => th.textContent.trim());
    if (!heads.length) return;
    $$('tbody tr', table).forEach((tr) => {
      if (tr.classList.contains('cat')) return; // category heading rows span the table
      let col = 0;
      Array.from(tr.children).forEach((td) => { td.dataset.label = heads[col] || ''; col += td.colSpan || 1; });
    });
    table.classList.add('stacked');
    const wrap = table.closest('.table');
    if (wrap) wrap.classList.add('stacked');
  });

  // ----- Character counter under a text box -----
  $$('textarea[data-counter]').forEach((ta) => {
    const out = $(ta.dataset.counter);
    if (!out) return;
    const limit = +ta.dataset.counterLimit || 0;
    const update = () => {
      const bytes = new Blob([ta.value]).size;
      out.textContent = ta.value.length.toLocaleString() + ' characters · about ' + Math.ceil(ta.value.length / 4).toLocaleString() + ' tokens'
        + (limit && bytes > limit ? ' · too long' : '');
      out.style.color = limit && bytes > limit ? 'var(--crit)' : '';
    };
    ta.addEventListener('input', update);
    update();
  });

  // ----- Phone menu: Escape closes it, and the burger works from the keyboard -----
  const toggle = $('#navtoggle');
  const burger = $('.burger');
  if (toggle && burger) {
    burger.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle.checked = !toggle.checked; }
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') toggle.checked = false; });
  }

  // ----- Smooth page transitions -----
  const mainEl = $('.main');
  if (mainEl) {
    mainEl.classList.add('entering');
    mainEl.addEventListener('animationend', () => mainEl.classList.remove('entering'), { once: true });

    document.addEventListener('click', (e) => {
      const link = e.target.closest('a.nav-link, .side-brand');
      if (!link || !link.href || link.target === '_blank' || e.ctrlKey || e.metaKey || e.shiftKey) return;
      if (link.classList.contains('on')) return;
      e.preventDefault();
      const href = link.href;

      // Highlight the clicked nav link immediately.
      $$('.nav-link.on').forEach((el) => el.classList.remove('on'));
      if (link.classList.contains('nav-link')) link.classList.add('on');

      // Close mobile menu if open.
      if (toggle) toggle.checked = false;

      mainEl.classList.add('leaving');
      setTimeout(() => { window.location.href = href; }, 150);
    });
  }

  // ----- Info tooltip bubbles (fixed-position, escapes overflow:hidden cards) -----
  const bubble = document.createElement('div');
  bubble.className = 'itip-bubble';
  document.body.appendChild(bubble);
  let bubbleTarget = null;
  // Capture-phase pointer events also fire for the document itself, which has no closest().
  const itipOf = (target) => (target instanceof Element ? target.closest('.itip') : null);
  const hideTip = () => {
    if (bubbleTarget) bubbleTarget.setAttribute('aria-expanded', 'false');
    bubble.classList.remove('on');
    bubbleTarget = null;
  };
  const showTip = (el) => {
    if (bubbleTarget && bubbleTarget !== el) bubbleTarget.setAttribute('aria-expanded', 'false');
    bubbleTarget = el;
    el.setAttribute('aria-expanded', 'true');
    bubble.textContent = el.dataset.info;
    bubble.classList.add('on');
    const r = el.getBoundingClientRect();
    bubble.style.left = '-9999px';
    bubble.style.top = '0';
    const bw = bubble.offsetWidth;
    const bh = bubble.offsetHeight;
    let left = r.left + r.width / 2 - bw / 2;
    left = Math.max(8, Math.min(window.innerWidth - bw - 8, left));
    let top = r.top - bh - 8;
    if (top < 4) top = r.bottom + 8;
    bubble.style.left = left + 'px';
    bubble.style.top = top + 'px';
    const arrowLeft = r.left + r.width / 2 - left;
    bubble.style.setProperty('--arrow', arrowLeft + 'px');
  };
  // Mouse: hover. Touch screens and keyboards: tap or focus, then tap anywhere / Escape to close.
  document.addEventListener('pointerenter', (e) => {
    const el = itipOf(e.target);
    if (el && el.dataset.info && e.pointerType === 'mouse') showTip(el);
  }, true);
  document.addEventListener('pointerleave', (e) => {
    if (e.pointerType === 'mouse' && bubbleTarget && itipOf(e.target) === bubbleTarget) hideTip();
  }, true);
  document.addEventListener('click', (e) => {
    const el = itipOf(e.target);
    if (el && el.dataset.info) {
      e.preventDefault(); // inside a <label>: don't tick its checkbox
      if (bubbleTarget === el && e.pointerType !== 'mouse') hideTip(); else showTip(el);
    } else if (bubbleTarget) hideTip();
  });
  document.addEventListener('focusin', (e) => { const el = itipOf(e.target); if (el && el.dataset.info && el.matches(':focus-visible')) showTip(el); }); // keyboard only; taps go through click
  document.addEventListener('focusout', (e) => { if (itipOf(e.target) === bubbleTarget) hideTip(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && bubbleTarget) hideTip(); });
  window.addEventListener('scroll', () => { if (bubbleTarget) hideTip(); }, { passive: true, capture: true });

  // ----- Toasts -----
  const toasts = $('#toasts');
  const toast = (message, href, linkText) => {
    if (!toasts) return;
    const box = document.createElement('div');
    box.className = 'toast';
    const text = document.createElement('span');
    text.textContent = message;
    box.append(text);
    if (href) {
      const a = document.createElement('a');
      a.href = href;
      a.textContent = linkText || 'Open';
      box.append(a);
    }
    toasts.append(box);
    setTimeout(() => box.remove(), 14000);
  };

  // ----- Live badges: ask every 30 seconds whether anything new came in -----
  const url = document.body.dataset.countsUrl;
  if (url) {
    const kinds = { orders: ['New order received', '?page=orders&s=new'], reservations: ['New reservation request', '?page=reservations&view=pending'], messages: ['New message', '?page=messages&view=new'], 'waiter-assistance': ['Waiter assistance requested', '?page=waiter-assistance'] };
    const baseTitle = document.body.dataset.title || document.title;
    const last = {};
    Object.keys(kinds).forEach((k) => { const b = $('[data-badge="' + k + '"]'); last[k] = b ? +b.textContent || 0 : 0; });
    let timer = null;
    const apply = (c) => {
      let total = 0;
      Object.keys(kinds).forEach((k) => {
        const n = +c[k] || 0;
        total += n;
        $$('[data-badge="' + k + '"]').forEach((b) => { b.textContent = n; b.hidden = n === 0; });
        if (n > last[k]) toast(kinds[k][0], kinds[k][1], 'View');
        last[k] = n;
      });
      document.title = (total ? '(' + total + ') ' : '') + baseTitle;
    };
    const tick = () => fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => (r.status === 401 ? Promise.reject('out') : r.json()))
      .then(apply)
      .catch((err) => {
        if (err === 'out') { toast('You were signed out. Reload the page to sign in again.'); clearInterval(timer); }
      });
    timer = setInterval(() => { if (!document.hidden) tick(); }, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
  }

  // ----- Notification bell & dropdown -----
  const notifUrl = document.body.dataset.notifUrl;
  const bell = $('#notif-bell');
  const panel = $('#notif-panel');
  const dot = $('#notif-dot');
  const body = $('#notif-body');
  if (notifUrl && bell && panel && body) {
    const typeLabels = {
      'order-not-started': 'Order alert',
      'order-late-prep': 'Kitchen alert',
      'order-ready-idle-pickup': 'Pickup alert',
      'order-ready-idle-delivery': 'Delivery alert',
      'order-ready-idle-dinein': 'Dine-in alert',
      'event-soon': 'Event',
      'reservation-pending': 'Reservation',
      'waiter-call': 'Waiter call',
    };
    const typeIcons = {
      'order-not-started': 'clock',
      'order-late-prep': 'alert',
      'order-ready-idle-pickup': 'bag',
      'order-ready-idle-delivery': 'truck',
      'order-ready-idle-dinein': 'edit',
      'event-soon': 'music',
      'reservation-pending': 'calendar',
      'waiter-call': 'bell',
    };
    const iconSvg = (name, size) => {
      const paths = {
        clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        alert: '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
        bag: '<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        truck: '<path d="M1 3h15v13H1zM16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        edit: '<path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
        music: '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        bell: '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
      };
      return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (paths[name] || '') + '</svg>';
    };

    let lastAlertKeys = new Set();
    let notifTimer = null;

    const esc = (s) => { const d = document.createElement('span'); d.textContent = s; return d.innerHTML; };

    const renderAlerts = (alerts) => {
      if (!alerts || !alerts.length) {
        body.innerHTML = '<p class="notif-empty">No alerts right now.</p>';
        dot.hidden = true;
        bell.classList.remove('has-alerts');
        lastAlertKeys.clear();
        return;
      }
      dot.hidden = false;
      bell.classList.add('has-alerts');

      // Toast for new alerts.
      const currentKeys = new Set(alerts.map((a) => a.type + ':' + a.message));
      for (const a of alerts) {
        const key = a.type + ':' + a.message;
        if (!lastAlertKeys.has(key) && lastAlertKeys.size > 0) {
          toast(a.message, a.href, 'View');
        }
      }
      lastAlertKeys = currentKeys;

      body.innerHTML = alerts.map((a) => {
        const icon = typeIcons[a.type] || 'alert';
        const label = typeLabels[a.type] || a.type;
        return '<a class="notif-item" href="' + esc(a.href) + '">'
          + '<span class="notif-icon tone-' + esc(a.tone) + '">' + iconSvg(icon, 16) + '</span>'
          + '<span class="notif-msg">' + esc(a.message) + '<span class="notif-type">' + esc(label) + '</span></span>'
          + '</a>';
      }).join('');
    };

    const fetchNotifs = () => fetch(notifUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => (r.status === 401 ? Promise.reject('out') : r.json()))
      .then((d) => { if (d.ok) renderAlerts(d.alerts); })
      .catch((err) => {
        if (err === 'out' && notifTimer) clearInterval(notifTimer);
      });

    // Toggle panel.
    bell.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = panel.hidden;
      panel.hidden = !open;
      bell.setAttribute('aria-expanded', open);
      if (open) fetchNotifs();
    });
    $('#notif-close').addEventListener('click', () => { panel.hidden = true; bell.setAttribute('aria-expanded', 'false'); });
    document.addEventListener('click', (e) => {
      if (!panel.hidden && !e.target.closest('#notif-wrap')) { panel.hidden = true; bell.setAttribute('aria-expanded', 'false'); }
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !panel.hidden) { panel.hidden = true; bell.setAttribute('aria-expanded', 'false'); }
    });

    // Poll alongside the badge counter.
    fetchNotifs();
    notifTimer = setInterval(() => { if (!document.hidden) fetchNotifs(); }, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) fetchNotifs(); });
  }
})();

/* Store settings → Website sections: keep the Enabled/Disabled label next to each switch in step. */
document.addEventListener('change', (e) => {
  const input = e.target.closest('.section-switch input[type="checkbox"]');
  if (!input) return;
  const state = input.closest('.section-switch').querySelector('[data-state]');
  if (state) state.textContent = input.checked ? 'Enabled' : 'Disabled';
});
