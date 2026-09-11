// js/home.js — интерактив главной страницы (тёмный дизайн из design-reference.html).
// Содержит: мобильное меню, свет за курсором, reveal-анимации, tilt-наклон,
// toast, демо-инструменты (ипотека, QR на локальной библиотеке, сжатие) и подсказки в tooltip.
// Статистика «посещений»/«инструментов» из демо удалена — цифры там были вымышленные.
  // Загрузчик подключается ДИНАМИЧЕСКИ: даже если /js/chunkload.js недоступен,
  // модуль выполнится и остальная логика страницы (показ контента и т.д.) не сломается.
  window.__qrLibReady = (function () {
    var p = null;
    return function () {
      if (typeof window.qrcode === 'function') return Promise.resolve(true);
      if (!p) p = import('/js/chunkload.js?v=8')
        .then(function (m) { return m.loadChunkedScript('/libs/qrcode-generator.js'); })
        .then(function () { return typeof window.qrcode === 'function'; })
        .catch(function () { return false; });
      return p;
    };
  })();

(function(){
  'use strict';
  const $ = id => document.getElementById(id);

  /* Появление при скролле */
  const io = new IntersectionObserver(es => es.forEach(e => {
    if(e.isIntersecting){ e.target.classList.add('vis'); io.unobserve(e.target); }
  }), {threshold:.12});
  document.querySelectorAll('.reveal').forEach(el => io.observe(el));
  window.__homeReady = true;

  /* Хедер + кнопка наверх */
  const header = $('header'), toTop = $('toTop');
  addEventListener('scroll', () => {
    header.classList.toggle('scrolled', scrollY > 30);
    toTop.classList.toggle('show', scrollY > 600);
  }, {passive:true});
  toTop.addEventListener('click', () => scrollTo({top:0, behavior:'smooth'}));

  /* Мобильное меню */
  const mnav = $('mnav');
  $('burger').addEventListener('click', () => mnav.classList.toggle('open'));
  mnav.querySelectorAll('a').forEach(a => a.addEventListener('click', () => mnav.classList.remove('open')));

  /* Свет за курсором */
  const light = document.querySelector('.cursor-light');
  let lx=0, ly=0, tick=false;
  addEventListener('mousemove', e => {
    lx=e.clientX; ly=e.clientY;
    if(!tick){ tick=true; requestAnimationFrame(() => {
      light.style.setProperty('--mx', lx+'px');
      light.style.setProperty('--my', ly+'px');
      tick=false;
    });}
  }, {passive:true});


  /* Toast */
  const toast = $('toast'); let tTimer;
  function showToast(msg){
    $('toastMsg').textContent = msg;
    toast.classList.add('show');
    clearTimeout(tTimer);
    tTimer = setTimeout(() => toast.classList.remove('show'), 2200);
  }

  /* Tilt-наклон стекла за курсором */
  function bindTilt(el, max){
    el.addEventListener('mousemove', e => {
      const r = el.getBoundingClientRect();
      const x = (e.clientX - r.left) / r.width - .5;
      const y = (e.clientY - r.top) / r.height - .5;
      el.style.transform = `translateY(-4px) rotateY(${x*max}deg) rotateX(${-y*max}deg)`;
    });
    el.addEventListener('mouseleave', () => {
      el.style.transition = 'transform .5s cubic-bezier(.2,.7,.3,1)';
      el.style.transform = '';
      setTimeout(() => el.style.transition = '', 500);
    });
  }
  document.querySelectorAll('.tilt').forEach(c => bindTilt(c, 5));

  const fmt = n => n.toLocaleString('ru-RU') + ' ₽';

  /* ---------- 1. Ипотека (аннуитет) ---------- */
  const mSum=$('mSum'), mRate=$('mRate'), mTerm=$('mTerm'), mPay=$('mPay'), mInt=$('mInt');

  function calcMortgage(){
    const S = +mSum.value.replace(/\D/g,'') || 0;
    const rate = parseFloat(mRate.value.replace(',','.')) || 0;
    const years = +mTerm.value.replace(/\D/g,'') || 0;
    if(!S || !rate || !years){ mPay.textContent='—'; mInt.textContent='—'; return null; }
    const i = rate/100/12, n = Math.round(years*12);
    const pay = S * i * Math.pow(1+i,n) / (Math.pow(1+i,n)-1);
    const interest = pay*n - S;
    mPay.textContent = fmt(Math.round(pay));
    mInt.textContent = fmt(Math.round(interest));
    return {S, rate, years, pay:Math.round(pay), interest:Math.round(interest)};
  }
  [mSum, mRate, mTerm].forEach(el => el.addEventListener('input', calcMortgage));
  mSum.addEventListener('input', () => {
    const d = mSum.value.replace(/\D/g,'').slice(0,12);
    mSum.value = d ? (+d).toLocaleString('ru-RU') : '';
  });
  $('mReset').addEventListener('click', () => {
    mSum.value = mRate.value = mTerm.value = '';
    mPay.textContent = mInt.textContent = '—';
    mSum.focus();
  });
  $('mCopy').addEventListener('click', () => {
    const r = calcMortgage();
    if(!r){ showToast('Заполните сумму, ставку и срок'); return; }
    const text = `Ипотека: ${fmt(r.S)}, ${r.rate}% на ${r.years} лет → платёж ${fmt(r.pay)}/мес, переплата ${fmt(r.interest)}`;
    navigator.clipboard.writeText(text)
      .then(() => showToast('Результат скопирован'))
      .catch(() => showToast('Не удалось скопировать'));
  });

  /* ---------- 2. QR-код: локальная библиотека (без внешних сервисов) ---------- */
  const qrInp = $('qrInp'), qrView = $('qrView'), qrImg = $('qrImg');
  let qrSvgCur = '';
  function qrDataUrl(svg){ return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg); }
  function qrSvgFromMatrix(qr, moduleSize, margin){
    const n = qr.getModuleCount(), total = n + 2 * margin, dim = total * moduleSize;
    let rects = '';
    for (let r = 0; r < n; r++) {
      for (let c = 0; c < n; c++) {
        if (qr.isDark(r, c)) {
          rects += '<rect x="' + ((c + margin) * moduleSize) + '" y="' + ((r + margin) * moduleSize) +
                   '" width="' + moduleSize + '" height="' + moduleSize + '"/>';
        }
      }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' + dim + '" height="' + dim +
           '" viewBox="0 0 ' + dim + ' ' + dim + '" shape-rendering="crispEdges">' +
           '<rect width="100%" height="100%" fill="#ffffff"/><g fill="#000000">' + rects + '</g></svg>';
  }
  function saveQrFile(blob, name, msg){
    const url = URL.createObjectURL(blob);
    const a2 = document.createElement('a');
    a2.href = url; a2.download = name; a2.click();
    setTimeout(() => URL.revokeObjectURL(url), 5000);
    showToast(msg);
  }
  function saveQrSvg(){ saveQrFile(new Blob([qrSvgCur], { type: 'image/svg+xml' }), 'qr-code.svg', 'QR-код сохранён (SVG)'); }
  async function makeQr(){
    const d = qrInp.value.trim();
    if(!d){ showToast('Введите текст или ссылку'); return; }
    if(d.length > 1000){ showToast('Максимум 1000 символов'); return; }
    const ready = window.__qrLibReady ? await window.__qrLibReady() : false;
    if(!ready){ showToast('Генератор QR не загрузился'); return; }
    const qr = window.qrcode(0, 'L');
    qr.addData(d);
    qr.make();
    qrSvgCur = qrSvgFromMatrix(qr, 4, 4);
    qrImg.src = qrDataUrl(qrSvgCur);
    qrView.classList.add('on');
  }
  $('qrBtn').addEventListener('click', makeQr);
  qrInp.addEventListener('keydown', e => { if(e.key === 'Enter') makeQr(); });
  $('qrDl').addEventListener('click', () => {
    if(!qrSvgCur){ showToast('Сначала создайте QR-код'); return; }
    const img = new Image();
    img.onload = () => {
      try {
        const c = document.createElement('canvas');
        c.width = 440; c.height = 440;
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, 440, 440);
        ctx.drawImage(img, 0, 0, 440, 440);
        c.toBlob(b => { if(!b){ saveQrSvg(); return; } saveQrFile(b, 'qr-code.png', 'QR-код сохранён (PNG)'); }, 'image/png');
      } catch(err){ saveQrSvg(); }
    };
    img.onerror = saveQrSvg;
    img.src = qrDataUrl(qrSvgCur);
  });

  /* ---------- 3. Сжатие изображения (Canvas, локально) ---------- */
  const cmpFile=$('cmpFile'), cmpRes=$('cmpRes');
  let cmpBlob = null, cmpUrl = null;
  const fmtSize = b => b < 1024 ? b+' Б' : b < 1048576 ? (b/1024).toFixed(1)+' КБ' : (b/1048576).toFixed(2)+' МБ';

  cmpFile.addEventListener('change', () => {
    const f = cmpFile.files[0];
    if(!f) return;
    if(!f.type.startsWith('image/')){ showToast('Выберите файл изображения'); return; }
    const img = new Image();
    img.onload = () => {
      const MAX = 1600;
      let w = img.width, h = img.height;
      if(Math.max(w,h) > MAX){ const k = MAX/Math.max(w,h); w = Math.round(w*k); h = Math.round(h*k); }
      const c = document.createElement('canvas');
      c.width = w; c.height = h;
      c.getContext('2d').drawImage(img, 0, 0, w, h);
      c.toBlob(b => {
        if(!b){ showToast('Не удалось обработать файл'); return; }
        if(cmpUrl) URL.revokeObjectURL(cmpUrl);
        cmpBlob = b; cmpUrl = URL.createObjectURL(b);
        $('cmpName').textContent = f.name;
        $('cmpSizes').textContent = fmtSize(f.size) + ' → ' + fmtSize(b.size);
        const pct = Math.round((1 - b.size/f.size) * 100);
        $('cmpPct').textContent = pct > 0 ? '−' + pct + '%' : 'оптимизирован';
        cmpRes.classList.add('on');
        showToast(pct > 0 ? 'Сжато на ' + pct + '%' : 'Файл готов');
      }, 'image/jpeg', 0.72);
    };
    img.onerror = () => showToast('Формат не поддерживается браузером');
    img.src = URL.createObjectURL(f);
  });
  $('cmpDl').addEventListener('click', () => {
    if(!cmpBlob){ showToast('Сначала выберите изображение'); return; }
    const a = document.createElement('a');
    a.href = cmpUrl; a.download = 'compressed.jpg'; a.click();
    showToast('Файл сохранён');
  });
})();