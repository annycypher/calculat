// js/generator-engine.js — универсальный движок генераторов документов (шаг 1.1 протокола).
//
// Один движок на все генераторы: живой предпросмотр, копирование, скачивание .doc для Word,
// печать только документа. Никаких внешних библиотек — всё в namespace GenEngine.
//
// Чего движок НЕ делает: он не считает проценты, ставки и сроки. Любая юридическая норма живёт
// в js/generator-norms.js и попадает в расчёт только после сверки владельцем.

export const GenEngine = {
  /** Параметры из адреса: ?field=value → поля формы по name, затем recalc(). */
  readParams(extra = {}) {
    const q = new URLSearchParams(location.search);
    let filled = 0;
    q.forEach((value, name) => {
      if (name.indexOf('__') === 0) { return; }
      const el = document.querySelector('[name="' + name + '"]');
      if (!el) { return; }
      el.value = value;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      filled++;
    });
    if (filled > 0) { GenEngine.recalc(extra); }
    return filled;
  },

  /** Попросить страницу пересчитать: своя recalc(), иначе событие gen:params. */
  recalc(extra = {}) {
    if (typeof window.recalc === 'function') { window.recalc(); return true; }
    document.dispatchEvent(new CustomEvent('gen:params', { detail: extra }));
    return false;
  },

  /** Даты документа: {{date_today}} и {{date_long}} движок подставляет сам. */
  today(inWords = false) {
    const d = new Date();
    const two = (n) => String(n).padStart(2, '0');
    if (!inWords) { return two(d.getDate()) + '.' + two(d.getMonth() + 1) + '.' + d.getFullYear(); }
    const months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
      'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    return '«' + two(d.getDate()) + '» ' + months[d.getMonth()] + ' ' + d.getFullYear() + ' г.';
  },

  /** Экранирование: значения из полей не должны ломать документ разметкой. */
  esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (c) => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));
  },

  /** Шаблон с {{placeholder}} → HTML документа. Незнакомые плейсхолдеры убираются целиком. */
  renderPreview(fields, template) {
    const data = Object.assign({}, fields || {});
    if (data.date_today === undefined) { data.date_today = GenEngine.today(false); }
    if (data.date_long === undefined) { data.date_long = GenEngine.today(true); }
    return String(template || '')
      .replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (all, key) => (
        data[key] === undefined || data[key] === null ? '' : GenEngine.esc(data[key])
      ))
      .replace(/\{\{[^}]*\}\}/g, '');
  },

  /** Полный HTML-документ: нужен и для .doc, и для окна печати. */
  docHtml(bodyHtml) {
    return '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8" />'
      + '<meta name="viewport" content="width=device-width, initial-scale=1" />'
      + '<title>Документ — CalcDoc</title>'
      + '<style>@page{size:A4;margin:18mm 15mm}body{background:#fff;color:#111;'
      + 'font:14pt/1.5 "Times New Roman",serif;margin:0}h1,h2{font-size:15pt;margin:0 0 8pt}'
      + 'p{margin:0 0 8pt;text-align:justify}table{width:100%;border-collapse:collapse;font-size:12pt}'
      + 'td,th{border:1px solid #444;padding:6pt;vertical-align:top}.gen-sign{margin-top:24pt}'
      + '.no-print,[data-print="btn"],button{display:none!important}</style></head><body>'
      + bodyHtml + '</body></html>';
  },

  /** Файл .doc для Word: Blob с HTML внутри (Word открывает с предупреждением — это норма). */
  previewToDocx(html, filename = 'document') {
    const blob = new Blob(['\ufeff', GenEngine.docHtml(html)], { type: 'application/msword' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = String(filename).replace(/[^\w.-]+/g, '-') + '.doc';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 4000);
    return blob;
  },

  /** Копирование текста в буфер обмена с запасным способом. */
  async copyToClipboard(text, note = 'Документ скопирован.') {
    const value = String(text == null ? '' : text);
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(value);
      } else {
        const ta = document.createElement('textarea');
        ta.value = value; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
      }
      GenEngine.toast(note);
      return true;
    } catch (e) {
      GenEngine.toast('Скопировать не получилось — выделите текст вручную.');
      return false;
    }
  },

  /** Печать только документа: новое окно, стили печати, белый фон, без шапки сайта. */
  printPreview(html) {
    const win = window.open('', '_blank', 'width=820,height=900');
    if (!win) { GenEngine.toast('Браузер закрыл окно печати — разрешите всплывающие окна.'); return false; }
    const css = '<link rel="stylesheet" href="/print.css?v=2" />';
    win.document.open();
    win.document.write(GenEngine.docHtml(html).replace('</head>', css + '</head>'));
    win.document.close();
    const run = () => { try { win.focus(); win.print(); } catch (e) { /* окно закрыто */ } };
    if (win.document.readyState === 'complete') { setTimeout(run, 120); } else { win.onload = run; }
    return true;
  },

  /** Двусторонняя связь поля и предпросмотра: ввод → мгновенно в документе. */
  bindFields(inputId, placeholder, onChange) {
    const input = document.getElementById(inputId);
    if (!input) { return false; }
    const apply = () => {
      document.querySelectorAll('[data-gen="' + placeholder + '"]').forEach((n) => {
        n.textContent = input.value;
      });
      if (typeof onChange === 'function') { onChange(input.value); }
    };
    input.addEventListener('input', apply);
    apply();
    return true;
  },

  /** Короткое сообщение снизу экрана. */
  toast(text) {
    let box = document.getElementById('genToast');
    if (!box) {
      box = document.createElement('div');
      box.id = 'genToast';
      box.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99;'
        + 'padding:12px 18px;border-radius:14px;background:rgba(20,16,40,.94);color:#f1eef9;'
        + 'border:1px solid rgba(255,255,255,.18);font-size:14.5px;max-width:92vw;text-align:center';
      document.body.appendChild(box);
    }
    box.textContent = text;
    box.hidden = false;
    clearTimeout(box._t);
    box._t = setTimeout(() => { box.hidden = true; }, 3600);
  },

  /** Собрать генератор: параметры из адреса, связи полей, предпросмотр и четыре кнопки.
      Кнопки ищутся по data-gen-action: copy, doc, print, clear.
      template и fields могут быть функциями — тогда они пересчитываются на каждый ввод
      (нужно генераторам с вариантами, где шаблон зависит от выбранной radio-кнопки). */
  build(options = {}) {
    const opt = Object.assign({ template: '', fields: () => ({}), preview: '.gen-preview',
      filename: 'document', from: [], bind: [] }, options);
    const box = document.querySelector(opt.preview);
    const draw = () => {
      const fields = typeof opt.fields === 'function' ? opt.fields() : opt.fields;
      const template = typeof opt.template === 'function' ? opt.template() : opt.template;
      const html = GenEngine.renderPreview(fields, template);
      if (box) { box.innerHTML = html; }
      return html;
    };
    opt.from.forEach((id) => {
      const el = document.getElementById(id);
      if (el) { el.addEventListener('input', draw); }
    });
    opt.bind.forEach((pair) => GenEngine.bindFields(pair[0], pair[1], draw));
    document.querySelectorAll('[data-gen-action]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const act = btn.getAttribute('data-gen-action');
        const html = draw();
        if (act === 'copy') { GenEngine.copyToClipboard(box ? box.innerText : ''); }
        if (act === 'doc') {
          GenEngine.previewToDocx(html, opt.filename);
          GenEngine.toast('Файл .doc для Word сохранён.');
        }
        if (act === 'print') { GenEngine.printPreview(html); }
        if (act === 'clear') {
          document.querySelectorAll('form input, form textarea').forEach((f) => {
            if (f.type !== 'submit' && f.type !== 'button') { f.value = ''; }
          });
          draw();
        }
      });
    });
    GenEngine.readParams();
    draw();
    return { draw: draw };
  }
};

export default GenEngine;

