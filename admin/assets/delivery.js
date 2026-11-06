/*
 * Delivery page (admin/?page=delivery):
 *  - zone form: show only the fields for the chosen zone type; preview a distance zone's circle;
 *    draw a "drawn area" zone by clicking corners on the map
 *  - overview: the zones map (restaurant pin you can drag, circles, drawn areas, the checked address)
 *  - Google key field only when Google lookups are picked
 * Maps use Leaflet (assets/vendor/leaflet) with OpenStreetMap tiles.
 */
(() => {
  'use strict';

  // ----- Fields per zone type (data-for-type="radius polygon" = shown for either) -----
  const types = [...document.querySelectorAll('[data-zone-type]')];
  const pickedType = () => (types.find((r) => r.checked) || {}).value || 'postal';
  const syncType = () => {
    const t = pickedType();
    document.querySelectorAll('[data-for-type]').forEach((el) => { el.hidden = !el.dataset.forType.split(' ').includes(t); });
    document.dispatchEvent(new CustomEvent('zonetype', { detail: t }));
  };
  types.forEach((r) => r.addEventListener('change', syncType));

  const geocoder = document.querySelector('[data-geocoder]');
  const keyField = document.querySelector('[data-google-key]');
  if (geocoder && keyField) geocoder.addEventListener('change', () => { keyField.hidden = geocoder.value !== 'google'; });

  if (!window.L) { if (types.length) syncType(); return; }

  // ----- Shared map helpers -----
  const newMap = (el) => {
    const map = L.map(el, { scrollWheelZoom: false });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors',
      referrerPolicy: 'strict-origin-when-cross-origin', // OpenStreetMap asks for a referrer; the admin sends none by default
    }).addTo(map);
    map.on('focus', () => map.scrollWheelZoom.enable());
    map.on('blur', () => map.scrollWheelZoom.disable());
    return map;
  };
  const zoneStyle = (z, faint) => ({
    color: z.color, weight: faint ? 1 : 2, opacity: faint ? 0.5 : 0.9,
    fillColor: z.color, fillOpacity: faint ? 0.05 : (z.active ? 0.15 : 0.04), dashArray: z.active ? null : '6 6',
  });
  const popup = (z) => {
    const box = document.createElement('div');
    const b = document.createElement('b'); b.textContent = z.name; box.appendChild(b);
    const p = document.createElement('div'); p.textContent = z.terms + (z.active ? '' : ' · paused'); box.appendChild(p);
    if (z.url) { const a = document.createElement('a'); a.href = z.url; a.textContent = 'Edit zone'; box.appendChild(a); }
    return box;
  };
  const restaurantIcon = L.divIcon({ className: 'dmap-home', html: '<span>&#127860;</span>', iconSize: [30, 30], iconAnchor: [15, 15] });
  // Area a circle covers, worked out from its radius (a circle's own getBounds needs a map view first).
  const circleBounds = (latlng, metres) => L.latLng(latlng).toBounds(metres * 2);
  // Zoom to show everything listed (bounds or polygons/markers); otherwise centre on the restaurant (or the world).
  const fit = (map, items, center) => {
    let all = null;
    items.filter(Boolean).forEach((it) => {
      const b = it instanceof L.LatLngBounds ? it : (it.getBounds ? it.getBounds() : L.latLng(it.getLatLng()).toBounds(400));
      if (!b.isValid()) return;
      all = all ? all.extend(b) : L.latLngBounds(b.getSouthWest(), b.getNorthEast());
    });
    if (all) map.fitBounds(all.pad(0.15), { maxZoom: 15 });
    else map.setView(center || [20, 0], center ? 13 : 2);
  };

  // ----- Overview map -----
  const overview = document.querySelector('[data-delivery-map]');
  if (overview) {
    const d = JSON.parse(overview.dataset.deliveryMap);
    const map = newMap(overview);
    const legend = document.querySelector('[data-map-legend]');
    const layers = [];
    const circles = [];
    d.zones.forEach((z) => {
      let layer = null;
      let bounds = null;
      if (z.type === 'radius' && d.origin) { layer = L.circle(d.origin, { radius: z.radius, ...zoneStyle(z) }); circles.push(layer); bounds = () => circleBounds(layer.getLatLng(), z.radius); }
      if (z.type === 'polygon' && z.polygon.length >= 3) { layer = L.polygon(z.polygon, zoneStyle(z)); bounds = () => layer.getBounds(); }
      if (layer) { layer.bindPopup(popup(z)).addTo(map); layers.push(bounds()); }
      if (legend && layer) {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'dmap-key' + (z.active ? '' : ' is-off');
        item.innerHTML = '<i></i>';
        item.querySelector('i').style.background = z.color;
        item.append(z.name);
        item.addEventListener('click', () => { map.fitBounds(bounds().pad(0.1)); layer.openPopup(); });
        legend.appendChild(item);
      }
    });

    // The restaurant: drag the pin, then "Save location" in the Restaurant location card.
    const start = d.origin || d.center;
    if (start) {
      const pin = L.marker(start, { draggable: true, icon: restaurantIcon, title: 'Restaurant (drag to move)', zIndexOffset: 1000 }).addTo(map);
      pin.bindTooltip('Restaurant', { direction: 'top', offset: [0, -14] });
      const latInput = document.querySelector('input[name="lat"]');
      const lngInput = document.querySelector('input[name="lng"]');
      const hint = document.querySelector('[data-origin-hint]');
      pin.on('drag', () => circles.forEach((c) => c.setLatLng(pin.getLatLng())));
      pin.on('dragend', () => {
        const p = pin.getLatLng();
        if (latInput) latInput.value = p.lat.toFixed(6);
        if (lngInput) lngInput.value = p.lng.toFixed(6);
        if (hint) { hint.textContent = 'New restaurant location ' + p.lat.toFixed(5) + ', ' + p.lng.toFixed(5) + ': press Save location below to keep it.'; hint.classList.add('is-changed'); }
      });
      layers.push(circleBounds(start, 300)); // keep the restaurant in view
    }

    if (d.check) {
      const m = L.circleMarker([d.check.lat, d.check.lng], { radius: 8, color: '#fff', weight: 2, fillColor: '#e5484d', fillOpacity: 1 }).addTo(map);
      m.bindTooltip(d.check.label, { permanent: true, direction: 'top', offset: [0, -8] });
      layers.push(circleBounds([d.check.lat, d.check.lng], 200));
    }
    fit(map, layers, d.center);
  }

  // ----- Zone form map: circle preview and drawing an area -----
  const zoneEl = document.querySelector('[data-zone-map]');
  if (zoneEl) {
    const d = JSON.parse(zoneEl.dataset.zoneMap);
    const map = newMap(zoneEl);
    const others = [];
    d.zones.filter((z) => z.id !== d.zone.id).forEach((z) => {
      let layer = null;
      if (z.type === 'radius' && d.origin) { layer = L.circle(d.origin, { radius: z.radius, ...zoneStyle(z, true), interactive: false }); others.push(circleBounds(d.origin, z.radius)); }
      if (z.type === 'polygon' && z.polygon.length >= 3) { layer = L.polygon(z.polygon, { ...zoneStyle(z, true), interactive: false }); others.push(layer.getBounds()); }
      if (layer) layer.addTo(map);
    });
    const home = d.origin ? L.marker(d.origin, { icon: restaurantIcon, interactive: false }).addTo(map) : null;
    const gold = { color: '#c8a84e', weight: 3, fillColor: '#c8a84e', fillOpacity: 0.18 };

    // Distance zone: the circle follows the "Reaches up to" field.
    const radiusInput = document.querySelector('input[name="radius"]');
    const circle = d.origin ? L.circle(d.origin, { radius: 0, ...gold, interactive: false }) : null;
    const syncCircle = () => {
      if (!circle) return;
      const r = parseFloat(String(radiusInput && radiusInput.value || '').replace(',', '.'));
      circle.setRadius(r > 0 ? r * d.metresPerUnit : 0);
    };
    if (radiusInput) radiusInput.addEventListener('input', () => { syncCircle(); if (circle && circle.getRadius()) map.fitBounds(circleBounds(d.origin, circle.getRadius()).pad(0.1)); });

    // Drawn area: click the map to add corners; drag a corner to move it; click a corner to remove it.
    const field = document.querySelector('[data-polygon]');
    const count = document.querySelector('[data-poly-count]');
    let points = (d.zone.polygon || []).map((p) => L.latLng(p[0], p[1]));
    const shape = L.polygon([], { ...gold, interactive: false });
    const corners = L.layerGroup();
    const cornerIcon = L.divIcon({ className: 'dmap-vertex', iconSize: [14, 14], iconAnchor: [7, 7] });
    const redraw = () => {
      shape.setLatLngs(points);
      corners.clearLayers();
      points.forEach((p, i) => {
        const c = L.marker(p, { draggable: true, icon: cornerIcon, title: 'Drag to move, click to remove' });
        c.on('drag', () => { points[i] = c.getLatLng(); shape.setLatLngs(points); });
        c.on('dragend', save);
        c.on('click', () => { points.splice(i, 1); redraw(); save(); });
        corners.addLayer(c);
      });
    };
    const save = () => {
      if (field) field.value = JSON.stringify(points.map((p) => [+p.lat.toFixed(6), +p.lng.toFixed(6)]));
      if (count) count.textContent = points.length < 3 ? `${points.length} of at least 3 corners` : `${points.length} corners`;
    };
    map.on('click', (e) => { if (pickedType() !== 'polygon') return; points.push(e.latlng); redraw(); save(); });
    const undo = document.querySelector('[data-poly-undo]');
    const clear = document.querySelector('[data-poly-clear]');
    if (undo) undo.addEventListener('click', () => { points.pop(); redraw(); save(); });
    if (clear) clear.addEventListener('click', () => { if (!points.length || confirm('Clear the drawn area?')) { points = []; redraw(); save(); } });

    const show = (t) => {
      if (t !== 'radius' && t !== 'polygon') return;
      setTimeout(() => {
        map.invalidateSize();
        if (circle) { if (t === 'radius') circle.addTo(map); else circle.remove(); }
        if (t === 'polygon') { shape.addTo(map); corners.addTo(map); zoneEl.classList.add('is-drawing'); } else { shape.remove(); corners.remove(); zoneEl.classList.remove('is-drawing'); }
        syncCircle();
        const mine = t === 'radius' ? (circle && circle.getRadius() ? circleBounds(d.origin, circle.getRadius()) : null) : (points.length >= 3 ? L.polygon(points).getBounds() : null);
        fit(map, mine ? [mine] : [...others, home && circleBounds(d.origin, 1500)], d.center);
      }, 0);
    };
    document.addEventListener('zonetype', (e) => show(e.detail));
    redraw();
    save();
  }

  if (types.length) syncType();
})();
