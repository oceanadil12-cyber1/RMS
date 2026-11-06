/* Weekly Lineup: turns the seven day blocks into tabs, opening on today
 * (restaurant time). Without JavaScript every day simply shows in order. */
(function () {
  const tabs = document.querySelector('.ev-tabs');
  if (!tabs) return;
  const buttons = [...tabs.querySelectorAll('[role="tab"]')];
  const panels = [...document.querySelectorAll('.ev-day')];
  const glanceDays = [...document.querySelectorAll('.ev-glance-day')];

  // One-night events carry data-date="YYYY-MM-DD" and drop off the page once that night is
  // over (a night runs until 4 AM, restaurant time). The tab counts follow.
  const night = {};
  new Intl.DateTimeFormat('en-US', { timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', hourCycle: 'h23' })
    .formatToParts(new Date()).forEach((p) => { night[p.type] = p.value; });
  const tonight = new Date(Date.UTC(+night.year, +night.month - 1, +night.day - (+night.hour < 4 ? 1 : 0))).toISOString().slice(0, 10);
  const over = [...document.querySelectorAll('[data-date]')].filter((el) => el.dataset.date < tonight);
  if (over.length) {
    over.forEach((el) => el.remove());
    panels.forEach((panel) => {
      const n = panel.querySelectorAll('.ev-card').length;
      const count = tabs.querySelector('[data-day="' + panel.dataset.day + '"] .ev-tab-count');
      if (count) count.textContent = n + (n === 1 ? ' event' : ' events');
      if (!n && !panel.querySelector('.ev-empty')) {
        const none = document.createElement('p');
        none.className = 'ev-empty';
        none.textContent = 'No shows are scheduled for ' + panel.querySelector('.ev-day-title').textContent + ' yet. Check back soon!';
        panel.querySelector('.ev-grid')?.remove();
        panel.append(none);
      }
    });
    glanceDays.forEach((g) => {
      const list = g.querySelector('ul');
      if (list && !list.children.length) {
        const li = document.createElement('li');
        li.className = 'ev-empty';
        li.textContent = 'Nothing scheduled yet';
        list.append(li);
      }
    });
  }

  const today = new Intl.DateTimeFormat('en-US', { timeZone: 'America/New_York', weekday: 'short' })
    .format(new Date()).toLowerCase().slice(0, 3);

  function select(day, focus) {
    buttons.forEach((b) => {
      const on = b.dataset.day === day;
      b.setAttribute('aria-selected', on);
      b.tabIndex = on ? 0 : -1;
      b.classList.toggle('is-active', on);
      if (on && focus) b.focus();
    });
    panels.forEach((p) => { p.hidden = p.dataset.day !== day; });
    glanceDays.forEach((g) => g.classList.toggle('is-active', g.dataset.day === day));
  }

  // Label today's tab and glance column.
  const todayBtn = buttons.find((b) => b.dataset.day === today);
  if (todayBtn) {
    todayBtn.classList.add('is-today');
    todayBtn.querySelector('.ev-tab-count').textContent = 'Tonight';
  }
  glanceDays.forEach((g) => { if (g.dataset.day === today) g.classList.add('is-today'); });

  document.body.classList.add('ev-tabbed');
  tabs.hidden = false;
  // A link like events.html#ev-mon opens that day; otherwise open on today.
  const hashDay = (location.hash.match(/^#ev-(\w{3})$/) || [])[1];
  const startDay = buttons.some((b) => b.dataset.day === hashDay) ? hashDay
    : todayBtn ? today : buttons[0].dataset.day;
  select(startDay);
  if (startDay === hashDay) tabs.scrollIntoView({ block: 'start' });

  tabs.addEventListener('click', (e) => {
    const b = e.target.closest('[role="tab"]');
    if (b) select(b.dataset.day);
  });
  tabs.addEventListener('keydown', (e) => {
    const i = buttons.indexOf(document.activeElement);
    if (i < 0) return;
    const step = { ArrowRight: 1, ArrowLeft: -1 }[e.key];
    if (step) { e.preventDefault(); select(buttons[(i + step + buttons.length) % buttons.length].dataset.day, true); }
    if (e.key === 'Home') { e.preventDefault(); select(buttons[0].dataset.day, true); }
    if (e.key === 'End') { e.preventDefault(); select(buttons[buttons.length - 1].dataset.day, true); }
  });

  // "Week at a glance" links open that day's tab and scroll to the tabs.
  document.querySelectorAll('[data-goto]').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopImmediatePropagation(); // skip main.js's generic smooth-scroll (panel may be hidden)
    select(a.dataset.goto);
    tabs.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }, true));
})();
