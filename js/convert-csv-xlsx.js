// Конвертер CSV → Excel (XLSX) через SheetJS.

const form = document.getElementById('csvForm');
const fileEl = document.getElementById('file');
const output = document.getElementById('output');

// Ленивая загрузка библиотеки (грузится только при конвертации)
function loadScript(src) {
  return new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = src;
    s.onload = () => resolve();
    s.onerror = () => reject(new Error('Не удалось загрузить ' + src));
    document.head.appendChild(s);
  });
}

if (form) {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const file = fileEl.files && fileEl.files[0];
    if (!file) { output.innerHTML = `<p class="hint">Сначала выберите CSV-файл.</p>`; return; }

    try {
      if (typeof XLSX === 'undefined') await loadScript('/libs/xlsx.full.min.js');
    } catch (err) {
      output.innerHTML = `<p class="hint" style="color:#c0392b">Библиотека SheetJS не загрузилась. Проверьте интернет.</p>`;
      return;
    }

    try {
      const reader = new FileReader();
      reader.onload = (ev) => {
        try {
          const wb = XLSX.read(new Uint8Array(ev.target.result), { type: 'array' });
          XLSX.writeFile(wb, 'converted.xlsx');
          output.innerHTML = `<p style="margin:0">✅ Готово! Файл <strong>converted.xlsx</strong> скачан. Листов: ${wb.SheetNames.length}</p>`;
        } catch (err) {
          output.innerHTML = `<p class="hint" style="color:#c0392b">Не удалось прочитать файл: ${err.message}</p>`;
        }
      };
      reader.onerror = () => { output.innerHTML = `<p class="hint" style="color:#c0392b">Ошибка чтения файла.</p>`; };
      reader.readAsArrayBuffer(file);
    } catch (err) {
      output.innerHTML = `<p class="hint" style="color:#c0392b">Ошибка: ${err.message}</p>`;
    }
  });
}
