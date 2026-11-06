/* Home page "Upcoming Events" slider: every show in the Weekly Lineup on events.html,
 * starting with the next one (restaurant time), scrolling right to left in a loop.
 * The lineup is edited in the admin (Events), which writes events.html; one-night
 * events (data-date) show only on their own date. The slider pauses on hover or keyboard
 * focus. If JavaScript is off, the fetch fails, or the visitor prefers reduced
 * motion, the cards stay in a row they can swipe. */
(function () {
  const slider = document.querySelector('[data-ev-slider]');
  if (!slider) return;
  const track = slider.querySelector('.ev-slider-track');

  const TZ = 'America/New_York';
  const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
  const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  const SPEED = 45; // pixels per second

  // The restaurant's current wall-clock date and time, as a local Date.
  function restaurantNow() {
    const parts = {};
    new Intl.DateTimeFormat('en-US', {
      timeZone: TZ, year: 'numeric', month: 'numeric', day: 'numeric',
      hour: 'numeric', minute: 'numeric', hourCycle: 'h23',
    }).formatToParts(new Date()).forEach((p) => { parts[p.type] = p.value; });
    return new Date(+parts.year, parts.month - 1, +parts.day, +parts.hour, +parts.minute);
  }

  // "8:30 PM" -> minutes after midnight.
  function toMinutes(time) {
    const m = /(\d{1,2})(?::(\d{2}))?\s*(AM|PM)/i.exec(time || '');
    if (!m) return null;
    return ((+m[1] % 12) + (/pm/i.test(m[3]) ? 12 : 0)) * 60 + (+m[2] || 0);
  }

  // Each day's cards from events.html, earliest first.
  function readLineup(html) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const lineup = {};
    doc.querySelectorAll('.ev-day[data-day]').forEach((day) => {
      lineup[day.dataset.day] = [...day.querySelectorAll('.ev-card')]
        .map((card) => ({ card, start: toMinutes(card.querySelector('.ev-time')?.textContent), only: card.dataset.date || '' }))
        .filter((ev) => ev.start !== null)
        .sort((a, b) => a.start - b.start);
    });
    return lineup;
  }

  // The whole week in order from now: tonight's remaining shows, the next six days,
  // then next week's showing of anything that already started today.
  function weekFromNow(lineup) {
    const now = restaurantNow();
    const nowMin = now.getHours() * 60 + now.getMinutes();
    const shows = [];
    for (let i = 0; i <= 7; i++) {
      const date = new Date(now.getFullYear(), now.getMonth(), now.getDate() + i);
      const key = DAY_KEYS[date.getDay()];
      const iso = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
      (lineup[key] || []).forEach((ev) => {
        if (ev.only && ev.only !== iso) return; // a one-night event shows on its own date only
        const upcoming = ev.start > nowMin;
        if ((i === 0 && upcoming) || (i > 0 && i < 7) || (i === 7 && !upcoming)) {
          shows.push({ ...ev, date, key, tonight: i === 0 });
        }
      });
    }
    return shows;
  }

  function slide(ev) {
    const link = document.createElement('a');
    link.className = 'ev-slide';
    link.href = 'events.html#ev-' + ev.key;
    const card = document.importNode(ev.card, true);
    card.querySelector('.ev-date')?.remove(); // the time badge below already carries the day and date
    const badge = card.querySelector('.ev-time');
    const when = ev.tonight ? 'Tonight' : DAY_NAMES[ev.date.getDay()] + ' ' + ev.date.getDate();
    badge.textContent = when + ' · ' + badge.textContent.trim();
    card.classList.toggle('is-tonight', ev.tonight);
    card.querySelectorAll('img').forEach((img) => { img.loading = 'lazy'; });
    link.appendChild(card);
    return link;
  }

  // A copy of the slides follows the originals so the loop has no seam; the copy is
  // hidden from screen readers and skipped by the keyboard.
  function build(shows) {
    const slides = shows.map(slide);
    track.replaceChildren(...slides);
    slides.forEach((s) => {
      const copy = s.cloneNode(true);
      copy.setAttribute('aria-hidden', 'true');
      copy.tabIndex = -1;
      track.appendChild(copy);
    });
  }

  function start() {
    // One loop = the distance from the first slide to its copy.
    const half = track.children.length / 2;
    const loop = track.children[half].offsetLeft - track.children[0].offsetLeft;
    slider.style.setProperty('--ev-loop', loop + 'px');
    slider.style.setProperty('--ev-duration', Math.round(loop / SPEED) + 's');
    slider.classList.add('is-running');
  }

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  fetch('events.html')
    .then((res) => (res.ok ? res.text() : Promise.reject(res.status)))
    .then((html) => {
      const shows = weekFromNow(readLineup(html));
      if (!shows.length) return;
      if (reduceMotion.matches) {
        track.replaceChildren(...shows.map(slide));
        return;
      }
      build(shows);
      start();
      // Card widths change at the phone breakpoint, so re-measure the loop.
      let t;
      window.addEventListener('resize', () => { clearTimeout(t); t = setTimeout(start, 200); });
    })
    .catch(() => { /* keep the static weekly highlights */ });
})();
