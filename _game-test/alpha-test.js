/* alpha-test.js — проверка прозрачности PNG в сжималках CalcDoc.
 * Запускается через DevTools Protocol прямо в контексте живой страницы
 * (конвертер /converters/image-converter/ или главная), поэтому тестируется
 * именно рабочий код страницы, а не его копия.
 *
 * Тестовый файл 8×8: левая половина красная, правая — полностью прозрачная.
 * Альфа правого верхнего угла результата: 0 — прозрачность сохранена, 255 — залита.
 */
(async () => {
  const out = [];
  const check = (c, t) => out.push((c ? 'OK     ' : 'ПРОВАЛ ') + t);

  const mk = (kind) => new Promise((r) => {
    const c = document.createElement('canvas'); c.width = 8; c.height = 8;
    const x = c.getContext('2d');
    x.fillStyle = '#ff0000'; x.fillRect(0, 0, kind === 'png' ? 4 : 8, 8);
    c.toBlob((b) => r(new File([b], kind === 'png' ? 'alpha.png' : 'opaque.jpg',
      { type: kind === 'png' ? 'image/png' : 'image/jpeg' })),
      kind === 'png' ? 'image/png' : 'image/jpeg', 0.9);
  });

  const alpha = (url) => new Promise((res, rej) => {
    const i = new Image();
    i.onload = () => {
      const c = document.createElement('canvas');
      c.width = i.naturalWidth; c.height = i.naturalHeight;
      const x = c.getContext('2d'); x.drawImage(i, 0, 0);
      res(x.getImageData(i.naturalWidth - 1, 0, 1, 1).data[3]);
    };
    i.onerror = () => rej(new Error('результат не открылся'));
    i.src = url;
  });

  const wait = async (fn, ms) => {
    const t0 = Date.now();
    for (;;) {
      let v = null; try { v = fn(); } catch (e) { v = null; }
      if (v) return v;
      if (Date.now() - t0 > (ms || 8000)) throw new Error('нет результата');
      await new Promise((r) => setTimeout(r, 50));
    }
  };

  const feed = (el, file) => {
    const dt = new DataTransfer(); dt.items.add(file);
    el.files = dt.files;
    el.dispatchEvent(new Event('change', { bubbles: true }));
  };

  const spyBlobs = () => {
    const seen = [];
    const orig = URL.createObjectURL.bind(URL);
    URL.createObjectURL = (b) => { const u = orig(b); seen.push({ type: b.type, url: u }); return u; };
    return seen;
  };

  const spyDl = () => {
    const rec = [];
    HTMLAnchorElement.prototype.click = function () { rec.push({ download: this.download, href: this.href }); };
    return rec;
  };

  try {
    if (location.pathname.indexOf('image-converter') >= 0) {
      const blobs = spyBlobs();
      feed(document.getElementById('file'), await mk('png'));
      const a = await wait(() => document.querySelector('.ic-actions a[download]'));
      const al = await alpha(a.href);
      const t = (blobs[blobs.length - 1] || {}).type;
      check(t === 'image/webp', 'конвертер, PNG → ' + t + ', имя ' + a.getAttribute('download'));
      check(al === 0, 'конвертер, PNG: альфа прозрачного угла ' + al);
      feed(document.getElementById('file'), await mk('jpg'));
      const a2 = await wait(() => {
        const l = document.querySelectorAll('.ic-actions a[download]');
        return l.length > 1 ? l[1] : null;
      });
      const al2 = await alpha(a2.href);
      check(/\.jpg$/.test(a2.getAttribute('download')) && al2 === 255,
        'конвертер, JPG: имя ' + a2.getAttribute('download') + ', альфа ' + al2);
    } else {
      const blobs = spyBlobs(), dls = spyDl();
      feed(document.getElementById('cmpFile'), await mk('png'));
      await wait(() => document.getElementById('cmpName').textContent);
      document.getElementById('cmpDl').click();
      const d = await wait(() => dls[0], 4000);
      const al = await alpha(d.href);
      const t = (blobs[blobs.length - 1] || {}).type;
      check(t === 'image/webp', 'главная, PNG → ' + t + ', имя ' + d.download);
      check(al === 0, 'главная, PNG: альфа прозрачного угла ' + al);
      feed(document.getElementById('cmpFile'), await mk('jpg'));
      await wait(() => document.getElementById('cmpName').textContent.indexOf('opaque') >= 0);
      document.getElementById('cmpDl').click();
      const d2 = await wait(() => dls[1], 4000);
      check(/\.jpg$/.test(d2.download), 'главная, JPG: имя ' + d2.download);
    }
  } catch (e) {
    out.push('ОШИБКА: ' + (e && e.message ? e.message : e));
  }
  return out.join('\n');
})()
