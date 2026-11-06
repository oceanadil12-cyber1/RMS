/* Add / edit a dancer: photo upload preview and live card preview. */
(() => {
  'use strict';
  const form = document.querySelector('form[data-dancer-form]');
  if (!form) return;
  const $ = (s) => form.querySelector(s);

  // ----- Photo upload preview -----
  const photoInput = $('#photo_file');
  const currentPhoto = $('[data-current-photo]');
  const emptyBox = $('[data-photo-empty]');
  const chooseLabel = $('[data-choose]');
  const pvPhoto = $('[data-pv-photo]');
  const pvPhotoEmpty = $('[data-pv-photo-empty]');
  const stage = $('.poster-stage');
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
  const pvName = $('[data-pv-name]');
  const pvTitle = $('[data-pv-title]');
  const sync = () => {
    if (pvName) pvName.textContent = form.elements.name.value.trim() || 'Dancer name';
    if (pvTitle) pvTitle.textContent = form.elements.title.value.trim();
  };
  ['name', 'title'].forEach((n) => form.elements[n].addEventListener('input', sync));
})();
