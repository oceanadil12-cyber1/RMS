/**
 * Staff ordering interface: dine-in, takeaway and delivery.
 *
 * Reads the menu from the data attribute, lets staff build an order (type,
 * table for dine-in, phone + address for delivery, items, notes), and submits
 * it to the admin's api=dine-in endpoint.
 */
(function () {
    'use strict';

    const app = document.getElementById('dine-in-app');
    if (!app) return;

    const menuData = JSON.parse(app.dataset.menu || '{}');
    const apiUrl = app.dataset.api;
    const categories = menuData.categories || {};
    const items = menuData.items || {};

    // DOM
    const tabsEl = document.getElementById('dinein-cat-tabs');
    const itemsEl = document.getElementById('dinein-items');
    const cartEl = document.getElementById('dinein-cart');
    const cartEmpty = document.getElementById('dinein-cart-empty');
    const typeEl = document.getElementById('dinein-type');
    const tableField = document.getElementById('dinein-table-field');
    const tableInput = document.getElementById('dinein-table');
    const guestInput = document.getElementById('dinein-guest');
    const deliveryFields = document.getElementById('dinein-delivery-fields');
    const phoneInput = document.getElementById('dinein-phone');
    const addressInput = document.getElementById('dinein-address');
    const notesInput = document.getElementById('dinein-notes');
    const totalEl = document.getElementById('dinein-total');
    const submitBtn = document.getElementById('dinein-submit');
    const resultEl = document.getElementById('dinein-result');

    // state
    let activeCat = null;
    let orderType = 'dine-in'; // 'dine-in' | 'pickup' (takeaway) | 'delivery'
    let cart = []; // { id, name, qty, price, variation, mods, note }

    // --- order type: dine-in needs a table, delivery needs phone + address ---
    function setOrderType(type) {
        orderType = type;
        typeEl.querySelectorAll('[data-type]').forEach(a => {
            const on = a.dataset.type === type;
            a.classList.toggle('on', on);
            a.setAttribute('aria-checked', on ? 'true' : 'false');
        });
        tableField.hidden = type !== 'dine-in';
        tableInput.required = type === 'dine-in';
        deliveryFields.hidden = type !== 'delivery';
        (type === 'dine-in' ? tableInput : type === 'delivery' ? phoneInput : guestInput).focus();
    }

    typeEl.addEventListener('click', (e) => {
        e.preventDefault();
        const a = e.target.closest('[data-type]');
        if (a) setOrderType(a.dataset.type);
    });

    function flagMissing(input) {
        input.focus();
        input.classList.add('error');
        setTimeout(() => input.classList.remove('error'), 2000);
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function money(cents) {
        return '$' + (cents / 100).toFixed(2);
    }

    // --- category tabs ("Menu" first: every category on one page) ---
    const ALL = '__all';

    function renderTabs() {
        const cats = Object.keys(categories);
        if (!cats.length) { tabsEl.innerHTML = '<span class="muted">No menu items found.</span>'; return; }
        if (!activeCat) activeCat = cats[0];
        tabsEl.innerHTML = [ALL, ...cats].map(cat =>
            `<a href="#" class="${cat === activeCat ? 'on' : ''}" data-cat="${esc(cat)}">${cat === ALL ? 'Menu' : esc(cat)}</a>`
        ).join('');
        renderItems();
    }

    tabsEl.addEventListener('click', (e) => {
        e.preventDefault();
        const tab = e.target.closest('[data-cat]');
        if (!tab) return;
        activeCat = tab.dataset.cat;
        renderTabs();
    });

    // --- menu items ---
    function renderItems() {
        if (activeCat === ALL) {
            itemsEl.innerHTML = Object.keys(categories).map(cat =>
                `<h3 class="dinein-cat-head">${esc(cat)}</h3>` + itemRows(categories[cat] || [])
            ).join('');
            return;
        }
        const ids = categories[activeCat] || [];
        if (!ids.length) { itemsEl.innerHTML = '<p class="muted">No items in this category.</p>'; return; }
        itemsEl.innerHTML = itemRows(ids);
    }

    // one row per size or choice, so the waiter can pick e.g. a small salad
    function itemRows(ids) {
        return ids.map(id => {
            const item = items[id];
            if (!item) return '';
            return (item.variations || []).filter(v => !v.soldOut).map(v => `<div class="dinein-item" data-id="${esc(id)}">
                <div class="dinein-item-info">
                    <b>${esc(item.name)}${v.name !== 'Regular' ? ' <small>(' + esc(v.name) + ')</small>' : ''}</b>
                    <span class="muted">${v.price ? money(v.price) : ''}</span>
                </div>
                <button class="btn sm primary dinein-add" data-id="${esc(id)}" data-variation="${esc(v.id)}">+ Add</button>
            </div>`).join('');
        }).join('');
    }

    itemsEl.addEventListener('click', (e) => {
        const btn = e.target.closest('.dinein-add');
        if (!btn) return;
        addToCart(btn.dataset.id, btn.dataset.variation);
    });

    // --- cart ---
    function addToCart(id, variationId) {
        const item = items[id];
        if (!item) return;

        const variation = (item.variations || []).find(v => v.id === variationId);
        const price = variation ? variation.price : 0;
        const varName = variation ? variation.name : '';

        // check if already in cart with same variation
        const existing = cart.find(c => c.id === id && c.variation === varName);
        if (existing) {
            existing.qty++;
        } else {
            cart.push({
                id,
                name: item.name,
                qty: 1,
                price,
                variation: varName,
                mods: '',
                note: '',
            });
        }
        renderCart();
    }

    function renderCart() {
        if (cart.length === 0) {
            cartEmpty.hidden = false;
            // remove cart item elements but keep the empty message
            cartEl.querySelectorAll('.dinein-cart-item').forEach(el => el.remove());
            totalEl.textContent = '';
            submitBtn.disabled = true;
            return;
        }

        cartEmpty.hidden = true;
        let total = 0;
        let html = '';
        cart.forEach((c, i) => {
            const lineTotal = c.price * c.qty;
            total += lineTotal;
            html += `<div class="dinein-cart-item" data-index="${i}">
                <div class="dinein-cart-row">
                    <span class="dinein-cart-qty">
                        <button class="btn-tiny" data-dec="${i}">−</button>
                        <b>${c.qty}</b>
                        <button class="btn-tiny" data-inc="${i}">+</button>
                    </span>
                    <span class="dinein-cart-name">${esc(c.name)}${c.variation && c.variation !== 'Regular' ? ' <small>(' + esc(c.variation) + ')</small>' : ''}</span>
                    <span class="dinein-cart-price">${c.price ? money(lineTotal) : ''}</span>
                    <button class="btn-tiny danger" data-remove="${i}" title="Remove">×</button>
                </div>
                <div class="dinein-cart-note">
                    <input type="text" placeholder="Item note (optional)" value="${esc(c.note)}" data-note="${i}" maxlength="200">
                </div>
            </div>`;
        });

        // keep empty message in DOM
        const emptyMsg = cartEmpty;
        cartEl.innerHTML = html;
        cartEl.insertBefore(emptyMsg, cartEl.firstChild);

        totalEl.textContent = total > 0 ? 'Total: ' + money(total) : cart.length + (cart.length === 1 ? ' item' : ' items');
        submitBtn.disabled = false;
    }

    cartEl.addEventListener('click', (e) => {
        const dec = e.target.closest('[data-dec]');
        const inc = e.target.closest('[data-inc]');
        const rem = e.target.closest('[data-remove]');

        if (dec) {
            const i = parseInt(dec.dataset.dec);
            if (cart[i]) { cart[i].qty--; if (cart[i].qty < 1) cart.splice(i, 1); renderCart(); }
        } else if (inc) {
            const i = parseInt(inc.dataset.inc);
            if (cart[i]) { cart[i].qty++; renderCart(); }
        } else if (rem) {
            const i = parseInt(rem.dataset.remove);
            cart.splice(i, 1);
            renderCart();
        }
    });

    cartEl.addEventListener('input', (e) => {
        const noteInput = e.target.closest('[data-note]');
        if (!noteInput) return;
        const i = parseInt(noteInput.dataset.note);
        if (cart[i]) cart[i].note = noteInput.value;
    });

    // --- submit ---
    submitBtn.addEventListener('click', async () => {
        const table = parseInt(tableInput.value, 10);
        if (orderType === 'dine-in' && (!table || table < 1 || table > 999)) { flagMissing(tableInput); return; }
        if (orderType === 'delivery') {
            if (phoneInput.value.replace(/\D/g, '').length < 10) { flagMissing(phoneInput); return; }
            if (!addressInput.value.trim()) { flagMissing(addressInput); return; }
        }
        if (cart.length === 0) return;

        submitBtn.disabled = true;
        submitBtn.textContent = 'Sending...';

        try {
            const res = await fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    type: orderType,
                    table: orderType === 'dine-in' ? table : null,
                    phone: orderType === 'delivery' ? phoneInput.value.trim() : '',
                    address: orderType === 'delivery' ? addressInput.value.trim() : '',
                    guest: guestInput.value.trim(),
                    items: cart.map(c => ({
                        name: c.name,
                        qty: c.qty,
                        price: c.price,
                        variation: c.variation,
                        mods: c.mods,
                        note: c.note,
                    })),
                    notes: notesInput.value.trim(),
                }),
            });
            const data = await res.json();
            if (data.ok) {
                resultEl.hidden = false;
                resultEl.className = 'flash ok';
                const where = data.type === 'delivery' ? 'Delivery' : data.type === 'pickup' ? 'Takeaway' : 'Table ' + data.table;
                resultEl.innerHTML = '<b>Order #' + esc(data.orderNumber) + '</b> sent to kitchen! ' + esc(where) + ' · ' + data.items + (data.items === 1 ? ' item' : ' items');
                cart = [];
                guestInput.value = '';
                phoneInput.value = '';
                addressInput.value = '';
                notesInput.value = '';
                renderCart();
                setTimeout(() => { resultEl.hidden = true; }, 8000);
            } else {
                resultEl.hidden = false;
                resultEl.className = 'flash err';
                resultEl.textContent = data.error || 'Failed to send order.';
            }
        } catch (e) {
            resultEl.hidden = false;
            resultEl.className = 'flash err';
            resultEl.textContent = 'Network error. Please try again.';
        }
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<svg class="i" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg><span>Send to Kitchen</span>';
    });

    // --- init ---
    renderTabs();
    renderCart();

    setOrderType('dine-in'); // also focuses the table input
})();
