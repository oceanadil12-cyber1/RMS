/* Gallery Instagram feed paging: shows the first 20 posts, then 8 more each time
 * "See More Posts" is clicked (counts come from data-ig-first / data-ig-step).
 *
 * The Elfsight widget loads posts in batches of its own size (9), so this script
 * asks it for more batches in the background as needed, hides anything past the
 * current count, and swaps the widget's own button for ours. The grid is set to 4
 * columns (2 on phones) so 20 and 8 always fill whole rows. */
(function () {
  const feed = document.querySelector('[data-ig-paged]');
  const more = document.querySelector('[data-ig-more]');
  if (!feed || !more) return;

  const STEP = +feed.dataset.igStep || 8;
  let shown = +feed.dataset.igFirst || 20;
  let root = null;
  const label = more.textContent;

  const STYLE = `
    [data-ig-hidden] { display: none !important; }
    [class*="es-load-more-button"] { display: none !important; }
    .es-grid-layout { grid-template-columns: repeat(4, minmax(0, 1fr)) !important; }
    @media (max-width: 768px) {
      .es-grid-layout { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
    }`;

  const grid = () => root && root.querySelector('.es-grid-layout');
  const posts = () => (grid() ? [...grid().children] : []);
  const widgetButton = () => root && root.querySelector('[class*="es-load-more-button"] button');

  // Show the first `shown` posts; keep our button only while there's more to see.
  function apply() {
    posts().forEach((post, i) => post.toggleAttribute('data-ig-hidden', i >= shown));
    more.hidden = !(posts().length > shown || widgetButton());
  }

  // Ask the widget for its next batch and wait until it has rendered.
  function loadBatch() {
    const button = widgetButton();
    if (!button) return Promise.resolve(false);
    const before = posts().length;
    button.click();
    return new Promise((resolve) => {
      const started = Date.now();
      (function check() {
        if (posts().length > before) return resolve(true);
        if (Date.now() - started > 8000) return resolve(false);
        setTimeout(check, 150);
      })();
    });
  }

  // Load batches until there are enough posts for `shown` (one run at a time; a
  // running pass picks up a raised `shown` because it re-reads it each loop).
  let filling = null;
  function fill() {
    if (!filling) {
      filling = (async () => {
        while (posts().length < shown && widgetButton()) {
          if (!(await loadBatch())) break;
        }
        apply();
      })().finally(() => { filling = null; });
    }
    return filling;
  }

  // The widget renders its button a moment after its grid, so keep checking as it
  // changes and top up whenever fewer posts are loaded than should be shown.
  function onWidgetChange() {
    apply();
    if (!filling && posts().length < shown && widgetButton()) fill();
  }

  more.addEventListener('click', async () => {
    shown += STEP;
    more.disabled = true;
    more.textContent = 'Loading…';
    await fill();
    more.disabled = false;
    more.textContent = label;
  });

  // The widget loads late (js/main.js loads Elfsight once the feed is near the
  // screen); wait for its grid, then take over.
  const wait = setInterval(() => {
    const host = feed.querySelector('.es-embed-root');
    root = host && host.shadowRoot;
    if (!grid()) return;
    clearInterval(wait);
    const style = document.createElement('style');
    style.textContent = STYLE;
    root.appendChild(style);
    new MutationObserver(onWidgetChange).observe(root, { childList: true, subtree: true });
    fill();
  }, 300);
})();
