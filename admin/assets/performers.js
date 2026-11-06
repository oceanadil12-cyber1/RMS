/* Add / edit a performer: genre tag buttons, photo upload preview, live preview. */
(() => {
  'use strict';
  const form = document.querySelector('form[data-performer-form]');
  if (!form) return;
  const $ = (s, r = form) => r.querySelector(s);
  const $$ = (s, r = form) => [...r.querySelectorAll(s)];

  // ----- Genre tags -----
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

  // ----- Photo upload preview -----
  const photoInput = $('#photo_file');
  const currentPhoto = $('[data-current-photo]');
  const emptyBox = $('[data-photo-empty]');
  const chooseLabel = $('[data-choose]');
  const pvPhoto = $('[data-pv-photo]');
  const pvPhotoEmpty = $('[data-pv-photo-empty]');
  const stage = form.querySelector('.poster-stage');
  const msg = $('[data-msg]');

  const loadPhoto = (file) => {
    if (!file) return;
    if (!/^image\/(webp|jpeg|png)/.test(file.type) && !/\.(webp|jpe?g|png)$/i.test(file.name)) {
      if (msg) { msg.textContent = 'Please use a WebP, JPG or PNG picture.'; msg.className = 'poster-msg err'; }
      return;
    }
    if (file.size > 2e6) {
      if (msg) { msg.textContent = 'That picture is over 2 MB. Pick a smaller one.'; msg.className = 'poster-msg err'; }
      return;
    }
    const url = URL.createObjectURL(file);
    if (currentPhoto) { currentPhoto.src = url; currentPhoto.hidden = false; }
    if (emptyBox) emptyBox.hidden = true;
    if (pvPhoto) { pvPhoto.src = url; pvPhoto.hidden = false; }
    if (pvPhotoEmpty) pvPhotoEmpty.hidden = true;
    if (chooseLabel) chooseLabel.textContent = 'Replace photo';
    if (msg) { msg.textContent = 'Photo selected. Save to keep it.'; msg.className = 'poster-msg ok'; }
  };

  photoInput.addEventListener('change', () => { if (photoInput.files[0]) loadPhoto(photoInput.files[0]); });

  if (stage) {
    ['dragenter', 'dragover'].forEach((t) => stage.addEventListener(t, (e) => { e.preventDefault(); stage.classList.add('over'); }));
    ['dragleave', 'drop'].forEach((t) => stage.addEventListener(t, (e) => { e.preventDefault(); stage.classList.remove('over'); }));
    stage.addEventListener('drop', (e) => {
      const f = e.dataTransfer && e.dataTransfer.files[0];
      if (f) {
        loadPhoto(f);
        const dt = new DataTransfer();
        dt.items.add(f);
        photoInput.files = dt.files;
      }
    });
  }

  // ----- Live preview -----
  const pv = {
    name: $('[data-pv-name]'),
    tagline: $('[data-pv-tagline]'),
    bio: $('[data-pv-bio]'),
    genres: $('[data-pv-genres]'),
  };

  function syncPreview() {
    const f = form.elements;
    if (pv.name) pv.name.textContent = f.name.value.trim() || 'Performer name';
    if (pv.tagline) pv.tagline.textContent = f.tagline.value.trim() || 'A short tagline';
    if (pv.bio) pv.bio.textContent = f.bio.value.trim() || 'Bio text appears here.';
    if (pv.genres) {
      pv.genres.replaceChildren(...tagList().slice(0, 8).map((t) => {
        const li = document.createElement('li');
        li.textContent = t;
        return li;
      }));
    }
  }

  ['name', 'tagline', 'bio'].forEach((n) => {
    const el = form.elements[n];
    if (el) el.addEventListener('input', syncPreview);
  });

  syncChips();
})();
