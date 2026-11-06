/**
 * Kitchen Display System — client-side logic.
 *
 * Polls the KDS API every 3 seconds, renders order cards, manages timers,
 * handles start/complete actions and sound notifications.
 */
(function () {
    'use strict';

    const API = document.body.dataset.api;
    if (!API) return;

    const grid = document.getElementById('kds-grid');
    const emptyEl = document.getElementById('kds-empty');

    // state
    let orders = [];
    let settings = {};
    let knownNumbers = new Set();
    let filter = 'active';
    let sort = 'oldest';
    let soundEnabled = true;
    let timerInterval = null;
    let pollTimeout = null;

    // --- API ---
    async function api(action, data) {
        const res = await fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...data }),
        });
        if (res.status === 401) {
            window.location.reload();
            return null;
        }
        const json = await res.json();
        if (!json.ok) {
            console.error('KDS API error:', json.error);
            return null;
        }
        return json;
    }

    // --- sound ---
    let audioCtx = null;
    function playAlert() {
        if (!soundEnabled) return;
        try {
            if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            // two-tone chime
            [440, 660].forEach((freq, i) => {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.3, audioCtx.currentTime + i * 0.15);
                gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + i * 0.15 + 0.4);
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start(audioCtx.currentTime + i * 0.15);
                osc.stop(audioCtx.currentTime + i * 0.15 + 0.4);
            });
        } catch (e) { /* audio not available */ }
    }

    // --- timer formatting ---
    function formatTimer(seconds) {
        const neg = seconds < 0;
        const abs = Math.abs(Math.floor(seconds));
        const m = Math.floor(abs / 60);
        const s = abs % 60;
        return (neg ? '-' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

    function getTimerState(order) {
        if (order.status !== 'preparing' || !order.prep_started) return null;
        const started = new Date(order.prep_started).getTime();
        const elapsed = (Date.now() - started) / 1000;
        const remaining = order.prep_duration - elapsed;
        return { remaining, elapsed };
    }

    function timerClass(remaining) {
        if (remaining < 0) return 'is-overdue';
        if (remaining < 120) return 'is-warn';
        return 'is-ok';
    }

    // --- rendering ---
    function typeLabel(type) {
        switch (type) {
            case 'dine-in': return 'Dine-In';
            case 'delivery': return 'Delivery';
            case 'pickup': return 'Pickup';
            default: return type;
        }
    }

    function typeClass(type) {
        switch (type) {
            case 'dine-in': return 'kds-type-dinein';
            case 'delivery': return 'kds-type-delivery';
            case 'pickup': return 'kds-type-pickup';
            default: return '';
        }
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function parseItemLine(line) {
        const m = line.match(/^(\d+) x (.+?)(?:\s*=\s*\$[\d,.]+)?$/);
        if (!m) return { qty: 1, label: line, mods: '', note: '' };
        let rest = m[2];
        let note = '';
        const noteIdx = rest.indexOf(' - note: ');
        if (noteIdx !== -1) { note = rest.substring(noteIdx + 9); rest = rest.substring(0, noteIdx); }
        let mods = '';
        const modMatch = rest.match(/^(.*)\s+\[(.+)\]$/);
        if (modMatch) { rest = modMatch[1]; mods = modMatch[2]; }
        return { qty: parseInt(m[1], 10), label: rest, mods, note };
    }

    // Always restaurant time, even on a tablet whose clock zone is set wrong.
    const restaurantTime = new Intl.DateTimeFormat('en-US', { timeZone: 'America/New_York', hour: 'numeric', minute: '2-digit' });
    function formatTime(iso) {
        const d = new Date(iso);
        return isNaN(d) ? '' : restaurantTime.format(d);
    }

    function cardStatus(order) {
        const timer = getTimerState(order);
        if (order.status === 'completed' || order.status === 'ready') return 'completed';
        if (order.status === 'preparing' && timer && timer.remaining < 0) return 'overdue';
        if (order.status === 'preparing') return 'preparing';
        return 'new';
    }

    function renderCard(order) {
        const status = cardStatus(order);
        const timer = getTimerState(order);
        const isDineIn = order.type === 'dine-in';
        const items = (order.items || []).map(parseItemLine);
        const allNotes = [order.notes, order.note].filter(Boolean).join(' | ');

        let timerHtml = '';
        let actionHtml = '';

        if (status === 'new') {
            timerHtml = `<span class="kds-received">Received ${esc(formatTime(order.time))}</span>`;
            actionHtml = `<button class="kds-btn kds-btn-primary" data-action="start" data-number="${esc(order.number)}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="5,3 19,12 5,21"/></svg> START</button>`;
        } else if (status === 'preparing' || status === 'overdue') {
            const remaining = timer ? timer.remaining : 0;
            timerHtml = `<span class="kds-timer ${timerClass(remaining)}" data-timer="${order.number}">${formatTimer(remaining)}</span>`;
            actionHtml = `<button class="kds-btn kds-btn-green" data-action="complete" data-number="${esc(order.number)}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg> DONE</button>`;
        } else {
            timerHtml = `<span class="kds-received">Completed</span>`;
            actionHtml = `<button class="kds-btn" data-action="recall" data-number="${esc(order.number)}">Recall</button>`;
        }

        let primaryText = '';
        let secondaryHtml = '';
        if (isDineIn) {
            primaryText = `Table #${order.table || '?'}`;
            secondaryHtml = `<span>Dine-In</span>`;
        } else {
            primaryText = order.name || 'Customer';
            const payLabel = order.payment === 'cash on delivery' ? 'COD' : (order.payment || '').toUpperCase();
            secondaryHtml = `<span>${esc(typeLabel(order.type))}</span>`
                + (payLabel ? `<span class="kds-pay">${esc(payLabel)}</span>` : '');
        }

        const itemsHtml = items.map(it =>
            `<li><span class="kds-item-qty">${it.qty}x</span><span class="kds-item-name">${esc(it.label)}`
            + (it.mods ? `<div class="kds-item-mods">${esc(it.mods)}</div>` : '')
            + (it.note ? `<div class="kds-item-note">${esc(it.note)}</div>` : '')
            + `</span></li>`
        ).join('');

        return `<div class="kds-card is-${status}" data-order="${esc(order.number)}" data-type="${esc(order.type)}" data-status="${status}">
            <div class="kds-card-head">
                <span class="kds-type ${typeClass(order.type)}">${esc(typeLabel(order.type))}</span>
                <span class="kds-status kds-status-${status}">${status === 'new' ? 'QUEUE' : status.toUpperCase()}</span>
                <span class="kds-order-num">#${esc(order.number)}</span>
            </div>
            <div class="kds-card-info">
                <div class="kds-card-primary">${esc(primaryText)}</div>
                <div class="kds-card-secondary">${secondaryHtml}</div>
            </div>
            <div class="kds-card-items"><ul>${itemsHtml}</ul></div>
            ${allNotes ? `<div class="kds-card-notes">${esc(allNotes)}</div>` : ''}
            <div class="kds-card-foot">${timerHtml}${actionHtml}</div>
        </div>`;
    }

    function matchFilter(order) {
        const status = cardStatus(order);
        switch (filter) {
            case 'active': return status !== 'completed';
            case 'dine-in': return order.type === 'dine-in';
            case 'delivery': return order.type === 'delivery';
            case 'pickup': return order.type === 'pickup';
            case 'queue': return status === 'new';
            case 'preparing': return status === 'preparing';
            case 'overdue': return status === 'overdue';
            case 'completed': return status === 'completed';
            default: return true;
        }
    }

    function sortOrders(list) {
        const copy = [...list];
        switch (sort) {
            case 'newest': copy.sort((a, b) => b.ts - a.ts); break;
            case 'overdue':
                copy.sort((a, b) => {
                    const sa = cardStatus(a) === 'overdue' ? 0 : 1;
                    const sb = cardStatus(b) === 'overdue' ? 0 : 1;
                    return sa - sb || a.ts - b.ts;
                });
                break;
            case 'type':
                const typeOrder = { 'dine-in': 0, 'delivery': 1, 'pickup': 2 };
                copy.sort((a, b) => (typeOrder[a.type] ?? 9) - (typeOrder[b.type] ?? 9) || a.ts - b.ts);
                break;
            default: copy.sort((a, b) => a.ts - b.ts);
        }
        return copy;
    }

    function renderAll() {
        const filtered = orders.filter(matchFilter);
        const sorted = sortOrders(filtered);

        if (sorted.length === 0) {
            grid.innerHTML = '';
            grid.appendChild(emptyEl);
            emptyEl.hidden = false;
            return;
        }

        emptyEl.hidden = true;
        const html = sorted.map(renderCard).join('');
        grid.innerHTML = html;
    }

    function updateCounters() {
        const now = Date.now();
        let queue = 0, preparing = 0, overdue = 0, completed = 0;
        for (const o of orders) {
            const s = cardStatus(o);
            if (s === 'new') queue++;
            else if (s === 'preparing') preparing++;
            else if (s === 'overdue') { preparing++; overdue++; }
            else if (s === 'completed') completed++;
        }
        setText('stat-queue', queue);
        setText('stat-preparing', preparing);
        setText('stat-overdue', overdue);
        setText('stat-completed', completed);
    }

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    // --- timer ticks ---
    function updateTimers() {
        document.querySelectorAll('[data-timer]').forEach(el => {
            const num = el.dataset.timer;
            const order = orders.find(o => o.number === num);
            if (!order) return;
            const timer = getTimerState(order);
            if (!timer) return;
            el.textContent = formatTimer(timer.remaining);
            el.className = 'kds-timer ' + timerClass(timer.remaining);

            // update card overdue state
            const card = el.closest('.kds-card');
            if (card) {
                const isOverdue = timer.remaining < 0;
                const wasOverdue = card.classList.contains('is-overdue');
                if (isOverdue && !wasOverdue) {
                    card.classList.remove('is-preparing');
                    card.classList.add('is-overdue');
                    const statusEl = card.querySelector('.kds-status');
                    if (statusEl) {
                        statusEl.textContent = 'OVERDUE';
                        statusEl.className = 'kds-status kds-status-overdue';
                    }
                }
            }
        });
        updateCounters();
    }

    // --- polling ---
    async function poll() {
        try {
            const data = await api('orders');
            if (!data) return;

            const newOrders = data.orders || [];
            settings = data.settings || settings;

            // detect new orders for sound
            const newNumbers = new Set(newOrders.map(o => o.number));
            for (const o of newOrders) {
                if (!knownNumbers.has(o.number) && knownNumbers.size > 0 &&
                    (o.status === 'new')) {
                    playAlert();
                    break;
                }
            }
            knownNumbers = newNumbers;
            orders = newOrders;

            renderAll();
            updateCounters();
            applySettingsToUI();
        } catch (e) {
            console.error('KDS poll error:', e);
        }
        pollTimeout = setTimeout(poll, 3000);
    }

    // --- actions ---
    grid.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const action = btn.dataset.action;
        const number = btn.dataset.number;
        btn.disabled = true;
        btn.style.opacity = '0.5';
        const result = await api(action, { number });
        if (result) {
            // immediate local update
            const order = orders.find(o => o.number === number);
            if (order) {
                if (action === 'start') {
                    order.status = 'preparing';
                    order.prep_started = new Date().toISOString();
                } else if (action === 'complete') {
                    order.status = 'ready';
                    order.completed_at = new Date().toISOString();
                } else if (action === 'recall') {
                    order.status = 'preparing';
                    order.completed_at = null;
                }
            }
            renderAll();
            updateCounters();
        }
        btn.disabled = false;
        btn.style.opacity = '';
    });

    // --- filter / sort ---
    document.getElementById('kds-filter').addEventListener('change', function () {
        filter = this.value;
        renderAll();
    });
    document.getElementById('kds-sort').addEventListener('change', function () {
        sort = this.value;
        renderAll();
    });

    // --- sound toggle ---
    const soundBtn = document.getElementById('kds-sound-toggle');
    soundBtn.addEventListener('click', () => {
        soundEnabled = !soundEnabled;
        soundBtn.classList.toggle('sound-off', !soundEnabled);
        soundBtn.title = soundEnabled ? 'Sound on' : 'Sound off';
        // unlock audio context on user gesture
        if (soundEnabled && !audioCtx) {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
    });

    // --- settings ---
    const settingsPanel = document.getElementById('kds-settings');
    document.getElementById('kds-settings-toggle').addEventListener('click', () => {
        settingsPanel.hidden = false;
    });
    document.getElementById('kds-settings-close').addEventListener('click', () => {
        settingsPanel.hidden = true;
    });
    document.getElementById('kds-settings-cancel').addEventListener('click', () => {
        settingsPanel.hidden = true;
        applySettingsToUI();
    });
    document.getElementById('kds-settings-save').addEventListener('click', async () => {
        const s = {
            dine_in_prep_minutes: parseInt(document.getElementById('set-dinein').value, 10) || 15,
            delivery_prep_minutes: parseInt(document.getElementById('set-delivery').value, 10) || 20,
            pickup_prep_minutes: parseInt(document.getElementById('set-pickup').value, 10) || 15,
            sound_enabled: document.getElementById('set-sound').checked,
        };
        const result = await api('settings-save', { settings: s });
        if (result) {
            settings = result.settings;
            soundEnabled = settings.sound_enabled;
            soundBtn.classList.toggle('sound-off', !soundEnabled);
        }
        settingsPanel.hidden = true;
    });

    function applySettingsToUI() {
        document.getElementById('set-dinein').value = settings.dine_in_prep_minutes || 15;
        document.getElementById('set-delivery').value = settings.delivery_prep_minutes || 20;
        document.getElementById('set-pickup').value = settings.pickup_prep_minutes || 15;
        document.getElementById('set-sound').checked = settings.sound_enabled !== false;
        soundEnabled = settings.sound_enabled !== false;
        soundBtn.classList.toggle('sound-off', !soundEnabled);
    }

    // --- boot ---
    timerInterval = setInterval(updateTimers, 1000);
    poll();
})();
