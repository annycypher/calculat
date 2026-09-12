/* Общие блоки для страниц мини-игр: оценка игры, «поделиться», мотивация и мини-статистика.
   Всё считается локально в браузере — на сервер ничего не отправляется. */
(function () {
  'use strict';

  var slug = (function () {
    var p = location.pathname.replace(/\/+$/, '');
    var n = p.split('/').pop().replace(/\.html$/, '');
    return n || 'game';
  })();

  function lsGet(k, d) { try { var v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } }
  function lsSet(k, v) { try { localStorage.setItem(k, String(v)); } catch (e) {} }

  var gameName = (document.body.getAttribute('data-game') || document.title.split('—')[0] || 'игра').trim();
  var OVERLAY = document.getElementById('over');
  var scoreEl = document.getElementById('score');
  var bestEl = document.getElementById('best');

  /* ---------- 1. Оценка игры ---------- */
  var rateBox = document.querySelector('[data-rating]');
  if (rateBox) {
    var stars = [].slice.call(rateBox.querySelectorAll('.rate-star'));
    var note = rateBox.querySelector('[data-rating-note]');
    var saved = parseInt(lsGet('grate:' + slug, '0'), 10) || 0;
    var thanks = {
      5: 'Спасибо! Пятёрка — приятно 🙂',
      4: 'Спасибо! Рады, что зашло.',
      3: 'Спасибо! Есть что улучшить — учтём.',
      2: 'Спасибо за честность. Напишите в отзыве, чего не хватило.',
      1: 'Жаль. Расскажите, что сломалось или не понравилось.'
    };
    function paint(v) {
      stars.forEach(function (s, i) {
        s.classList.toggle('on', i < v);
        s.setAttribute('aria-checked', i === v - 1 ? 'true' : 'false');
      });
    }
    stars.forEach(function (s, i) {
      s.addEventListener('click', function () {
        var v = i + 1;
        saved = v; lsSet('grate:' + slug, v);
        paint(v);
        if (note) { note.textContent = thanks[v] || 'Спасибо!'; note.hidden = false; }
      });
      s.addEventListener('mouseenter', function () { paint(i + 1); });
    });
    rateBox.addEventListener('mouseleave', function () { paint(saved); });
    if (saved) { paint(saved); if (note) { note.textContent = thanks[saved] || 'Ваша оценка сохранена.'; note.hidden = false; } }
  }

  /* ---------- 2. Поделиться ---------- */
  var shareUrl = location.href.split('#')[0].split('?')[0];
  var shareTitle = document.title.split('—')[0].trim() || gameName;

  function shareText(extra) {
    var base = shareTitle + ' — бесплатная игра на calc-doc.ru';
    return extra ? extra + '\n' + base : base;
  }
  function toast(msg) {
    var t = document.createElement('div');
    t.className = 'gx-toast';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('on'); }, 10);
    setTimeout(function () { t.classList.remove('on'); setTimeout(function () { t.remove(); }, 300); }, 2600);
  }
  function copy(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { toast('Ссылка скопирована'); }, function () { toast('Не удалось скопировать'); });
    } else {
      var ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', '');
      ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); toast('Ссылка скопирована'); } catch (e) { toast('Скопируйте ссылку вручную'); }
      ta.remove();
    }
  }
  function nativeShare(text) {
    if (navigator.share) {
      navigator.share({ title: shareTitle, text: text, url: shareUrl }).catch(function () {});
      return true;
    }
    return false;
  }

  var resultText = null;
  document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('[data-share]') : null;
    if (!el) return;
    var kind = el.getAttribute('data-share');
    var text = shareText(el.getAttribute('data-result') === '1' ? resultText : null);
    if (kind === 'native') { e.preventDefault(); if (!nativeShare(text)) copy(text); return; }
    if (kind === 'copy') { e.preventDefault(); copy(shareUrl); return; }
    var map = {
      tg: 'https://t.me/share/url?url=' + encodeURIComponent(shareUrl) + '&text=' + encodeURIComponent(text),
      vk: 'https://vk.com/share.php?url=' + encodeURIComponent(shareUrl) + '&title=' + encodeURIComponent(text),
      wa: 'https://api.whatsapp.com/send?text=' + encodeURIComponent(text + ' ' + shareUrl)
    };
    if (map[kind]) { e.preventDefault(); window.open(map[kind], '_blank', 'noopener,noreferrer'); }
  });

  /* ---------- 3. Мотивация и мини-статистика после партии ---------- */
  var playsKey = 'gplays:' + slug, bestKey = 'gbest:' + slug;
  var plays = parseInt(lsGet(playsKey, '0'), 10) || 0;
  var statEl = document.querySelector('[data-game-stats]');
  var nudgeEl = document.querySelector('[data-nudge]');

  function num(el) {
    var v = el ? (el.textContent || '').replace(/[^\d]/g, '') : '';
    var n = parseInt(v, 10);
    return isNaN(n) ? 0 : n;
  }
  function bestNow() { return num(bestEl); }
  function scoreNow() { return num(scoreEl); }

  function showStats() {
    if (!statEl) return;
    var b = Math.max(parseInt(lsGet(bestKey, '0'), 10) || 0, bestNow());
    statEl.innerHTML = 'Партий в этой игре: <strong>' + plays + '</strong>' +
      (b ? ' · Ваш рекорд: <strong>' + b.toLocaleString('ru-RU') + '</strong>' : '') +
      '<br><span class="gx-note">Результаты хранятся только в вашем браузере — и это честно: без регистрации и сбора данных.</span>';
  }

  function motivation() {
    if (!nudgeEl) return;
    var s = scoreNow(), b = bestNow();
    var text;
    if (s > 0 && s >= b) {
      text = 'Это ваш лучший результат. Отправьте ссылку другу — пусть попробует побить.';
    } else if (b > 0 && b > s) {
      text = 'До рекорда (' + b.toLocaleString('ru-RU') + ') осталось ' + (b - s).toLocaleString('ru-RU') + '. Ещё партия — и он ваш.';
    } else {
      text = 'Ещё одна попытка — и результат будет выше: короткие партии быстро добавляют навык.';
    }
    nudgeEl.textContent = text;
    nudgeEl.hidden = false;
  }

  if (OVERLAY) {
    var wasOn = OVERLAY.classList.contains('on');
    new MutationObserver(function () {
      var on = OVERLAY.classList.contains('on');
      if (on && !wasOn) {
        plays++;
        lsSet(playsKey, plays);
        lsSet(bestKey, Math.max(parseInt(lsGet(bestKey, '0'), 10) || 0, bestNow()));
        var s = scoreNow();
        if (s > 0) {
          resultText = 'Мой результат: ' + s.toLocaleString('ru-RU') + ' в игре «' + shareTitle + '». Попробуй побить!';
          var rb = document.querySelector('[data-share-result]');
          if (rb) rb.hidden = false;
        }
        showStats();
        motivation();
      }
      wasOn = on;
    }).observe(OVERLAY, { attributes: true, attributeFilter: ['class'] });
  }
  showStats();
  if (nudgeEl) nudgeEl.hidden = true;
})();
