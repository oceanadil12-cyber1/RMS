/**
 * Customer-facing dine-in ordering.
 *
 * Single-page app at /dine-in/table/{N}. The URL never changes.
 * Cart is in-memory only — no localStorage, no cross-customer data leakage.
 */
(function () {
  'use strict';

  var app = document.getElementById('dinein-app');
  if (!app) return;

  var TABLE = parseInt(app.dataset.table, 10);
  // The site's folder ("/" at the domain root), so requests work wherever the site is uploaded.
  var BASE = window.__DINEIN_BASE || '/';

  // ---------- helpers ----------
  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : s;
    return d.innerHTML;
  }
  function money(cents) { return '$' + (cents / 100).toFixed(2); }

  // ---------- state ----------
  var state = {
    screen: 'welcome',
    customerName: '',
    activeCat: null,
    cart: [],         // { key, itemId, variationId, qty, modifiers:[], note }
    orderNotes: '',
    orders: []        // { number, items, total, time } — all orders this session
  };

  var menu = window.__DINEIN_MENU || null;

  function menuItem(id) { return menu && menu.items[id]; }

  function cartCount() {
    var n = 0;
    for (var i = 0; i < state.cart.length; i++) n += state.cart[i].qty;
    return n;
  }

  function linePrice(line) {
    var it = menuItem(line.itemId);
    if (!it) return 0;
    var v = it.variations.find(function (x) { return x.id === line.variationId; });
    if (!v) return 0;
    var unit = v.price;
    it.modifiers.forEach(function (g) {
      g.choices.forEach(function (c) {
        if (line.modifiers.indexOf(c.id) >= 0) unit += c.price;
      });
    });
    return unit * line.qty;
  }

  function cartTotal() {
    var t = 0;
    for (var i = 0; i < state.cart.length; i++) t += linePrice(state.cart[i]);
    return t;
  }

  function lineDetail(line) {
    var it = menuItem(line.itemId);
    if (!it) return '';
    var parts = [];
    var v = it.variations.find(function (x) { return x.id === line.variationId; });
    if (v && v.name && v.name !== 'Regular') parts.push(v.name);
    it.modifiers.forEach(function (g) {
      g.choices.forEach(function (c) {
        if (line.modifiers.indexOf(c.id) >= 0 && !/^(none|no thanks)$/i.test(c.name.trim())) {
          parts.push(c.name);
        }
      });
    });
    if (line.note) parts.push('Note: ' + line.note);
    return parts.join(' · ');
  }

  function priceLabel(it) {
    var prices = it.variations.map(function (v) { return v.price; });
    var mn = Math.min.apply(null, prices);
    return prices.some(function (p) { return p !== mn; }) ? 'from ' + money(mn) : money(mn);
  }

  function uid() {
    return Date.now().toString(36) + Math.random().toString(36).slice(2);
  }

  // ---------- DOM refs ----------
  var nameForm   = document.getElementById('dinein-name-form');
  var nameInput  = document.getElementById('dinein-name');
  var nameError  = document.getElementById('dinein-name-error');
  var greetingEl = document.getElementById('dinein-greeting');
  var catsEl     = document.getElementById('dinein-cats');
  var gridEl     = document.getElementById('dinein-menu-grid');
  var cartBar    = document.getElementById('dinein-cart-bar');
  var cartCountEl  = document.getElementById('dinein-cart-count');
  var cartTotalEl  = document.getElementById('dinein-cart-total');
  var cartItemsEl  = document.getElementById('dinein-cart-items');
  var cartSummaryEl = document.getElementById('dinein-cart-summary');
  var cartEmptyMsg  = document.getElementById('dinein-cart-empty-msg');
  var placeOrderBtn = document.getElementById('dinein-place-order-btn');
  var orderNotesEl  = document.getElementById('dinein-order-notes');
  var modalOverlay  = document.getElementById('dinein-modal');
  var modalBody     = document.getElementById('dinein-modal-body');
  var modalFooter   = document.getElementById('dinein-modal-footer');
  var modalCloseBtn = document.getElementById('dinein-modal-close');
  var callWaiterBtn     = document.getElementById('dinein-call-waiter-btn');
  var menuCallWaiterBtn = document.getElementById('dinein-menu-call-waiter');
  var startBtn          = document.getElementById('dinein-start-btn');

  // ========== SCREEN 1: WELCOME ==========

  var NAME_RE = /^[A-Za-z][A-Za-z ]*$/;

  function validateName(value) {
    var trimmed = value.trim();
    if (trimmed.length === 0) return 'Please enter your name.';
    if (!NAME_RE.test(trimmed))  return 'Letters only, please.';
    if (trimmed.length < 2)      return 'Name must be at least 2 characters.';
    if (trimmed.length > 30)     return 'Name is too long.';
    return '';
  }

  function setFieldError(msg) {
    nameError.textContent = msg;
    if (msg) nameInput.classList.add('dinein-field--invalid');
    else nameInput.classList.remove('dinein-field--invalid');
  }

  var menuAvailable = !!(menu && menu.categories && menu.categories.length);

  function updateWelcomeButtons() {
    var valid = !validateName(nameInput.value);
    callWaiterBtn.disabled = !valid;
    startBtn.disabled = !valid || !menuAvailable;
    if (valid && !menuAvailable) {
      startBtn.textContent = 'Menu Unavailable';
    } else {
      startBtn.textContent = 'Order by Yourself';
    }
  }

  nameInput.addEventListener('input', function () {
    var raw = nameInput.value;
    var filtered = raw.replace(/[^A-Za-z ]/g, '');
    if (filtered !== raw) nameInput.value = filtered;
    if (nameInput.classList.contains('dinein-field--invalid')) {
      if (!validateName(filtered)) setFieldError('');
    }
    updateWelcomeButtons();
  });

  nameInput.addEventListener('blur', function () {
    var err = validateName(nameInput.value);
    if (err && nameInput.value.trim().length > 0) setFieldError(err);
  });

  // "Order by Yourself" — goes to the menu
  nameForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var err = validateName(nameInput.value);
    if (err) { setFieldError(err); nameInput.focus(); return; }
    state.customerName = nameInput.value.trim();
    greetingEl.textContent = 'Hi, ' + state.customerName;
    showScreen('menu');
    renderMenu();
  });

  // "Call the Waiter" — sends a request to the admin panel
  var waiterInFlight = false;

  function waiterLabel(btn) {
    return btn === menuCallWaiterBtn ? 'Call Waiter' : 'Call the Waiter';
  }

  function callWaiter(e) {
    var name = state.customerName || nameInput.value.trim();
    if (!name || validateName(name)) {
      setFieldError('Please enter your name first.');
      return;
    }
    if (waiterInFlight) return;
    if (!state.customerName) state.customerName = name;

    var btn = (e && e.currentTarget) || callWaiterBtn;
    var label = waiterLabel(btn);
    waiterInFlight = true;
    btn.disabled = true;
    btn.textContent = 'Calling…';

    fetch(BASE + 'api/waiter-call.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ table: TABLE, name: state.customerName })
    })
    .then(function (res) {
      if (!res.ok && res.headers.get('content-type')?.indexOf('json') === -1) {
        throw new Error('server');
      }
      return res.json();
    })
    .then(function (data) {
      if (data.ok) {
        btn.textContent = data.already ? 'Already Called' : 'Waiter is Coming';
        btn.classList.add('dinein-btn--waiter-called');
        setTimeout(function () {
          btn.textContent = label;
          btn.classList.remove('dinein-btn--waiter-called');
          btn.disabled = false;
        }, 10000);
      } else {
        showWaiterError(btn, data.error || 'Could not call the waiter. Try again.');
      }
    })
    .catch(function () {
      showWaiterError(btn, 'Connection error. Please try again.');
    })
    .finally(function () {
      waiterInFlight = false;
    });
  }

  function showWaiterError(btn, msg) {
    var label = waiterLabel(btn);
    btn.textContent = msg;
    btn.classList.add('dinein-btn--waiter-error');
    btn.disabled = false;
    setTimeout(function () {
      btn.textContent = label;
      btn.classList.remove('dinein-btn--waiter-error');
    }, 4000);
  }

  callWaiterBtn.addEventListener('click', callWaiter);
  if (menuCallWaiterBtn) menuCallWaiterBtn.addEventListener('click', callWaiter);

  // ========== SCREEN MANAGEMENT ==========

  function showScreen(name) {
    var screens = app.querySelectorAll('.dinein-screen');
    for (var i = 0; i < screens.length; i++) {
      screens[i].classList.remove('dinein-screen--active');
    }
    var target = document.getElementById('screen-' + name);
    if (target) { target.classList.add('dinein-screen--active'); state.screen = name; }
    window.scrollTo(0, 0);
  }

  function resetFlow() {
    nameInput.value = '';
    setFieldError('');
    state.customerName = '';
    state.cart = [];
    state.orderNotes = '';
    state.activeCat = null;
    state.orders = [];
    state.lastOrder = null;
    if (orderNotesEl) orderNotesEl.value = '';
    startBtn.disabled = true;
    callWaiterBtn.disabled = true;
    callWaiterBtn.textContent = 'Call the Waiter';
    callWaiterBtn.classList.remove('dinein-btn--waiter-called');
    if (menuCallWaiterBtn) {
      menuCallWaiterBtn.textContent = 'Call Waiter';
      menuCallWaiterBtn.classList.remove('dinein-btn--waiter-called', 'dinein-btn--waiter-error');
      menuCallWaiterBtn.disabled = false;
    }
    clearIdleTimer();
    showScreen('welcome');
    nameInput.focus();
  }

  // ========== SCREEN 2: MENU ==========

  function renderMenu() {
    if (!menu || !menu.categories || !menu.categories.length) {
      gridEl.innerHTML = '<p class="dinein-loading">Menu is not available right now.</p>';
      return;
    }

    if (!state.activeCat) state.activeCat = menu.categories[0].id;
    renderCategoryTabs();
    renderMenuItems();
    updateCartBar();
  }

  var catsWrap   = document.getElementById('dinein-cats-wrap');
  var catsLeft   = document.getElementById('dinein-cats-left');
  var catsRight  = document.getElementById('dinein-cats-right');

  function updateCatsArrows() {
    if (!catsWrap) return;
    var sl = catsEl.scrollLeft;
    var canLeft = sl > 2;
    var canRight = sl < catsEl.scrollWidth - catsEl.clientWidth - 2;
    catsWrap.classList.toggle('can-scroll-left', canLeft);
    catsWrap.classList.toggle('can-scroll-right', canRight);
  }

  catsEl.addEventListener('scroll', updateCatsArrows, { passive: true });

  if (catsLeft) {
    catsLeft.addEventListener('click', function () {
      catsEl.scrollBy({ left: -160, behavior: 'smooth' });
    });
  }
  if (catsRight) {
    catsRight.addEventListener('click', function () {
      catsEl.scrollBy({ left: 160, behavior: 'smooth' });
    });
  }

  function renderCategoryTabs() {
    var html = '';
    menu.categories.forEach(function (cat) {
      html += '<button class="dinein-cat-tab' + (cat.id === state.activeCat ? ' active' : '') +
              '" data-cat="' + esc(cat.id) + '">' + esc(cat.name) + '</button>';
    });
    catsEl.innerHTML = html;

    // Scroll active tab into view
    var active = catsEl.querySelector('.active');
    if (active) active.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });

    setTimeout(updateCatsArrows, 50);
  }

  catsEl.addEventListener('click', function (e) {
    var tab = e.target.closest('.dinein-cat-tab');
    if (!tab) return;
    state.activeCat = tab.dataset.cat;
    renderCategoryTabs();
    renderMenuItems();

    // Scroll to the category section
    var section = document.getElementById('dinein-cat-' + state.activeCat);
    if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  function renderMenuItems() {
    var html = '';
    menu.categories.forEach(function (cat) {
      var items = cat.itemIds.map(function (id) { return menuItem(id); }).filter(Boolean);
      if (!items.length) return;
      html += '<div class="dinein-cat-section" id="dinein-cat-' + esc(cat.id) + '">';
      html += '<h3 class="dinein-cat-title">' + esc(cat.name) + '</h3>';
      html += '<div class="dinein-items-grid">';
      items.forEach(function (it) {
        var sold = it.soldOut || it.variations.every(function (v) { return v.soldOut; });
        html += '<button type="button" class="dinein-item-card' + (sold ? ' is-sold-out' : '') +
                '" data-item="' + esc(it.id) + '"' + (sold ? ' disabled' : '') + '>';
        html += '<div class="dinein-item-info">';
        html += '<span class="dinein-item-name">' + esc(it.name) + '</span>';
        if (it.description) html += '<span class="dinein-item-desc">' + esc(it.description) + '</span>';
        html += '<span class="dinein-item-price">' + (sold ? 'Sold out' : priceLabel(it)) + '</span>';
        html += '</div>';
        if (it.image) html += '<img class="dinein-item-img" src="' + esc(it.image) + '" alt="" loading="lazy">';
        if (!sold) html += '<span class="dinein-item-add">+</span>';
        html += '</button>';
      });
      html += '</div></div>';
    });
    gridEl.innerHTML = html;
  }

  gridEl.addEventListener('click', function (e) {
    var card = e.target.closest('.dinein-item-card');
    if (card && !card.disabled) openItemModal(card.dataset.item);
  });

  // ========== ITEM MODAL ==========

  var modalSel = null; // { variationId, mods: Set, qty, note }

  function openItemModal(itemId, editIndex) {
    var it = menuItem(itemId);
    if (!it) return;

    var editing = editIndex !== undefined ? state.cart[editIndex] : null;
    modalSel = {
      itemId: itemId,
      editIndex: editIndex,
      variationId: editing ? editing.variationId : (it.variations.find(function (v) { return !v.soldOut; }) || it.variations[0]).id,
      mods: new Set(editing ? editing.modifiers : it.modifiers.reduce(function (acc, g) {
        g.choices.forEach(function (c) { if (c.default && !c.soldOut) acc.push(c.id); });
        return acc;
      }, [])),
      qty: editing ? editing.qty : 1,
      note: editing ? editing.note : ''
    };

    renderModal(it);
    modalOverlay.hidden = false;
    document.body.classList.add('dinein-modal-open');
  }

  function closeModal() {
    modalOverlay.hidden = true;
    document.body.classList.remove('dinein-modal-open');
    modalSel = null;
  }

  modalCloseBtn.addEventListener('click', closeModal);
  modalOverlay.addEventListener('click', function (e) {
    if (e.target === modalOverlay) closeModal();
  });

  function renderModal(it) {
    var html = '';
    if (it.image) html += '<img class="dinein-modal-img" src="' + esc(it.image) + '" alt="">';
    html += '<h3>' + esc(it.name) + '</h3>';
    if (it.description) html += '<p class="dinein-modal-desc">' + esc(it.description) + '</p>';

    // Variations
    if (it.variations.length > 1) {
      html += '<div class="dinein-variations">';
      it.variations.forEach(function (v) {
        var active = v.id === modalSel.variationId;
        var dis = v.soldOut;
        html += '<label class="dinein-var-option' + (active ? ' active' : '') + (dis ? ' disabled' : '') + '">';
        html += '<input type="radio" name="dinein-var" value="' + esc(v.id) + '"' +
                (active ? ' checked' : '') + (dis ? ' disabled' : '') + '>';
        html += '<span class="dinein-var-name">' + esc(v.name) + (dis ? ' (sold out)' : '') + '</span>';
        html += '<span class="dinein-var-price">' + money(v.price) + '</span>';
        html += '</label>';
      });
      html += '</div>';
    }

    // Modifier groups
    it.modifiers.forEach(function (g) {
      var single = g.max === 1;
      var rule = g.min > 0
        ? (single ? 'Required' : 'Required · choose ' + g.min + (g.max && g.max !== g.min ? '–' + g.max : g.max ? '' : '+'))
        : (g.max > 0 ? 'Optional · up to ' + g.max : 'Optional');
      var isReq = g.min > 0;
      html += '<div class="dinein-mod-group" data-group="' + esc(g.id) + '">';
      html += '<div class="dinein-mod-group-head">';
      html += '<span class="dinein-mod-group-name">' + esc(g.name) + '</span>';
      html += '<span class="dinein-mod-req ' + (isReq ? 'req' : 'opt') + '">' + rule + '</span>';
      html += '</div>';
      html += '<div class="dinein-mod-group-error">Please make a selection.</div>';
      g.choices.forEach(function (c) {
        var checked = modalSel.mods.has(c.id);
        var dis = c.soldOut;
        html += '<label class="dinein-mod-choice' + (dis ? ' disabled' : '') + '">';
        html += '<input type="' + (single ? 'radio' : 'checkbox') + '" name="dinein-g-' + esc(g.id) +
                '" value="' + esc(c.id) + '"' + (checked ? ' checked' : '') + (dis ? ' disabled' : '') + '>';
        html += '<span class="dinein-mod-choice-name">' + esc(c.name) + (dis ? ' (sold out)' : '') + '</span>';
        if (c.price) html += '<span class="dinein-mod-choice-price">+' + money(c.price) + '</span>';
        html += '</label>';
      });
      html += '</div>';
    });

    // Special instructions
    html += '<div class="dinein-modal-note"><span>Special instructions</span>';
    html += '<textarea rows="2" maxlength="200" placeholder="Allergies, extra sauce on the side…">' + esc(modalSel.note) + '</textarea></div>';

    modalBody.innerHTML = html;

    // Footer: qty + add button
    var footerHtml = '<div class="dinein-qty">';
    footerHtml += '<button type="button" data-qty="-1">&minus;</button>';
    footerHtml += '<span>' + modalSel.qty + '</span>';
    footerHtml += '<button type="button" data-qty="1">+</button>';
    footerHtml += '</div>';
    footerHtml += '<button type="button" class="dinein-modal-add">' +
                  (modalSel.editIndex !== undefined ? 'Update' : 'Add to order') +
                  ' · <span></span></button>';
    modalFooter.innerHTML = footerHtml;

    updateModalPrice(it);
  }

  function updateModalPrice(it) {
    if (!modalSel) return;
    var v = it.variations.find(function (x) { return x.id === modalSel.variationId; });
    var unit = v ? v.price : 0;
    it.modifiers.forEach(function (g) {
      g.choices.forEach(function (c) {
        if (modalSel.mods.has(c.id)) unit += c.price;
      });
    });
    var priceSpan = modalFooter.querySelector('.dinein-modal-add span');
    if (priceSpan) priceSpan.textContent = money(unit * modalSel.qty);
    var qtySpan = modalFooter.querySelector('.dinein-qty span');
    if (qtySpan) qtySpan.textContent = modalSel.qty;
  }

  // Modal event delegation
  modalBody.addEventListener('change', function (e) {
    var input = e.target;
    if (!modalSel) return;
    var it = menuItem(modalSel.itemId);
    if (!it) return;

    if (input.name === 'dinein-var') {
      modalSel.variationId = input.value;
      // Update active class on variation options
      modalBody.querySelectorAll('.dinein-var-option').forEach(function (el) {
        el.classList.toggle('active', el.querySelector('input').value === input.value);
      });
    } else if (input.name && input.name.indexOf('dinein-g-') === 0) {
      var gid = input.name.replace('dinein-g-', '');
      var group = it.modifiers.find(function (g) { return g.id === gid; });
      if (group && input.type === 'radio') {
        group.choices.forEach(function (c) { modalSel.mods.delete(c.id); });
      }
      if (input.checked) modalSel.mods.add(input.value);
      else modalSel.mods.delete(input.value);

      // Clear error on this group
      var groupEl = input.closest('.dinein-mod-group');
      if (groupEl) groupEl.classList.remove('has-error');

      // Enforce max for multi-choice groups
      if (group && group.max > 1) {
        var boxes = modalBody.querySelectorAll('[name="dinein-g-' + CSS.escape(gid) + '"]');
        var count = 0;
        boxes.forEach(function (b) { if (b.checked) count++; });
        boxes.forEach(function (b) {
          if (!b.checked) {
            var choice = group.choices.find(function (c) { return c.id === b.value; });
            b.disabled = count >= group.max || (choice && choice.soldOut);
          }
        });
      }
    }
    updateModalPrice(it);
  });

  modalBody.addEventListener('input', function (e) {
    if (e.target.tagName === 'TEXTAREA' && modalSel) {
      modalSel.note = e.target.value;
    }
  });

  modalFooter.addEventListener('click', function (e) {
    if (!modalSel) return;
    var it = menuItem(modalSel.itemId);
    if (!it) return;

    var qBtn = e.target.closest('[data-qty]');
    if (qBtn) {
      modalSel.qty = Math.max(1, Math.min(50, modalSel.qty + parseInt(qBtn.dataset.qty, 10)));
      updateModalPrice(it);
      return;
    }

    if (e.target.closest('.dinein-modal-add')) {
      // Validate required modifier groups
      var missing = it.modifiers.find(function (g) {
        var selected = g.choices.filter(function (c) { return modalSel.mods.has(c.id); });
        return selected.length < g.min;
      });
      if (missing) {
        var groupEl = modalBody.querySelector('[data-group="' + CSS.escape(missing.id) + '"]');
        if (groupEl) {
          groupEl.classList.add('has-error');
          var errEl = groupEl.querySelector('.dinein-mod-group-error');
          if (errEl) errEl.textContent = 'Please choose ' + (missing.min > 1 ? missing.min + ' options' : 'an option') + ' for ' + missing.name + '.';
          groupEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        return;
      }

      var line = {
        key: modalSel.editIndex !== undefined ? state.cart[modalSel.editIndex].key : uid(),
        itemId: modalSel.itemId,
        variationId: modalSel.variationId,
        qty: modalSel.qty,
        modifiers: Array.from(modalSel.mods),
        note: modalSel.note.trim()
      };

      if (modalSel.editIndex !== undefined) {
        state.cart[modalSel.editIndex] = line;
      } else {
        state.cart.push(line);
      }

      closeModal();
      updateCartBar();

      if (state.screen === 'cart') renderCartItems();
    }
  });

  // ========== FLOATING CART BAR ==========

  function updateCartBar() {
    var count = cartCount();
    var total = cartTotal();
    if (count > 0) {
      cartBar.hidden = false;
      cartCountEl.textContent = count + ' item' + (count !== 1 ? 's' : '');
      cartTotalEl.textContent = money(total);
    } else {
      cartBar.hidden = true;
    }
  }

  document.getElementById('dinein-view-cart-btn').addEventListener('click', function () {
    showScreen('cart');
    renderCartItems();
  });

  // ========== SCREEN 3: CART REVIEW ==========

  document.getElementById('dinein-back-to-menu').addEventListener('click', function () {
    showScreen('menu');
    updateCartBar();
  });

  var browseMenuLink = document.getElementById('dinein-browse-menu');
  if (browseMenuLink) {
    browseMenuLink.addEventListener('click', function (e) {
      e.preventDefault();
      showScreen('menu');
      updateCartBar();
    });
  }

  function renderCartItems() {
    if (state.cart.length === 0) {
      cartItemsEl.innerHTML = '';
      cartEmptyMsg.hidden = false;
      cartSummaryEl.innerHTML = '';
      placeOrderBtn.disabled = true;
      if (orderNotesEl) orderNotesEl.parentElement.style.display = 'none';
      return;
    }

    cartEmptyMsg.hidden = true;
    if (orderNotesEl) orderNotesEl.parentElement.style.display = '';
    var html = '';
    state.cart.forEach(function (line, i) {
      var it = menuItem(line.itemId);
      if (!it) return;
      var price = linePrice(line);
      var detail = lineDetail(line);
      html += '<div class="dinein-cart-line" data-index="' + i + '">';
      html += '<div class="dinein-cart-line-qty">';
      html += '<button data-cart-dec="' + i + '">&minus;</button>';
      html += '<span>' + line.qty + '</span>';
      html += '<button data-cart-inc="' + i + '">+</button>';
      html += '</div>';
      html += '<div class="dinein-cart-line-info">';
      html += '<div class="dinein-cart-line-name">' + esc(it.name) + '</div>';
      if (detail) html += '<div class="dinein-cart-line-detail">' + esc(detail) + '</div>';
      html += '<button class="dinein-cart-line-remove" data-cart-remove="' + i + '">Remove</button>';
      html += '</div>';
      html += '<div class="dinein-cart-line-price">' + money(price) + '</div>';
      html += '</div>';
    });
    cartItemsEl.innerHTML = html;

    var total = cartTotal();
    var count = cartCount();
    cartSummaryEl.innerHTML =
      '<div class="dinein-cart-summary-row"><span>' + count + ' item' + (count !== 1 ? 's' : '') + '</span><span>' + money(total) + '</span></div>' +
      '<div class="dinein-cart-summary-row total"><span>Total</span><span>' + money(total) + '</span></div>';
    placeOrderBtn.disabled = false;
  }

  cartItemsEl.addEventListener('click', function (e) {
    var dec = e.target.closest('[data-cart-dec]');
    var inc = e.target.closest('[data-cart-inc]');
    var rem = e.target.closest('[data-cart-remove]');

    if (dec) {
      var i = parseInt(dec.dataset.cartDec, 10);
      if (state.cart[i]) {
        state.cart[i].qty--;
        if (state.cart[i].qty < 1) state.cart.splice(i, 1);
        renderCartItems();
      }
    } else if (inc) {
      var j = parseInt(inc.dataset.cartInc, 10);
      if (state.cart[j]) {
        state.cart[j].qty = Math.min(50, state.cart[j].qty + 1);
        renderCartItems();
      }
    } else if (rem) {
      var k = parseInt(rem.dataset.cartRemove, 10);
      state.cart.splice(k, 1);
      renderCartItems();
    }
  });

  if (orderNotesEl) {
    orderNotesEl.addEventListener('input', function () {
      state.orderNotes = orderNotesEl.value;
    });
  }

  // ========== PLACE ORDER ==========

  var orderErrorEl = null;

  function showOrderError(msg) {
    if (!orderErrorEl) {
      orderErrorEl = document.createElement('p');
      orderErrorEl.className = 'dinein-order-error';
      placeOrderBtn.parentNode.insertBefore(orderErrorEl, placeOrderBtn.nextSibling);
    }
    orderErrorEl.textContent = msg;
    orderErrorEl.hidden = !msg;
  }

  var orderInFlight = false;

  placeOrderBtn.addEventListener('click', function () {
    if (state.cart.length === 0) { showOrderError('Your cart is empty.'); return; }
    if (orderInFlight) return;
    if (!state.customerName) { showOrderError('Session expired. Please start over.'); return; }
    if (TABLE < 1) { showOrderError('Invalid table number.'); return; }

    state.orderNotes = orderNotesEl ? orderNotesEl.value.trim() : '';
    showOrderError('');

    var payload = {
      table: TABLE,
      name: state.customerName,
      cart: state.cart.map(function (line) {
        return {
          itemId: line.itemId,
          variationId: line.variationId,
          qty: line.qty,
          modifiers: line.modifiers,
          note: line.note || ''
        };
      }),
      notes: state.orderNotes
    };

    orderInFlight = true;
    placeOrderBtn.disabled = true;
    placeOrderBtn.textContent = 'Placing Order…';

    fetch(BASE + 'api/dine-in-order.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(function (res) {
      if (!res.ok && res.headers.get('content-type')?.indexOf('json') === -1) {
        throw new Error('Server error (' + res.status + ')');
      }
      return res.json();
    })
    .then(function (data) {
      if (data.ok) {
        state.lastOrder = {
          number: data.orderNumber,
          table: data.table,
          items: data.items,
          total: data.total,
          cart: state.cart.slice(),
          notes: state.orderNotes
        };
        state.cart = [];
        showScreen('confirmation');
        renderConfirmation();
      } else {
        showOrderError(data.error || 'Something went wrong. Please try again.');
      }
    })
    .catch(function (err) {
      var msg = 'Could not reach the server. Please check your connection and try again.';
      if (err && err.message && err.message.indexOf('Server error') === 0) msg = err.message + '. Please try again.';
      showOrderError(msg);
    })
    .finally(function () {
      orderInFlight = false;
      placeOrderBtn.disabled = state.cart.length === 0;
      placeOrderBtn.textContent = 'Place Order';
    });
  });

  // ========== SCREEN 4: ORDER CONFIRMATION ==========

  function renderConfirmation() {
    var o = state.lastOrder;
    if (!o) return;

    // Track this order in session history
    state.orders.push({
      number: o.number,
      items: o.items,
      total: o.total,
      time: new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
    });

    var el = document.getElementById('screen-confirmation');
    var html = '<div class="dinein-confirmation">';
    html += '<div class="dinein-confirm-icon">&#10003;</div>';
    html += '<h2>Order Placed!</h2>';
    html += '<p class="dinein-confirm-number">Order #' + esc(o.number) + '</p>';
    html += '<p>Your order has been sent to the kitchen.<br>Table ' + o.table + ' &middot; ' + o.items + ' item' + (o.items !== 1 ? 's' : '') + ' &middot; ' + money(o.total) + '</p>';

    // Order details
    html += '<div class="dinein-confirm-details">';
    o.cart.forEach(function (line) {
      var it = menuItem(line.itemId);
      if (!it) return;
      var detail = lineDetail(line);
      html += '<div class="dinein-confirm-line">';
      html += '<span class="dinein-confirm-line-qty">' + line.qty + 'x</span>';
      html += '<span class="dinein-confirm-line-name">' + esc(it.name);
      if (detail) html += '<small>' + esc(detail) + '</small>';
      html += '</span>';
      html += '<span class="dinein-confirm-line-price">' + money(linePrice(line)) + '</span>';
      html += '</div>';
    });
    if (o.notes) {
      html += '<div class="dinein-confirm-notes">Note: ' + esc(o.notes) + '</div>';
    }
    html += '</div>';

    // Session order history (if multiple orders)
    if (state.orders.length > 1) {
      var sessionTotal = 0;
      state.orders.forEach(function (so) { sessionTotal += so.total; });
      html += '<div class="dinein-session-history">';
      html += '<h3>Orders This Visit</h3>';
      state.orders.forEach(function (so) {
        html += '<div class="dinein-session-row">';
        html += '<span>#' + esc(so.number) + ' &middot; ' + so.time + '</span>';
        html += '<span>' + money(so.total) + '</span>';
        html += '</div>';
      });
      html += '<div class="dinein-session-row total">';
      html += '<span>Session Total</span>';
      html += '<span>' + money(sessionTotal) + '</span>';
      html += '</div>';
      html += '</div>';
    }

    html += '<div class="dinein-confirm-actions">';
    html += '<button class="dinein-btn dinein-btn--primary" id="dinein-new-order">Order More</button>';
    html += '<button class="dinein-btn dinein-btn--waiter" id="dinein-confirm-call-waiter">Call the Waiter</button>';
    html += '<button class="dinein-btn dinein-btn--secondary" id="dinein-new-guest">New Guest / Done</button>';
    html += '</div>';

    // Auto-reset notice
    html += '<p class="dinein-confirm-auto-reset">This screen resets automatically after 5 minutes.</p>';

    html += '</div>';
    el.innerHTML = html;

    document.getElementById('dinein-new-order').addEventListener('click', function () {
      showScreen('menu');
      renderMenu();
    });

    document.getElementById('dinein-new-guest').addEventListener('click', function () {
      resetFlow();
    });

    document.getElementById('dinein-confirm-call-waiter').addEventListener('click', callWaiter);

    // Start auto-reset countdown on confirmation screen
    startAutoReset();
  }

  // ========== AUTO-RESET & IDLE TIMER ==========

  var autoResetTimer = null;
  var AUTO_RESET_MS = 5 * 60 * 1000; // 5 minutes on confirmation screen

  var idleTimer = null;
  var IDLE_MS = 10 * 60 * 1000; // 10 minutes of inactivity anywhere

  function startAutoReset() {
    clearAutoReset();
    autoResetTimer = setTimeout(function () {
      if (state.screen === 'confirmation') resetFlow();
    }, AUTO_RESET_MS);
  }

  function clearAutoReset() {
    if (autoResetTimer) { clearTimeout(autoResetTimer); autoResetTimer = null; }
  }

  function resetIdleTimer() {
    if (state.screen === 'welcome') return;
    clearIdleTimer();
    idleTimer = setTimeout(function () {
      if (state.screen !== 'welcome') resetFlow();
    }, IDLE_MS);
  }

  function clearIdleTimer() {
    if (idleTimer) { clearTimeout(idleTimer); idleTimer = null; }
  }

  ['click', 'touchstart', 'scroll', 'keydown'].forEach(function (evt) {
    document.addEventListener(evt, function () {
      resetIdleTimer();
      if (state.screen === 'confirmation') startAutoReset();
    }, { passive: true });
  });

  // ========== EXPOSE FOR OTHER OPTIONS ==========

  window.DineIn = {
    table:       TABLE,
    state:       state,
    showScreen:  showScreen,
    resetFlow:   resetFlow,
    updateCartBar: updateCartBar,
    renderCartItems: renderCartItems,
    linePrice:   linePrice,
    lineDetail:  lineDetail,
    money:       money,
    esc:         esc,
    menuItem:    menuItem
  };
})();
