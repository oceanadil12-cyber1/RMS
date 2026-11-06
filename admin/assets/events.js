/* Add / edit an event: the "how often" switch, tag buttons, the live preview card and
   the poster editor. The editor takes any picture, lets you drag and zoom it inside
   a 480 x 600 frame, and puts the cropped result (WebP, or JPG if the browser can't
   make WebP) into the form's file field, so the server always gets the exact size.
   Without JavaScript the form still works, but the picture must already be 480 x 600. */
(() => {
  'use strict';
  const form = document.querySelector('form[data-event-form]');
  if (!form) return;
  const $ = (s, r = form) => r.querySelector(s);
  const $$ = (s, r = form) => [...r.querySelectorAll(s)];

  // ----- How often: show the day buttons or the date field -----
  const repeats = $$('input[name="repeat"]');
  const whens = $$('[data-when]');
  const syncWhen = () => {
    const v = (repeats.find((r) => r.checked) || {}).value || 'weekly';
    whens.forEach((b) => { b.hidden = b.dataset.when !== v; });
  };
  repeats.forEach((r) => r.addEventListener('change', () => { syncWhen(); syncPreview(); }));

  const dateInput = form.elements.date;
  const weekday = $('[data-weekday]');
  const dateOf = (v) => new Date(v + 'T12:00:00');
  const syncDate = () => {
    const v = dateInput.value;
    weekday.textContent = v ? dateOf(v).toLocaleDateString('en-US', { weekday: 'long' }) : '';
  };
  dateInput.addEventListener('input', () => { syncDate(); syncPreview(); });

  // ----- Tags: buttons for tags used before -----
  const tagsInput = $('[data-tags]');
  const chips = $$('.tagchip');
  const tagList = () => tagsInput.value.split(/[,\n]+/).map((s) => s.trim()).filter(Boolean);
  const syncChips = () => {
    const have = tagList().map((t) => t.toLowerCase());
    chips.forEach((c) => c.classList.toggle('on', have.includes(c.dataset.tag.toLowerCase())));
  };
  chips.forEach((c) => c.addEventListener('click', () => {
    const list = tagList();
    const at = list.findIndex((t) => t.toLowerCase() === c.dataset.tag.toLowerCase());
    if (at >= 0) list.splice(at, 1); else list.push(c.dataset.tag);
    tagsInput.value = list.join(', ');
    syncChips();
    syncPreview();
  }));
  tagsInput.addEventListener('input', () => { syncChips(); syncPreview(); });

  // ----- Live preview card (same wording rules as the published page) -----
  const pv = {
    title: $('[data-pv-title]'), host: $('[data-pv-host]'), desc: $('[data-pv-desc]'), tags: $('[data-pv-tags]'),
    time: $('[data-pv-time]'), date: $('[data-pv-date]'),
  };
  const t12 = (v) => {
    const m = /^(\d{2}):(\d{2})$/.exec(v);
    if (!m) return '7:00 PM';
    const h = +m[1];
    return ((h % 12) || 12) + ':' + m[2] + ' ' + (h >= 12 ? 'PM' : 'AM');
  };
  function syncPreview() {
    const f = form.elements;
    pv.title.textContent = f.title.value.trim() || 'Event title';
    const name = f.host.value.trim();
    const style = f.hostStyle.value;
    pv.host.textContent = name ? (style === 'hosted' ? 'Hosted by ' + name : style === 'with' ? 'with ' + name : name) : '';
    pv.host.hidden = !name;
    pv.desc.textContent = f.desc.value.trim() || 'A short description of the night appears here.';
    pv.tags.replaceChildren(...tagList().slice(0, 6).map((t) => { const li = document.createElement('li'); li.textContent = t; return li; }));
    pv.time.textContent = t12(f.time.value);
    const once = (repeats.find((r) => r.checked) || {}).value === 'once' && f.date.value;
    pv.date.hidden = !once;
    if (once) pv.date.textContent = dateOf(f.date.value).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
  }
  ['title', 'host', 'hostStyle', 'desc', 'time'].forEach((n) => form.elements[n].addEventListener('input', syncPreview));

  // ----- Poster editor -----
  const box = $('[data-poster]');
  const W = +box.dataset.w;
  const H = +box.dataset.h;
  const stage = $('[data-stage]', box);
  const canvas = $('[data-canvas]', box);
  const current = $('[data-current]', box);
  const empty = $('[data-empty]', box);
  const input = $('#poster_file');
  const zoomWrap = $('[data-zoomwrap]', box);
  const zoomInput = $('[data-zoom]', box);
  const msg = $('[data-msg]', box);
  const guides = $$('[data-guide]', box);
  const guidesToggle = $('[data-guides]', box);
  const chooseLabel = $('[data-choose]', box);
  const pvImg = $('[data-pv-img]');
  const pvCanvas = $('[data-pv-canvas]');
  const pvEmpty = $('[data-pv-empty]');
  const ctx = canvas.getContext('2d');

  let img = null;          // the picture being placed
  let base = 1;            // scale that just covers the frame
  let zoom = 1;            // 1 = covers the frame, up to 4
  let x = 0;               // where the picture's top-left sits inside the frame
  let y = 0;
  let timer = 0;
  let pending = null;      // resolves once the file field holds the latest crop
  let resolvePending = null;

  const say = (text, kind) => { msg.textContent = text; msg.className = 'poster-msg' + (kind ? ' ' + kind : ''); };
  const syncGuides = () => {
    const has = !!img || !current.hidden;
    guides.forEach((g) => { g.hidden = !(has && guidesToggle.checked); });
  };
  const clamp = () => {
    const s = base * zoom;
    x = Math.min(0, Math.max(W - img.naturalWidth * s, x));
    y = Math.min(0, Math.max(H - img.naturalHeight * s, y));
  };
  const draw = () => {
    const s = base * zoom;
    ctx.fillStyle = '#111';
    ctx.fillRect(0, 0, W, H);
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(img, x, y, img.naturalWidth * s, img.naturalHeight * s);
    // What the Events page shows: a square window at the bottom of the poster.
    pvCanvas.getContext('2d').drawImage(canvas, 0, H - W, W, W, 0, 0, pvCanvas.width, pvCanvas.height);
  };

  // Put the current crop into the form's file field.
  const render = () => new Promise((resolve) => {
    const done = (blob, name) => {
      if (!blob) { say('The picture couldn\'t be prepared. Try a different one.', 'err'); return resolve(); }
      const dt = new DataTransfer();
      dt.items.add(new File([blob], name, { type: blob.type }));
      input.files = dt.files;
      say('Ready: ' + W + ' × ' + H + ' ' + (blob.type === 'image/webp' ? 'WebP' : 'JPG') + ', ' + Math.max(1, Math.round(blob.size / 1024)) + ' KB. Save to keep it.', 'ok');
      resolve();
    };
    canvas.toBlob((blob) => {
      if (blob && blob.type === 'image/webp') return done(blob, 'poster.webp');
      canvas.toBlob((b) => done(b, 'poster.jpg'), 'image/jpeg', 0.9); // browsers that can't make WebP
    }, 'image/webp', 0.86);
  });
  const settle = () => { const r = resolvePending; pending = null; resolvePending = null; if (r) r(); };
  const schedule = (delay = 250) => {
    if (!pending) pending = new Promise((r) => { resolvePending = r; });
    clearTimeout(timer);
    timer = setTimeout(() => render().then(settle), delay);
  };

  const load = (file) => {
    if (!file) return;
    if (!/^image\//.test(file.type) && !/\.(webp|jpe?g|png|gif|bmp)$/i.test(file.name)) { say('That file isn\'t a picture. Choose a WebP, JPG or PNG.', 'err'); input.value = ''; return; }
    if (file.size > 25e6) { say('That picture is very large (over 25 MB). Choose a smaller one.', 'err'); input.value = ''; return; }
    if (!pending) pending = new Promise((r) => { resolvePending = r; }); // hold the form until the crop is ready
    const url = URL.createObjectURL(file);
    const im = new Image();
    im.onload = () => {
      URL.revokeObjectURL(url);
      img = im;
      base = Math.max(W / im.naturalWidth, H / im.naturalHeight);
      zoom = 1;
      zoomInput.value = 1;
      x = (W - im.naturalWidth * base) / 2;
      y = (H - im.naturalHeight * base) / 2;
      current.hidden = true;
      canvas.hidden = false;
      empty.hidden = true;
      zoomWrap.hidden = false;
      stage.classList.add('has-canvas');
      chooseLabel.textContent = 'Choose a different picture';
      pvImg.hidden = true;
      pvEmpty.hidden = true;
      pvCanvas.hidden = false;
      syncGuides();
      draw();
      schedule(0);
      const small = im.naturalWidth < W || im.naturalHeight < H;
      say(small ? 'This picture is small (' + im.naturalWidth + ' × ' + im.naturalHeight + '), so it will look soft. A bigger one is better.'
        : 'Drag the picture to choose what shows, and zoom to fit. It is cropped to ' + W + ' × ' + H + ' for you.', small ? 'warn' : '');
    };
    im.onerror = () => {
      URL.revokeObjectURL(url);
      input.value = '';
      say('That picture couldn\'t be opened. Try a different file.', 'err');
      settle();
    };
    im.src = url;
  };

  input.addEventListener('change', () => { if (input.files[0]) load(input.files[0]); });
  ['dragenter', 'dragover'].forEach((t) => stage.addEventListener(t, (e) => { e.preventDefault(); stage.classList.add('over'); }));
  ['dragleave', 'drop'].forEach((t) => stage.addEventListener(t, (e) => { e.preventDefault(); stage.classList.remove('over'); }));
  stage.addEventListener('drop', (e) => { const f = e.dataTransfer && e.dataTransfer.files[0]; if (f) load(f); });
  guidesToggle.addEventListener('change', syncGuides);

  // Drag to move.
  let drag = null;
  stage.addEventListener('pointerdown', (e) => {
    if (!img) return;
    drag = { id: e.pointerId, px: e.clientX, py: e.clientY };
    stage.setPointerCapture(e.pointerId);
    stage.classList.add('dragging');
  });
  stage.addEventListener('pointermove', (e) => {
    if (!drag || e.pointerId !== drag.id) return;
    const k = W / stage.getBoundingClientRect().width;
    x += (e.clientX - drag.px) * k;
    y += (e.clientY - drag.py) * k;
    drag.px = e.clientX;
    drag.py = e.clientY;
    clamp();
    draw();
  });
  const endDrag = (e) => {
    if (!drag || e.pointerId !== drag.id) return;
    drag = null;
    stage.classList.remove('dragging');
    schedule();
  };
  stage.addEventListener('pointerup', endDrag);
  stage.addEventListener('pointercancel', endDrag);

  // Zoom about the middle of the frame.
  const setZoom = (z) => {
    if (!img) return;
    const old = base * zoom;
    const cx = (W / 2 - x) / old;
    const cy = (H / 2 - y) / old;
    zoom = Math.min(4, Math.max(1, z));
    const s = base * zoom;
    x = W / 2 - cx * s;
    y = H / 2 - cy * s;
    zoomInput.value = zoom;
    clamp();
    draw();
    schedule();
  };
  zoomInput.addEventListener('input', () => setZoom(+zoomInput.value));
  stage.addEventListener('wheel', (e) => { if (!img) return; e.preventDefault(); setZoom(zoom * (e.deltaY < 0 ? 1.06 : 1 / 1.06)); }, { passive: false });
  stage.addEventListener('keydown', (e) => {
    if (!img) return;
    const step = e.shiftKey ? 24 : 8;
    const move = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] }[e.key];
    if (move) { e.preventDefault(); x += move[0]; y += move[1]; clamp(); draw(); schedule(); }
    else if (e.key === '+' || e.key === '=') { e.preventDefault(); setZoom(zoom * 1.1); }
    else if (e.key === '-') { e.preventDefault(); setZoom(zoom / 1.1); }
  });

  // Don't send the form until the latest crop is in the file field.
  form.addEventListener('submit', (e) => {
    if (!pending) return;
    e.preventDefault();
    const by = e.submitter;
    clearTimeout(timer);
    (img ? render() : Promise.resolve()).then(() => {
      settle();
      if (form.requestSubmit) { form.requestSubmit(by || undefined); return; }
      if (by && by.name) { // older browsers: keep the button that was pressed
        const h = document.createElement('input');
        h.type = 'hidden';
        h.name = by.name;
        h.value = by.value;
        form.append(h);
      }
      form.submit();
    });
  });

  syncWhen();
  syncDate();
  syncChips();
  syncGuides();
})();
