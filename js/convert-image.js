// Конвертер изображений: PNG / JPG / WEBP + сжатие, всё на клиенте через canvas.

const form = document.getElementById('imgForm');
const fileEl = document.getElementById('file');
const fmtEl = document.getElementById('format');
const qualityEl = document.getElementById('quality');
const qualityVal = document.getElementById('qualityVal');
const maxWidthEl = document.getElementById('maxWidth');
const output = document.getElementById('output');

if (qualityEl && qualityVal) {
  qualityEl.addEventListener('input', () => { qualityVal.textContent = qualityEl.value + '%'; });
}

if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const file = fileEl.files && fileEl.files[0];
    if (!file) { output.innerHTML = `<p class="hint">Сначала выберите изображение.</p>`; return; }

    const fmt = fmtEl.value;
    const quality = parseInt(qualityEl.value, 10) / 100;
    const maxW = parseInt(maxWidthEl.value, 10) || 0;

    const img = new Image();
    const url = URL.createObjectURL(file);

    img.onload = () => {
      let w = img.naturalWidth, h = img.naturalHeight;
      if (maxW && w > maxW) { h = Math.round((h * maxW) / w); w = maxW; }

      const canvas = document.createElement('canvas');
      canvas.width = w; canvas.height = h;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#ffffff'; // JPG без прозрачности
      ctx.fillRect(0, 0, w, h);
      ctx.drawImage(img, 0, 0, w, h);

      const mime = fmt === 'png' ? 'image/png' : fmt === 'webp' ? 'image/webp' : 'image/jpeg';
      canvas.toBlob((blob) => {
        URL.revokeObjectURL(url);
        if (!blob) { output.innerHTML = `<p class="hint" style="color:#c0392b">Не удалось сконвертировать. Формат WEBP может не поддерживаться этим браузером.</p>`; return; }
        const outUrl = URL.createObjectURL(blob);
        output.innerHTML = `
          <img src="${outUrl}" alt="Результат" style="max-width:100%;border-radius:10px;margin-bottom:12px" />
          <p style="margin:0 0 12px">Готово: <strong>${(blob.size / 1024).toFixed(0)} КБ</strong> · ${w}×${h}px · ${fmt.toUpperCase()}</p>
          <a class="btn btn-primary" download="converted.${fmt}" href="${outUrl}">Скачать .${fmt}</a>
        `;
      }, mime, quality);
    };
    img.onerror = () => { output.innerHTML = `<p class="hint" style="color:#c0392b">Файл не является изображением.</p>`; URL.revokeObjectURL(url); };
    img.src = url;
  });
}
