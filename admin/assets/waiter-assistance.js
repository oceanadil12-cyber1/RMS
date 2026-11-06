(function () {
  'use strict';
  var app = document.getElementById('waiter-app');
  if (!app) return;

  var apiUrl = app.dataset.api;
  var listEl = document.getElementById('waiter-list');
  var emptyEl = document.getElementById('waiter-empty');
  var known = {};
  var audio = null;

  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  function elapsed(created) {
    var start = new Date(created.replace(' ', 'T'));
    var diff = Math.max(0, Math.floor((Date.now() - start.getTime()) / 1000));
    var m = Math.floor(diff / 60);
    var s = diff % 60;
    return pad(m) + ':' + pad(s);
  }

  function playAlert() {
    try {
      if (!audio) {
        var ac = new (window.AudioContext || window.webkitAudioContext)();
        var osc = ac.createOscillator();
        var gain = ac.createGain();
        osc.connect(gain);
        gain.connect(ac.destination);
        osc.frequency.value = 800;
        gain.gain.value = 0.3;
        osc.start();
        setTimeout(function () { osc.stop(); ac.close(); }, 300);
      }
    } catch (e) {}
  }

  function renderCard(call) {
    var div = document.createElement('div');
    div.className = 'waiter-card waiter-card--active';
    div.dataset.id = call.id;
    div.innerHTML =
      '<div class="waiter-card-header">' +
        '<span class="waiter-card-badge">WAITER REQUEST</span>' +
      '</div>' +
      '<div class="waiter-card-body">' +
        '<div class="waiter-card-table">Table ' + call.table_number + '</div>' +
        '<div class="waiter-card-name">Customer: ' + call.customer_name + '</div>' +
        '<div class="waiter-card-timer">Waiting: <span class="waiter-timer" data-created="' + call.created_at + '">' + elapsed(call.created_at) + '</span></div>' +
      '</div>' +
      '<div class="waiter-card-actions">' +
        '<button class="btn danger waiter-resolve" data-id="' + call.id + '">Resolved</button>' +
      '</div>';
    return div;
  }

  function resolve(id) {
    fetch(apiUrl + '&do=resolve', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: id })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.ok) {
        var card = listEl.querySelector('[data-id="' + id + '"]');
        if (card) card.remove();
        delete known[id];
        if (Object.keys(known).length === 0) emptyEl.hidden = false;
      }
    });
  }

  listEl.addEventListener('click', function (e) {
    var btn = e.target.closest('.waiter-resolve');
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = 'Resolving…';
    resolve(+btn.dataset.id);
  });

  function poll() {
    fetch(apiUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) return;
        var calls = data.calls || [];
        var activeIds = {};

        calls.forEach(function (call) {
          activeIds[call.id] = true;
          if (!known[call.id]) {
            listEl.appendChild(renderCard(call));
            known[call.id] = true;
            playAlert();
          }
        });

        Object.keys(known).forEach(function (id) {
          if (!activeIds[id]) {
            var card = listEl.querySelector('[data-id="' + id + '"]');
            if (card) card.remove();
            delete known[id];
          }
        });

        emptyEl.hidden = calls.length > 0;
      });
  }

  // Update all visible timers every second
  setInterval(function () {
    var timers = listEl.querySelectorAll('.waiter-timer');
    for (var i = 0; i < timers.length; i++) {
      timers[i].textContent = elapsed(timers[i].dataset.created);
    }
  }, 1000);

  // Poll for new requests every 5 seconds
  poll();
  setInterval(function () { if (!document.hidden) poll(); }, 5000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();
