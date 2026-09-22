/* check-dosrochnoe-case.js — контрольный кейс досрочного погашения (фаза 7, партия 8).
   Кейс владельца: 4 000 000 ₽ / 12% / 20 лет / досрочка 200 000 ₽ с 6-го месяца.
   Ждём: обязательный платёж при стратегии «уменьшить платёж» ниже исходных ~44 000 ₽,
   и осмысленное сравнение трёх стратегий. Снимаем ДО и сравниваем ПОСЛЕ.
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес> -JsFile _game-test\check-dosrochnoe-case.js */
(async () => {
  const out = [];
  const set = (id, v) => {
    const el = document.getElementById(id);
    if (!el) { out.push('нет поля ' + id); return; }
    el.value = String(v);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  };
  const t = (id) => { const e = document.getElementById(id); return e ? (e.textContent || '').trim().replace(/\s+/g, ' ') : '—'; };

  set('sum', 4000000);
  set('rate', 12);
  set('years', 20);
  set('early', 200000);
  set('earlyMonth', 6);
  await new Promise((r) => setTimeout(r, 900));

  out.push('кейс: 4 000 000 ₽ / 12% / 20 лет / досрочка 200 000 ₽ с 6-го месяца');
  out.push('сокращение срока:  платёж=' + t('tShortenPay') + '; срок=' + t('tShortenTerm') + '; экономия=' + t('tShortenSave'));
  out.push('плати по-старому:  платёж=' + t('tOldPay') + '; срок=' + t('tOldTerm') + '; экономия=' + t('tOldSave'));
  out.push('уменьшить платёж:  платёж=' + t('tRedPay') + '; срок=' + t('tRedTerm') + '; экономия=' + t('tRedSave'));
  out.push('вывод страницы: ' + t('verdict').slice(0, 200));

  const num = (id, i) => { const m = (t(id).match(/\d[\d\s\u00a0]*/g) || []); return m.length > i ? Number(m[i].replace(/[^\d]/g, '')) : NaN; };
  const basePay = num('tShortenPay', 0);
  const redPay = num('tRedPay', 0);
  out.push('ИТОГ КЕЙСА: исходный обязательный платёж ' + basePay + ' ₽ → при «уменьшить платёж» ' + redPay + ' ₽ (-' + (basePay - redPay) + ' ₽); платёж снижен: ' + (redPay < basePay ? 'ДА' : 'НЕТ'));

  const err = performance.getEntriesByType('resource').filter((e) => e.responseStatus && e.responseStatus >= 400);
  out.push('ошибки HTTP: ' + (err.length ? err.map((e) => e.name.split('/').pop() + '=' + e.responseStatus).join(', ') : '0'));
  out.push('блокирующих CSS: ' + Array.from(document.querySelectorAll('link[rel="stylesheet"]')).filter((l) => { const m = l.getAttribute('media'); return !m || m === 'all' || m === 'screen'; }).length + ' → ' + Array.from(document.querySelectorAll('link[rel="stylesheet"]')).filter((l) => { const m = l.getAttribute('media'); return !m || m === 'all' || m === 'screen'; }).map((l) => l.getAttribute('href')).join(', '));
  return out.join('\n');
})()
