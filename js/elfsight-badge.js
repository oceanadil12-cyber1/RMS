/* Hides the "Free … Widget" badge that Elfsight's free plan adds to its widgets
 * (Instagram feed, Google Reviews). The badge lives inside each widget's shadow
 * root with forced inline styles, so page CSS can't reach it; this script finds it,
 * overrides its display, and watches for the widget re-rendering it.
 * Note: the badge is part of Elfsight's free-plan terms. A paid Elfsight plan
 * removes it officially, after which this file can be deleted. */
(function () {
  const BADGE = 'a[href*="elfsight.com"][href*="utm_medium=clients"]';
  const watched = new WeakSet();

  function hide(root) {
    root.querySelectorAll(BADGE).forEach((badge) => {
      if (badge.style.getPropertyValue('display') !== 'none') {
        badge.style.setProperty('display', 'none', 'important');
      }
    });
  }

  function scan() {
    document.querySelectorAll('[class*="elfsight-app-"] .es-embed-root').forEach((host) => {
      const root = host.shadowRoot;
      if (!root || watched.has(root)) return;
      watched.add(root);
      hide(root);
      new MutationObserver(() => hide(root)).observe(root, {
        childList: true, subtree: true, attributes: true, attributeFilter: ['style'],
      });
    });
  }

  // Widgets load late (and only once scrolled near), so keep looking for new ones.
  new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
  scan();
})();
