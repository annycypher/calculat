/* check-eng-case.js — контрольный кейс инженерных калькуляторов (фаза 7, партия 6).
   Требование владельца: после замены ui.js цифра должна остаться осмысленной и НЕ измениться.
   Снимаем канонические значения ДО правки и сравниваем ПОСЛЕ.
   gidrostrelka: 30 кВт → диаметр, поток, труба (ожидание владельца: DN80).
   vulkan: цех 20×12×6, −25/+15 → мощности. otoplenie-obem: 200 м² → объём системы.
   Запуск: powershell -File _game-test\cdp-check.ps1 -Url <адрес> -JsFile _game-test\check-eng-case.js */
(async () => {
  const out = [];
  const set = (id, v) => {
    const el = document.getElementById(id);
    if (!el) { return false; }
    el.value = String(v);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  };
  const txt = (id) => { const e = document.getElementById(id); return e ? (e.textContent || '').trim().replace(/\s+/g, ' ') : '—'; };
  const firstNums = (id, n) => {
    const t = txt(id);
    return (t.match(/\d[\d\s\u00a0]*[.,]?\d*\s*(мм|м³\/ч|л\/мин|кВт|л|м³|₽)/g) || []).slice(0, n).join(' | ') || t.slice(0, 80);
  };

  if (document.getElementById('aPow')) {
    out.push('страница: гидрострелка');
    set('aPow', 30);
    await new Promise((r) => setTimeout(r, 600));
    out.push('КОНТРОЛЬ: диаметр=' + txt('aD') + '; мин. длина=' + txt('aL') + '; поток=' + txt('aFlow') + '; патрубок=' + txt('aPipe') + '; теплообменник=' + txt('aHex'));
  } else if (document.getElementById('len') && document.getElementById('wid')) {
    out.push('страница: тепловентилятор (vulkan)');
    ['len:20', 'wid:12', 'hgt:6', 'tOut:-25', 'tIn:15'].forEach((p) => { const [i, v] = p.split(':'); set(i, v); });
    await new Promise((r) => setTimeout(r, 700));
    out.push('КОНТРОЛЬ: результат=' + firstNums('result', 7));
    out.push('КОНТРОЛЬ (блок подбора): ' + firstNums('units', 5));
  } else if (document.getElementById('floorArea')) {
    out.push('страница: объём системы отопления');
    set('floorArea', 200);
    await new Promise((r) => setTimeout(r, 700));
    out.push('КОНТРОЛЬ: результат=' + firstNums('result', 7));
  } else {
    out.push('страница не распознана');
  }

  const err = performance.getEntriesByType('resource').filter((e) => e.responseStatus && e.responseStatus >= 400);
  out.push('ошибки HTTP: ' + (err.length ? err.map((e) => e.name.split('/').pop() + '=' + e.responseStatus).join(', ') : '0'));
  out.push('блокирующих CSS: ' + Array.from(document.querySelectorAll('link[rel="stylesheet"]')).filter((l) => { const m = l.getAttribute('media'); return !m || m === 'all' || m === 'screen'; }).length);
  return out.join('\n');
})()
