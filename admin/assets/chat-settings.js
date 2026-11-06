/* Admin → Chat settings: keeps the live preview in step with the form, the way
 * js/chat.js will show the chat on the website. Text only goes in as text. */
(function () {
  const form = document.querySelector('.cs-form');
  const pv = document.querySelector('.cs-preview');
  if (!form || !pv) return;

  const val = (name) => { const el = form.querySelector('[data-cs="' + name + '"]'); return el ? el.value : ''; };
  const all = (name) => pv.querySelectorAll('[data-pv="' + name + '"]');
  const one = (name) => pv.querySelector('[data-pv="' + name + '"]');
  const show = (name, on) => all(name).forEach((el) => { el.hidden = !on; });
  const text = (name, t) => all(name).forEach((el) => { el.textContent = t; });

  const logos = all('logo');
  const logoImg = form.querySelector('[data-cs-logo]');
  const logoInput = form.querySelector('[data-cs="logo_file"]');
  const logoDefault = form.querySelector('[data-cs="logo_default"]');
  const savedLogo = logoImg ? logoImg.src : '';
  let blobUrl = '';

  function setLogo(src) { logos.forEach((img) => { img.src = src; }); if (logoImg) logoImg.src = src; }

  function update() {
    text('title', val('title') || 'Ask RMS');
    text('status', val('status'));
    show('status', val('status').trim() !== '');
    text('welcome', val('welcome'));

    const chips = one('chips');
    chips.textContent = '';
    val('suggestions').split('\n').map((s) => s.trim()).filter(Boolean).slice(0, 6).forEach((q) => {
      const c = document.createElement('span');
      c.textContent = q;
      chips.append(c);
    });

    const gate = val('gate');
    show('gate', gate !== 'off');
    text('gateText', val('gateText'));
    show('emailOpt', gate === 'optional');
    show('skip', gate === 'optional');
    const phone = val('gatePhone');
    show('phoneRow', phone !== 'off');
    show('phoneOpt', phone === 'optional');

    const notice = val('notice').trim();
    const privacy = /^https?:\/\//i.test(val('privacyUrl').trim());
    const n = one('notice');
    n.textContent = notice;
    if (privacy) { const a = document.createElement('a'); a.textContent = 'Privacy policy'; n.append(notice ? ' ' : '', a); }
    n.hidden = !notice && !privacy;
    text('phone', val('phone'));

    const pos = val('position');
    const row = one('launchRow');
    row.hidden = pos === 'hidden' && val('autoOpen') !== 'bubble' && val('autoOpen') !== 'smart';
    row.classList.toggle('is-left', pos === 'left');
    show('button', pos !== 'hidden');
    text('button', val('button') || 'Ask us');
    const auto = val('autoOpen');
    show('bubble', auto === 'smart' || auto === 'bubble');
    text('bubble', val('bubble'));

    const off = val('enabled') === '0';
    one('chat').classList.toggle('is-off', off);
    row.classList.toggle('is-off', off);
    show('off', off);
  }

  form.addEventListener('input', update);
  form.addEventListener('change', update);
  if (logoInput) logoInput.addEventListener('change', () => {
    if (blobUrl) URL.revokeObjectURL(blobUrl);
    const f = logoInput.files && logoInput.files[0];
    blobUrl = f ? URL.createObjectURL(f) : '';
    setLogo(blobUrl || savedLogo);
    if (f && logoDefault) logoDefault.checked = false;
  });
  if (logoDefault) logoDefault.addEventListener('change', () => {
    setLogo(logoDefault.checked ? (logoImg.getAttribute('data-default') || savedLogo) : (blobUrl || savedLogo));
  });
  update();
})();
