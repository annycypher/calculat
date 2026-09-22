// DaData-конструктор: собирает curl-запрос и пробует вызвать Suggest API.
// Запрос из браузера может быть ограничен CORS — тогда используйте curl.

const form = document.getElementById('dadataForm');
const buildBtn = document.getElementById('buildBtn');
const out = document.getElementById('out');
const copyBtn = document.getElementById('copyBtn');
const tokenInput = document.getElementById('token');

const ENDPOINTS = {
  party: 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/party',
  address: 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address',
  bank: 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/bank',
  fio: 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/fio'
};

function get(id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; }

function buildBody() {
  const type = get('type');
  const body = { query: get('query') || '', count: parseInt(get('count'), 10) || 5 };
  if (type === 'party') {
    const inn = get('bust');
    if (inn) body.constraints = [{ inn: inn }];
  }
  return JSON.stringify(body, null, 2);
}

function buildCurl() {
  const type = get('type');
  const url = ENDPOINTS[type];
  const token = tokenInput.value.trim();
  const auth = token ? '  -H "Authorization: Token ' + token + '" \\\n' : '';
  return 'curl -X POST "' + url + '" \\\n' +
    '  -H "Content-Type: application/json" \\\n' +
    '  -H "Accept: application/json" \\\n' +
    auth +
    '  -d \'' + buildBody() + '\'';
}

if (buildBtn) {
  buildBtn.addEventListener('click', () => {
    out.textContent = buildCurl();
    if (copyBtn) copyBtn.hidden = false;
  });
}

if (copyBtn) {
  copyBtn.addEventListener('click', () => {
    navigator.clipboard.writeText(out.textContent).then(() => {
      copyBtn.textContent = '✓ Скопировано';
      setTimeout(() => { copyBtn.textContent = '📋 Скопировать результат'; }, 1500);
    });
  });
}

if (form) {
  // Восстановить токен из localStorage
  const saved = localStorage.getItem('dadata_token');
  if (saved && tokenInput) tokenInput.value = saved;

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const type = get('type');
    const url = ENDPOINTS[type];
    const token = tokenInput.value.trim();
    if (token) localStorage.setItem('dadata_token', token);

    if (!token) {
      out.textContent = 'Укажите API-токен DaData, чтобы выполнить запрос. Или скопируйте curl-команду.\n\n' + buildCurl();
      if (copyBtn) copyBtn.hidden = false;
      return;
    }

    out.textContent = 'Запрос…\n\n' + buildCurl();
    fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': 'Token ' + token
      },
      body: JSON.stringify(JSON.parse(buildBody()))
    }).then((r) => r.text().then((t) => ({ ok: r.ok, t })))
      .then((res) => {
        out.textContent = res.ok
          ? 'URL: ' + url + '\n\n' + prettyJson(res.t)
          : 'Ошибка API (' + res.t + ')';
      })
      .catch((err) => {
        out.textContent = 'Запрос не выполнен (' + err.message + ').\nСкорее всего DaData не разрешает CORS. Скопируйте curl-команду и запустите её на сервере.\n\n' + buildCurl();
      });
    if (copyBtn) copyBtn.hidden = false;
  });
}

function prettyJson(s) {
  try { return JSON.stringify(JSON.parse(s), null, 2); } catch (e) { return s; }
}
