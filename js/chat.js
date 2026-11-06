/* Chat assistant: a gold "Ask us" button on every page that opens a chat panel.
 * On a page with a [data-chat-embed] box (the home page's "Ask RMS"
 * section) the panel sits in that box instead, and [data-ask] buttons send
 * their question to it.
 *
 * Questions go to api/chat.php, which answers from the bar's own information
 * (see data/chatbot-knowledge.md). Replies can carry cards (shows, party
 * packages, food added to the order) and follow-up suggestions; "Talk to a
 * person" shows the team's phone and hours without asking the AI.
 * The conversation is kept for the browser tab (sessionStorage), so it carries
 * on when the guest follows a link to another page.
 *
 * Its wording, button, contact form and opening are set in Admin → Chat settings
 * (api/chat-settings.php), which is read before anything is shown. */
(async function () {
  const script = document.currentScript;
  const SITE = new URL('../', script.src);               // site root, wherever this page lives
  const ENDPOINT = new URL('api/chat.php', SITE).href;
  const STORE = 'rms-chat-v1';
  const MAX_KEEP = 30;                                   // messages remembered per tab

  // Admin → Chat settings. If they can't be read, these defaults are what the chat always did.
  const CFG = Object.assign({
    enabled: true, position: 'right', button: 'Ask us', autoOpen: 'smart', autoDelay: 3,
    bubble: 'Hi! Need a table or food? Ask me.', title: 'Ask RMS', status: 'Online now · virtual assistant',
    welcome: "I'm the RMS virtual assistant. I can help you order food, check tonight's shows, find Happy Hour deals, book a table or plan a private event.",
    logo: '', gate: 'email', gatePhone: 'off',
    gateText: "Before we start, what's your email? We'll use it to follow up if you'd like. Your name is optional.",
    suggestions: ['Show me the menu', "What's on tonight?", 'What are the Happy Hour deals?', 'How do I book a table?'],
    phone: '(954) 565-2526', hours: 'Open every day, 11 AM to 2 AM', notice: '', privacyUrl: '',
  }, await fetch(new URL('api/chat-settings.php', SITE).href).then((r) => (r.ok ? r.json() : {})).catch(() => ({})));
  if (!CFG.enabled) return; // switched off in the admin: no button, no chat

  const LOGO = new URL(CFG.logo || 'images/apple-touch-icon.png', SITE).href;
  const PHONE = CFG.phone;
  const PHONE_DIGITS = PHONE.replace(/\D/g, '');
  const PHONE_TEL = 'tel:' + (PHONE_DIGITS.length === 10 ? '+1' + PHONE_DIGITS : (PHONE.trim().startsWith('+') ? '+' : '') + PHONE_DIGITS);
  const GREETING = CFG.welcome;
  const HUMAN_RE = /\b(talk|speak|chat)\s+(to|with)\s+(a\s+|an\s+|the\s+|someone|somebody)?(human|person|real person|staff|manager|team|someone|somebody)\b|\breal person\b/i;
  const HUMAN_MSG = 'Of course! Our team is happy to help. Call us at ' + PHONE
    + (CFG.hours ? ' (' + CFG.hours.charAt(0).toLowerCase() + CFG.hours.slice(1) + ')' : '')
    + ", or send a message and we'll get back to you.";
  const MENU_RE = /\b(show|see|browse|view|open)\s+(me\s+)?(the\s+|your\s+|full\s+|complete\s+|entire\s+)?(food\s+)?menu\b|\b(full|complete|entire)\s+menu\b|\bbrowse\s+(the\s+)?(food|menu)\b/i;
  // Before the first message the guest gives an email (required, or optional with a
  // Skip button, or not asked at all: Admin → Chat settings), a name (optional) and,
  // if set, a phone number. Kept for the browser tab and sent with each message.
  const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  const GATE = CFG.gate;           // 'email' | 'optional' | 'off'
  const GATE_PHONE = CFG.gatePhone; // 'off' | 'optional' | 'required'
  const GATE_INTRO = CFG.gateText;
  const CHIP_ICONS = { 'Show me the menu': '\u{1F4CB}', "What's on tonight?": '\u{1F3AD}', 'What are the Happy Hour deals?': '\u{1F379}', 'How do I book a table?': '\u{1F4C5}' };
  const SUGGESTIONS = (CFG.suggestions || []).map((text) => ({ icon: CHIP_ICONS[text] || '\u{1F4AC}', label: text, text: text }));
  // Settings are typed by the team, so anything placed into markup is escaped.
  const escHtml = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const ICONS = {
    chat: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.8 7L4 20.5l1.5-4.9A8 8 0 1 1 21 12z"/></svg>',
    close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    restart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>',
    person: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="14" width="4" height="6" rx="1.5"/><rect x="17" y="14" width="4" height="6" rx="1.5"/></svg>',
    send: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>',
  };

  // ---------- saved state ----------

  function load() {
    try { return JSON.parse(sessionStorage.getItem(STORE)) || { open: false, messages: [] }; } catch (e) { return { open: false, messages: [] }; }
  }
  function save() {
    state.messages = state.messages.slice(-MAX_KEEP);
    try { sessionStorage.setItem(STORE, JSON.stringify(state)); } catch (e) { /* storage unavailable */ }
  }
  // Anonymous id that groups one conversation in the chat history (admin/?page=chats).
  const newCid = () => 'c-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
  const state = load();
  if (!state.cid) state.cid = newCid();

  // ---------- markup ----------

  const embedHost = document.querySelector('[data-chat-embed]');
  const embedded = !!embedHost;

  const launcher = document.createElement('button');
  launcher.type = 'button';
  launcher.className = 'chat-launcher';
  launcher.setAttribute('aria-controls', 'chat-panel');
  launcher.setAttribute('aria-expanded', 'false');
  launcher.setAttribute('aria-label', 'Chat with the RMS assistant');
  launcher.innerHTML = ICONS.chat + '<span>' + escHtml(CFG.button) + '</span>';
  const LEFT = CFG.position === 'left';
  if (LEFT) launcher.classList.add('is-left');
  launcher.hidden = CFG.position === 'hidden'; // no floating button: the chat opens by itself or from [data-ask] buttons

  const panel = document.createElement('section');
  panel.className = 'chat-panel' + (embedded ? ' is-embedded' : '') + (LEFT ? ' is-left' : '');
  panel.id = 'chat-panel';
  panel.hidden = !embedded;
  panel.setAttribute('role', embedded ? 'region' : 'dialog');
  panel.setAttribute('aria-label', 'Chat with the RMS assistant');
  panel.innerHTML =
    '<div class="chat-pride" aria-hidden="true"></div>' +
    '<div class="chat-head">' +
      '<span class="chat-avatar-wrap"><img class="chat-avatar" src="' + LOGO + '" alt="" width="42" height="42"><i class="chat-online" aria-hidden="true"></i></span>' +
      '<div class="chat-title"><strong>' + escHtml(CFG.title) + '</strong>' + (CFG.status ? '<span>' + escHtml(CFG.status) + '</span>' : '') + '</div>' +
      '<button type="button" class="chat-icon-btn" data-human aria-label="Talk to a person" title="Talk to a person">' + ICONS.person + '</button>' +
      '<button type="button" class="chat-icon-btn" data-restart aria-label="Start a new chat" title="New chat">' + ICONS.restart + '</button>' +
      (embedded ? '' : '<button type="button" class="chat-icon-btn" data-close aria-label="Close chat" title="Close">' + ICONS.close + '</button>') +
    '</div>' +
    '<div class="chat-body">' +
      '<div class="chat-welcome">' +
        '<img class="chat-welcome-logo" src="' + LOGO + '" alt="" width="64" height="64">' +
        '<h3>Hey there! \u{1F44B}</h3>' +
        '<p>' + escHtml(GREETING) + '</p>' +
        '<div class="chat-chips"></div>' +
        '<form class="chat-gate" novalidate hidden>' +
          '<p class="chat-gate-intro">' + escHtml(GATE_INTRO) + '</p>' +
          '<label class="chat-gate-field"><span>Name <em>(optional)</em></span>' +
            '<input type="text" name="name" autocomplete="name" maxlength="80" placeholder="Your name"></label>' +
          '<label class="chat-gate-field"><span>Email' + (GATE === 'optional' ? ' <em>(optional)</em>' : '') + '</span>' +
            '<input type="email" name="email" inputmode="email" autocomplete="email"' + (GATE === 'email' ? ' required' : '') + ' maxlength="120" placeholder="you@example.com"></label>' +
          (GATE_PHONE !== 'off'
            ? '<label class="chat-gate-field"><span>Phone' + (GATE_PHONE === 'optional' ? ' <em>(optional)</em>' : '') + '</span>' +
                '<input type="tel" name="phone" inputmode="tel" autocomplete="tel"' + (GATE_PHONE === 'required' ? ' required' : '') + ' maxlength="30" placeholder="(954) 555-0100"></label>'
            : '') +
          '<p class="chat-gate-error" role="alert" hidden></p>' +
          '<button type="submit" class="chat-gate-btn">Start chatting</button>' +
          (GATE === 'optional' ? '<button type="button" class="chat-gate-skip" data-skip>Skip</button>' : '') +
        '</form>' +
      '</div>' +
      '<div class="chat-log" role="log" aria-live="polite"></div>' +
    '</div>' +
    '<form class="chat-form">' +
      '<div class="chat-compose">' +
        '<textarea rows="1" maxlength="600" placeholder="Order food, ask about shows, bookings&hellip;" aria-label="Your message"></textarea>' +
        '<button type="submit" class="chat-send" aria-label="Send" disabled>' + ICONS.send + '</button>' +
      '</div>' +
    '</form>' +
    '<p class="chat-foot">' +
      (CFG.notice || CFG.privacyUrl
        ? '<span class="chat-notice">' + escHtml(CFG.notice) +
            (CFG.privacyUrl ? (CFG.notice ? ' ' : '') + '<a href="' + escHtml(CFG.privacyUrl) + '" target="_blank" rel="noopener">Privacy policy</a>' : '') + '</span>'
        : '') +
      'To book or for anything urgent, call <a href="' + escHtml(PHONE_TEL) + '">' + escHtml(PHONE) + '</a>.' +
      '<span class="chat-credit">Chat built by <a href="https://seoberg.com" target="_blank" rel="noopener">SEOBERG</a></span></p>';

  document.body.append(launcher);
  (embedded ? embedHost : document.body).append(panel);
  const body = panel.querySelector('.chat-body');
  const log = panel.querySelector('.chat-log');
  const chips = panel.querySelector('.chat-chips');
  const form = panel.querySelector('.chat-form');
  const input = form.querySelector('textarea');
  const sendBtn = form.querySelector('.chat-send');
  const gate = panel.querySelector('.chat-gate');
  const gateName = gate.querySelector('input[name="name"]');
  const gateEmail = gate.querySelector('input[name="email"]');
  const gatePhone = gate.querySelector('input[name="phone"]'); // null unless asked for in the admin
  const gateError = gate.querySelector('.chat-gate-error');
  let busy = false;
  let pendingQuestion = null; // a question asked before the email was given

  SUGGESTIONS.forEach((s) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.innerHTML = '<span class="chat-chip-icon" aria-hidden="true">' + s.icon + '</span>';
    b.append(s.label);
    b.addEventListener('click', () => send(s.text));
    chips.append(b);
  });

  // ---------- rendering ----------
  // Everything the AI or a guest wrote is added as text nodes, never innerHTML,
  // so replies can't inject markup.

  // Turns site paths (/menu.html#drinks), phone numbers, @rms and web
  // addresses into links.
  const LINKS = /(https?:\/\/[^\s)]+[^\s).,!?])|((?:^|(?<=[\s(]))\/[a-z0-9-]+\.html(?:#[\w-]+)?)|(\(?\b\d{3}\)?[\s.-]?\d{3}[\s.-]\d{4}\b)|(@rms\b)/gi;

  function linkify(el, text) {
    // Markdown bold/italics the AI sometimes adds despite being asked not to.
    text = text.replace(/\*\*(.+?)\*\*/g, '$1').replace(/(^|[\s(])\*([^*\n]+)\*(?=$|[\s).,!?:;])/gm, '$1$2');
    let last = 0;
    text.replace(LINKS, (match, url, path, phone, insta, offset) => {
      el.append(text.slice(last, offset));
      const a = document.createElement('a');
      a.textContent = match;
      if (url) { a.href = url; a.target = '_blank'; a.rel = 'noopener'; }
      else if (path) a.href = new URL(path.slice(1), SITE).href;
      else if (phone) a.href = 'tel:+1' + match.replace(/\D/g, '').slice(-10);
      else { a.href = 'https://www.instagram.com/'; a.target = '_blank'; a.rel = 'noopener'; }
      el.append(a);
      last = offset + match.length;
      return match;
    });
    el.append(text.slice(last));
  }

  function scrollDown() { body.scrollTop = body.scrollHeight; }

  // Small DOM helper: el('a', 'class', 'text', { href: ... }).
  function el(tag, cls, text, attrs) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text) n.textContent = text;
    Object.entries(attrs || {}).forEach(([k, v]) => n.setAttribute(k, v));
    return n;
  }
  const page = (path) => new URL(path, SITE).href;

  // One message: bot replies get the logo beside them. Returns the bubble.
  function row(role, animate) {
    const r = el('div', 'chat-row ' + (role === 'user' ? 'from-user' : 'from-bot') + (animate ? ' is-new' : ''));
    if (role !== 'user') r.append(el('img', 'chat-row-avatar', '', { src: LOGO, alt: '' }));
    const msg = el('div', 'chat-msg');
    r.append(msg);
    log.append(r);
    return msg;
  }

  function bubble(role, text, opts) {
    opts = opts || {};
    const msg = row(role, opts.animate !== false);
    if (opts.error) msg.classList.add('is-error');
    linkify(msg, text);
    scrollDown();
    return msg;
  }

  // A reply and everything that came with it.
  function showReply(m, animate) {
    bubble('assistant', m.content, { animate });
    if (m.cart) orderCard(m.cart, animate);
    (m.cards || []).forEach((c) => {
      if (c.type === 'shows') showCards(c.shows, animate);
      if (c.type === 'packages') packagesCard(c.packages, animate);
      if (c.type === 'menu') menuCards(c.items, animate);
    });
    if (m.menuBrowse) menuBrowseRestore();
    if (m.human) humanCard(animate);
  }

  function render() {
    log.textContent = '';
    state.messages.forEach((m) => (m.role === 'user' ? bubble('user', m.content, { animate: false }) : showReply(m, false)));
    const last = state.messages[state.messages.length - 1];
    if (last && last.role === 'assistant' && last.suggestions) suggestionChips(last.suggestions);
    applyGate();
  }

  // ---------- contact gate ----------

  const phoneOk = (p) => String(p || '').replace(/\D/g, '').length >= 10;

  // Has the guest done what the contact form asks (Admin → Chat settings)?
  function contactOk() {
    if (GATE === 'off') return true;
    const c = state.contact;
    if (!c) return false;
    if (GATE === 'optional') return true; // filled in or skipped
    return EMAIL_RE.test(c.email || '') && (GATE_PHONE !== 'required' || phoneOk(c.phone));
  }

  // Show the email gate until the guest gives one, hiding the chips and the
  // compose box; once they have, show the normal chat.
  function applyGate() {
    const gated = !contactOk();
    panel.classList.toggle('is-gated', gated);
    gate.hidden = !gated;
    form.hidden = gated;
    chips.hidden = gated || state.messages.length > 0;
    if (gated && state.contact) { // keep what they typed if they come back
      if (!gateName.value) gateName.value = state.contact.name || '';
      if (!gateEmail.value) gateEmail.value = state.contact.email || '';
      if (gatePhone && !gatePhone.value) gatePhone.value = state.contact.phone || '';
    }
  }

  // Bring the gate into view and focus it (for a question asked before an email).
  function revealGate() {
    if (!embedded) { if (panel.hidden) setOpen(true, false); }
    else if (!isVisible()) panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    applyGate();
    setTimeout(() => gateName.focus({ preventScroll: true }), embedded ? 300 : 0);
  }

  gate.addEventListener('submit', (e) => {
    e.preventDefault();
    const name = gateName.value.trim().slice(0, 80);
    const email = gateEmail.value.trim().slice(0, 120);
    const phone = gatePhone ? gatePhone.value.trim().slice(0, 30) : '';
    const problem = (GATE === 'email' || email !== '') && !EMAIL_RE.test(email)
      ? [gateEmail, GATE === 'email' ? 'Please enter a valid email so we can follow up.' : 'Please check your email, or leave it blank.']
      : (GATE_PHONE === 'required' || phone !== '') && !phoneOk(phone)
        ? [gatePhone, GATE_PHONE === 'required' ? 'Please enter your phone number so we can call you back.' : 'Please check your phone number, or leave it blank.']
        : null;
    if (problem) {
      gateError.textContent = problem[1];
      gateError.hidden = false;
      problem[0].focus();
      return;
    }
    gateError.hidden = true;
    startChat({ name: name, email: email, phone: phone });
  });

  const skipBtn = gate.querySelector('[data-skip]');
  if (skipBtn) skipBtn.addEventListener('click', () => { gateError.hidden = true; startChat({ name: '', email: '', phone: '', skipped: true }); });

  function startChat(contact) {
    state.contact = contact;
    save();
    applyGate();
    const q = pendingQuestion;
    pendingQuestion = null;
    if (q) send(q);
    else input.focus({ preventScroll: true });
  }

  // ---------- cards ----------

  function card(cls, animate) {
    const c = el('div', 'chat-card ' + cls + (animate ? ' is-new' : ''));
    log.append(c);
    return c;
  }

  // Shows named in a reply: poster, day and time, host, "Reserve a table". Data comes from events.html.
  function showCards(shows, animate) {
    const wrap = card('chat-shows', animate);
    shows.forEach((s) => {
      const item = el('div', 'chat-show');
      if (/^[\w/.-]+$/.test(s.image || '')) item.append(el('img', '', '', { src: page(s.image), alt: '', width: '64', height: '80', loading: 'lazy' }));
      const info = el('div', 'chat-show-info');
      info.append(el('strong', '', s.name), el('span', 'chat-show-when', s.when));
      if (s.host) info.append(el('span', 'chat-show-host', s.host));
      info.append(el('a', 'chat-card-link', 'Reserve a table →', { href: page('contact.html#reserve') }));
      item.append(info);
      wrap.append(item);
    });
    scrollDown();
  }

  // Private-party packages with prices and "Plan my event". Data comes from private-events.html.
  function packagesCard(packages, animate) {
    const c = card('chat-packages', animate);
    c.append(el('strong', 'chat-card-title', '\u{1F389} Private event packages'));
    const list = el('ul');
    packages.forEach((p) => {
      const li = el('li');
      const head = el('div', 'chat-pkg-head');
      head.append(el('b', '', p.name), el('span', '', 'from ' + p.price));
      li.append(head, el('small', '', (p.features || []).slice(0, 3).join(' · ')));
      list.append(li);
    });
    const actions = el('div', 'chat-card-actions');
    actions.append(
      el('a', 'chat-card-btn', 'Plan my event', { href: page('contact.html') }),
      el('a', 'chat-card-btn is-ghost', 'See details', { href: page('private-events.html') })
    );
    c.append(list, actions);
    scrollDown();
  }

  // "Talk to a person": the team's phone and hours, no AI involved.
  function humanCard(animate) {
    const c = card('chat-human', animate);
    c.append(el('strong', 'chat-card-title', '\u{1F44B} Talk to our team'));
    if (CFG.hours) c.append(el('p', '', CFG.hours));
    const actions = el('div', 'chat-card-actions');
    actions.append(
      el('a', 'chat-card-btn', 'Call ' + PHONE, { href: PHONE_TEL }),
      el('a', 'chat-card-btn is-ghost', 'Send a message', { href: page('contact.html') })
    );
    c.append(actions);
    scrollDown();
  }

  // One-tap follow-up questions under the latest reply.
  function suggestionChips(list) {
    clearSuggestions();
    if (!list || !list.length) return;
    const wrap = el('div', 'chat-suggest-row');
    list.forEach((text) => {
      const b = el('button', '', text, { type: 'button' });
      b.addEventListener('click', () => send(text));
      wrap.append(b);
    });
    log.append(wrap);
    scrollDown();
  }
  function clearSuggestions() {
    log.querySelectorAll('.chat-suggest-row').forEach((n) => n.remove());
  }

  // ---------- food orders ----------

  const CART_KEY = 'rms-cart-v1'; // shared with js/order.js
  const money = (cents) => '$' + (cents / 100).toFixed(2);

  function currentCart() {
    try {
      return (JSON.parse(localStorage.getItem(CART_KEY)) || []).map((l) => ({ itemId: l.itemId, variationId: l.variationId, qty: l.qty, modifiers: l.modifiers }));
    } catch (e) { return []; }
  }

  // Puts dishes the assistant added into the site's normal cart. On the menu page
  // js/order.js takes them directly; elsewhere they wait in storage for it.
  function addToCart(lines) {
    if (window.rmsOrder) return window.rmsOrder.add(lines);
    try {
      const cart = JSON.parse(localStorage.getItem(CART_KEY)) || [];
      lines.forEach((l) => cart.push({
        key: crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2),
        itemId: l.itemId, variationId: l.variationId, qty: l.qty, modifiers: l.modifiers || [], note: l.note || '',
      }));
      localStorage.setItem(CART_KEY, JSON.stringify(cart));
    } catch (e) { /* storage unavailable: the guest can still order on the menu page */ }
  }

  function reviewOrder() {
    if (window.rmsOrder) { if (!embedded) setOpen(false, false); window.rmsOrder.open(); }
    else location.href = page('menu.html#order');
  }

  // "Added to your order" card with a Review order button, under the reply.
  function orderCard(items, animate) {
    const c = el('div', 'chat-order' + (animate ? ' is-new' : ''));
    c.append(el('strong', '', '\u{1F6D2} Added to your order'));
    const list = el('ul');
    items.forEach((i) => {
      const li = el('li');
      li.append(i.label, el('span', '', money(i.total)));
      list.append(li);
    });
    const btn = el('button', '', 'Review order', { type: 'button' });
    btn.addEventListener('click', reviewOrder);
    c.append(list, btn);
    log.append(c);
    scrollDown();
  }

  // Menu items the AI recommends: tappable cards with name, price and "Add to order".
  function menuCards(items, animate) {
    const wrap = el('div', 'chat-menu' + (animate ? ' is-new' : ''));
    items.forEach((item) => {
      const c = el('div', 'chat-menu-item');
      if (item.image) c.append(el('img', '', '', { src: item.image, alt: '', width: '56', height: '56', loading: 'lazy' }));
      const info = el('div', 'chat-menu-info');
      info.append(el('strong', '', item.name), el('span', 'chat-menu-price', item.price));
      if (item.desc) info.append(el('small', '', item.desc));
      c.append(info);
      const btn = el('button', 'chat-menu-add', '+ Order', { type: 'button' });
      btn.addEventListener('click', () => send("I'd like to order " + item.name));
      c.append(btn);
      wrap.append(c);
    });
    log.append(wrap);
    scrollDown();
  }

  function typing() {
    const msg = row('assistant', true);
    msg.classList.add('chat-typing');
    msg.innerHTML = '<span class="chat-dots" aria-hidden="true"><i></i><i></i><i></i></span>';
    scrollDown();
    // Local test models can be slow; let the guest know it's still working.
    const slow = setTimeout(() => { msg.append('Still thinking…'); scrollDown(); }, 12000);
    return () => { clearTimeout(slow); msg.parentNode.remove(); };
  }

  // ---------- talking to the server ----------

  // Guest asked for a person: answer straight away with the team's details.
  function talkToPerson(text) {
    if (busy) return;
    if (!contactOk()) { pendingQuestion = text || null; return revealGate(); }
    clearSuggestions();
    chips.hidden = true;
    if (text) {
      state.messages.push({ role: 'user', content: text });
      bubble('user', text);
    }
    const m = { role: 'assistant', content: HUMAN_MSG, human: true };
    state.messages.push(m);
    save();
    showReply(m, true);
  }

  // "Show me the menu": fetch the food menu and render a browsable accordion.
  async function browseMenu(text) {
    if (busy) return;
    if (!contactOk()) { pendingQuestion = text || null; return revealGate(); }
    busy = true;
    clearSuggestions();
    chips.hidden = true;
    if (text) {
      state.messages.push({ role: 'user', content: text });
      bubble('user', text);
    }
    input.value = '';
    grow();
    const stopTyping = typing();

    let menu = null;
    try {
      const res = await fetch(new URL('api/menu.php', SITE).href);
      menu = await res.json();
    } catch (e) { /* handled below */ }
    stopTyping();

    if (menu && menu.categories && menu.categories.length) {
      const m = { role: 'assistant', content: "Here's our food menu! Tap a category to see the dishes, then hit \"+ Order\" to add something to your order.", menuBrowse: true };
      state.messages.push(m);
      save();
      bubble('assistant', m.content);
      menuBrowseCard(menu, true);
      suggestionChips(['What do you recommend?', 'Do you deliver to my area?', "What's on tonight?"]);
    } else {
      state.messages.push({ role: 'assistant', content: "I couldn't load the menu right now. You can browse it at /menu.html or call us at " + PHONE + '.' });
      save();
      bubble('assistant', state.messages[state.messages.length - 1].content, { error: true });
    }
    busy = false;
    if (!panel.hidden) input.focus({ preventScroll: true });
  }

  // The interactive menu accordion: categories expand to show items with prices and "Order" buttons.
  function menuBrowseCard(menu, animate) {
    const wrap = el('div', 'chat-menu-browse' + (animate ? ' is-new' : ''));
    menu.categories.forEach((cat) => {
      var ids = cat.itemIds.filter(function (id) { return menu.items[id] && !menu.items[id].soldOut; });
      if (!ids.length) return;
      var section = el('div', 'chat-menu-cat');
      var header = el('button', 'chat-menu-cat-head', '', { type: 'button' });
      var icon = el('span', 'chat-menu-cat-toggle', '+');
      header.append(icon, document.createTextNode(' ' + cat.name));
      var count = el('span', 'chat-menu-cat-count', ids.length === 1 ? '1 item' : ids.length + ' items');
      header.append(count);
      var body = el('div', 'chat-menu-cat-body');
      body.hidden = true;

      ids.forEach(function (id) {
        var item = menu.items[id];
        var variations = item.variations.filter(function (v) { return !v.soldOut; });
        if (!variations.length) return;
        var prices = variations.map(function (v) { return v.price; });
        var lo = Math.min.apply(null, prices);
        var hi = Math.max.apply(null, prices);
        var priceText = lo === hi ? money(lo) : money(lo) + ' to ' + money(hi);

        var row = el('div', 'chat-menu-item');
        if (item.image) row.append(el('img', '', '', { src: item.image, alt: '', width: '44', height: '44', loading: 'lazy' }));
        var info = el('div', 'chat-menu-info');
        info.append(el('strong', '', item.name), el('span', 'chat-menu-price', priceText));
        row.append(info);
        var btn = el('button', 'chat-menu-add', '+ Order', { type: 'button' });
        btn.addEventListener('click', function () { send("I'd like to order " + item.name); });
        row.append(btn);
        body.append(row);
      });

      header.addEventListener('click', function () {
        var open = !body.hidden;
        body.hidden = open;
        icon.textContent = open ? '+' : '−';
        section.classList.toggle('is-open', !open);
        if (!open) scrollDown();
      });
      section.append(header, body);
      wrap.append(section);
    });
    log.append(wrap);
    scrollDown();
  }

  // On session restore, show a compact button instead of re-fetching the full menu.
  function menuBrowseRestore() {
    var wrap = el('div', 'chat-menu-browse-btn');
    var btn = el('button', 'chat-card-btn', '\u{1F4CB} Browse the menu', { type: 'button' });
    btn.addEventListener('click', async function () {
      wrap.remove();
      var stopTyping = typing();
      try {
        var res = await fetch(new URL('api/menu.php', SITE).href);
        var menu = await res.json();
        stopTyping();
        if (menu && menu.categories) menuBrowseCard(menu, true);
      } catch (e) { stopTyping(); }
    });
    wrap.append(btn);
    log.append(wrap);
    scrollDown();
  }

  async function send(text) {
    text = text.trim().slice(0, 600);
    if (!text || busy) return;
    if (!contactOk()) { pendingQuestion = text; input.value = ''; grow(); return revealGate(); }
    if (HUMAN_RE.test(text)) { input.value = ''; grow(); return talkToPerson(text); }
    if (MENU_RE.test(text)) { input.value = ''; grow(); return browseMenu(text); }
    busy = true;
    clearSuggestions();
    chips.hidden = true;
    state.messages.push({ role: 'user', content: text });
    save();
    bubble('user', text);
    input.value = '';
    grow();
    const stopTyping = typing();

    let data = null;
    try {
      const res = await fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          messages: state.messages.map((m) => ({ role: m.role, content: m.content })),
          cart: currentCart(), // so the assistant knows what's already ordered
          cid: state.cid,
          name: state.contact ? state.contact.name : '',
          email: state.contact ? state.contact.email : '',
          phone: state.contact ? state.contact.phone || '' : '',
        }),
      });
      data = await res.json().catch(() => null);
    } catch (e) { /* network error: handled below */ }
    stopTyping();

    if (data && data.ok) {
      const msg = { role: 'assistant', content: data.reply };
      if (Array.isArray(data.cart) && data.cart.length) {
        addToCart(data.cart);
        msg.cart = data.cart.map((l) => ({ label: l.label, total: l.total })); // just what the card shows
      }
      if (Array.isArray(data.cards) && data.cards.length) msg.cards = data.cards;
      if (Array.isArray(data.suggestions)) msg.suggestions = data.suggestions.slice(0, 3);
      state.messages.push(msg);
      save();
      showReply(msg, true);
      suggestionChips(msg.suggestions);
    } else {
      // Failed turns aren't kept, so the guest can simply ask again.
      state.messages.pop();
      save();
      const err = bubble('assistant', (data && data.error) || "Sorry, I can't connect right now. Please call us at " + PHONE + '.', { error: true });
      if (data && data.detail) {
        err.append(el('small', '', 'For the developer: ' + data.detail));
        scrollDown();
      }
      suggestionChips(['Talk to a person']);
    }
    busy = false;
    grow();
    if (!panel.hidden && (!embedded || isVisible())) input.focus({ preventScroll: true });
  }

  // ---------- open / close ----------

  function isVisible() {
    const r = panel.getBoundingClientRect();
    return r.top < innerHeight && r.bottom > 0;
  }

  function setOpen(open, focus) {
    if (embedded) return;
    panel.hidden = !open;
    launcher.setAttribute('aria-expanded', String(open));
    launcher.classList.remove('is-pulse');
    state.open = open;
    save();
    if (open) { scrollDown(); requestAnimationFrame(scrollDown); } // again once fonts/layout settle
    if (open && focus) (contactOk() ? input : gateName).focus();
    if (!open && focus) launcher.focus();
  }

  // On the page with the built-in chat, the "Ask us" button takes the guest to it.
  function goToEmbedded() {
    launcher.classList.remove('is-pulse');
    panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => (contactOk() ? input : gateName).focus({ preventScroll: true }), 400);
  }

  // Grow the box with the text (up to ~5 lines); the send button lights up once there's something to send.
  function grow() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    input.style.overflowY = input.scrollHeight > 120 ? 'auto' : 'hidden';
    sendBtn.disabled = busy || !input.value.trim();
  }

  launcher.addEventListener('click', () => (embedded ? goToEmbedded() : setOpen(true, true)));
  const closeBtn = panel.querySelector('[data-close]');
  if (closeBtn) closeBtn.addEventListener('click', () => setOpen(false, true));
  panel.querySelector('[data-human]').addEventListener('click', () => talkToPerson(''));
  panel.querySelector('[data-restart]').addEventListener('click', () => {
    if (busy) return;
    state.messages = [];
    state.cid = newCid(); // a new chat is a new conversation in the history
    save();
    render();
    input.focus({ preventScroll: true });
  });
  if (!embedded) panel.addEventListener('keydown', (e) => { if (e.key === 'Escape') setOpen(false, true); });
  form.addEventListener('submit', (e) => { e.preventDefault(); send(input.value); });
  input.addEventListener('input', grow);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(input.value); }
  });

  // "Try asking" buttons on the page.
  document.querySelectorAll('[data-ask]').forEach((b) => b.addEventListener('click', () => {
    if (embedded) {
      if (!isVisible()) panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else setOpen(true, false);
    send(b.getAttribute('data-ask'));
  }));

  render();
  window.addEventListener('load', scrollDown);
  if (embedded) {
    // Hide the floating button while the built-in chat is on screen.
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(([entry]) => launcher.classList.toggle('is-away', entry.isIntersecting)).observe(panel);
    }
  } else if (state.open) setOpen(true, false); // stay open when moving between pages
  if (!state.messages.length) launcher.classList.add('is-pulse'); // a gentle "hello" on first visit

  // ---------- opens by itself, once per visit ----------

  // A few seconds (Admin → Chat settings) after the first page has fully loaded, the
  // chat opens without a click. In the recommended "smart" mode phones get a small
  // greeting bubble beside the button instead, because there the panel would cover
  // the whole screen (and Google ranks pages with screen-covering pop-ups lower on
  // mobile). Once shown, it never comes back in this browser tab, so a guest who
  // closes the chat isn't asked again on every page.
  const AUTO_DELAY = Math.max(0, Number(CFG.autoDelay) || 0) * 1000;
  const PHONE_SCREEN = '(max-width: 480px)'; // where the panel goes full-screen (css/style.css)

  function autoOpen() {
    if (state.auto || !panel.hidden || document.querySelector('.mobile-nav.open')) return;
    state.auto = true;
    save();
    const bubbleOnly = CFG.autoOpen === 'bubble' || (CFG.autoOpen === 'smart' && matchMedia(PHONE_SCREEN).matches);
    if (bubbleOnly) showTeaser();
    else setOpen(true, false); // no focus: the page stays where the guest is
  }

  function showTeaser() {
    const teaser = el('div', 'chat-teaser' + (LEFT ? ' is-left' : ''));
    teaser.innerHTML = '<button type="button" class="chat-teaser-msg">' + escHtml(CFG.bubble) + '</button>' +
      '<button type="button" class="chat-teaser-x" aria-label="Dismiss">' + ICONS.close + '</button>';
    document.body.append(teaser);
    // Sit just above the button, wherever the page's own bars have moved it (or near the bottom with no button).
    const place = () => {
      teaser.style.bottom = (launcher.hidden ? 24 : Math.max(16, innerHeight - launcher.getBoundingClientRect().top + 10)) + 'px';
    };
    place();
    addEventListener('resize', place);
    const remove = () => { teaser.remove(); removeEventListener('resize', place); };
    teaser.querySelector('.chat-teaser-msg').addEventListener('click', () => { remove(); setOpen(true, true); });
    teaser.querySelector('.chat-teaser-x').addEventListener('click', remove);
    launcher.addEventListener('click', remove, { once: true });
  }

  if (!embedded && CFG.autoOpen !== 'off' && !state.auto && !state.open) {
    const later = () => setTimeout(autoOpen, AUTO_DELAY);
    if (document.readyState === 'complete') later(); else addEventListener('load', later, { once: true });
  }
})();
