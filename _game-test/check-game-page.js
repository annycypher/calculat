/* check-game-page.js — проверка игр и страницы поиска (фаза 7, партия 7).
   Игры: живёт ли полотно/доска и реагирует ли на ход (клавиши для 2048, указатель для аркад).
   Поиск: ввод запроса → сколько результатов появилось.
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес> -JsFile _game-test\check-game-page.js */
(async () => {
  const out = [];
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));
  const hash = (s) => { let h = 0; for (let i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) | 0; } return h; };
  const canvasHash = (cv) => {
    if (!cv) { return '—'; }
    try {
      const g = cv.getContext('2d');
      const d = g.getImageData(0, 0, cv.width, cv.height).data;
      const step = Math.max(4, Math.floor(d.length / 4000 / 4) * 4);
      let h = 0;
      for (let i = 0; i < d.length; i += step) { h = (h * 31 + d[i]) | 0; }
      return h;
    } catch (e) { return 'ошибка чтения: ' + e.message; }
  };

  const score = () => { const s = document.getElementById('score'); return s ? (s.textContent || '').trim() : '—'; };

  if (document.getElementById('board')) {
    out.push('игра: 2048');
    const board = document.getElementById('board');
    out.push('доска до: счёт=' + score() + ', содержимое=' + hash((board.textContent || '') + (board.innerHTML || '').length));
    let moved = 0;
    for (const key of ['ArrowLeft', 'ArrowUp', 'ArrowRight', 'ArrowDown']) {
      const b = hash(board.innerHTML || '');
      document.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));
      window.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));
      await wait(350);
      if (hash(board.innerHTML || '') !== b) { moved++; }
    }
    out.push('ходов, изменивших доску: ' + moved + ' из 4; счёт=' + score());
    out.push('плиток в доске: ' + board.querySelectorAll('[class*="tile"], .cell, div').length);
  } else if (document.getElementById('cv')) {
    out.push('игра: ' + (location.pathname.indexOf('water') !== -1 ? 'вода' : (location.pathname.indexOf('bubble') !== -1 ? 'пузыри' : 'canvas-игра')) + ' (canvas)');
    const cv = document.getElementById('cv');
    out.push('размер полотна: ' + cv.width + 'x' + cv.height);
    const h1 = canvasHash(cv);
    await wait(500);
    const h2 = canvasHash(cv);
    out.push('полотно отрисовывается: ' + (h1 !== h2 ? 'да (кадры меняются)' : 'нет (кадры одинаковые)'));
    const r = cv.getBoundingClientRect();
    const mk = (type, x, y) => new PointerEvent(type, { bubbles: true, clientX: x, clientY: y, pointerId: 1, isPrimary: true, button: 0, buttons: type === 'pointerup' ? 0 : 1 });
    cv.dispatchEvent(mk('pointerdown', r.left + r.width / 2, r.top + r.height / 2));
    await wait(200);
    cv.dispatchEvent(mk('pointerup', r.left + r.width / 2, r.top + r.height / 2));
    await wait(1200);
    const h3 = canvasHash(cv);
    out.push('после выстрела: счёт=' + score() + ', кадр изменился: ' + (h3 !== h2 ? 'да' : 'нет'));
    if (h3 === h2) {
      cv.dispatchEvent(mk('pointermove', r.left + r.width / 2, r.top + 20));
      await wait(300);
      cv.dispatchEvent(mk('pointerdown', r.left + r.width / 2, r.top + 40));
      await wait(200);
      cv.dispatchEvent(mk('pointerup', r.left + r.width / 2, r.top + 40));
      await wait(1500);
      out.push('вторая попытка (прицел + выстрел): кадр изменился: ' + (canvasHash(cv) !== h3 ? 'да' : 'нет') + ', счёт=' + score());
    }
  } else if (document.getElementById('searchBox')) {
    out.push('страница: поиск');
    const box = document.getElementById('searchBox');
    box.value = 'ипотека';
    box.dispatchEvent(new Event('input', { bubbles: true }));
    await wait(1500);
    const res = document.getElementById('searchResults');
    const links = res ? res.querySelectorAll('a') : [];
    out.push('результатов: ' + links.length + (links.length ? ' → «' + (links[0].textContent || '').trim().slice(0, 60) + '…»' : ''));
    out.push('текст блока: ' + (res ? (res.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 90) : '—'));
  } else {
    out.push('страница не распознана');
  }

  out.push('поиск в шапке: ' + !!document.getElementById('siteSearch') + '; счётчик: ' + (window.__cdStats ? 'ответил' : 'нет'));
  const bad = performance.getEntriesByType('resource').filter((e) => e.responseStatus && e.responseStatus >= 400);
  out.push('ошибки HTTP: ' + (bad.length ? bad.map((e) => e.name.split('/').pop() + '=' + e.responseStatus).join(', ') : '0'));
  out.push('блокирующих CSS: ' + Array.from(document.querySelectorAll('link[rel="stylesheet"]')).filter((l) => { const m = l.getAttribute('media'); return !m || m === 'all' || m === 'screen'; }).length);
  return out.join('\n');
})()
