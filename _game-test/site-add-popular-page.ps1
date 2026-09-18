# site-add-popular-page.ps1 — создаёт страницу /popular/ (шаг 8.4).
#
# Страница собирается из /contact/index.html: берём её шапку, подвал и подключения стилей
# (так вёрстка гарантированно совпадает с остальным сайтом), меняем заголовок, описание,
# canonical/og и заменяем содержимое <main> на список популярного.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-popular-page.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-popular-page.ps1
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-popular-page.ps1 -Remove

param([switch]$DryRun, [switch]$Remove)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root    = Split-Path -Parent $PSScriptRoot
$src     = Join-Path $root 'contact\index.html'
$dir     = Join-Path $root 'popular'
$file    = Join-Path $dir 'index.html'
$backDir = Join-Path $root 'backups\files'
$stamp   = Get-Date -Format 'yyyyMMdd-HHmmss'

if (-not (Test-Path $backDir)) { New-Item -ItemType Directory -Path $backDir | Out-Null }

if ($Remove) {
    if (Test-Path $file) {
        Copy-Item $file (Join-Path $backDir ('popular-index.html.' + $stamp + '.bak')) -Force
        Write-Host 'удалил /popular/index.html'
    }
    if (Test-Path $dir) { Remove-Item $dir -Recurse -Force }
    exit 0
}

if (-not (Test-Path $src)) { Write-Host 'Нет источника: contact\index.html'; exit 2 }
if ($DryRun) { Write-Host 'создал бы /popular/index.html из contact\index.html'; exit 0 }

$text = [System.IO.File]::ReadAllText($src)

# ── голова: заголовок, описание, canonical, og ──
$text = $text.Replace('<title>Контакты — CalcDoc</title>', '<title>Популярное на сайте — CalcDoc</title>')
$text = [regex]::Replace($text, '(?m)^(\s*)<meta name="description" content="[^"]*" />',
    '$1<meta name="description" content="Что чаще всего открывают на CalcDoc: популярные калькуляторы, генераторы и статьи за неделю." />', 1)
$text = [regex]::Replace($text, '<link rel="canonical" href="[^"]*" />',
    '<link rel="canonical" href="https://calc-doc.ru/popular/" />', 1)
$text = [regex]::Replace($text, '<meta property="og:url" content="[^"]*" />',
    '<meta property="og:url" content="https://calc-doc.ru/popular/" />', 1)
$text = [regex]::Replace($text, '<meta property="og:title" content="[^"]*" />',
    '<meta property="og:title" content="Популярное на сайте" />', 1)
$text = [regex]::Replace($text, '<meta property="og:description" content="[^"]*" />',
    '<meta property="og:description" content="Популярные калькуляторы, генераторы и статьи CalcDoc за неделю." />', 1)

# ── содержимое страницы ──
$main = @'
  <main>
    <div class="container" style="padding:26px 0 10px">
      <nav aria-label="Хлебные крошки" style="font-size:14px;color:var(--text-muted);margin-bottom:6px">
        <a href="/">Главная</a> <span aria-hidden="true">·</span> Популярное
      </nav>
      <h1>Популярное на сайте</h1>
      <p style="max-width:780px">Что чаще всего открывают на CalcDoc за последние 7 дней. Список считает
        собственный счётчик сайта: без cookie, без слежки и без сторонних сервисов — роботов он не считает.</p>
      <section style="max-width:780px;margin:18px 0 8px;padding:18px;border:1px solid var(--border);border-radius:var(--radius);background:var(--card-bg)">
        <ol id="popularList" style="margin:0;padding-left:22px;line-height:1.9">
          <li><a href="/calculators/finance/mortgage/">Ипотечный калькулятор</a></li>
          <li><a href="/calculators/finance/deposit/">Калькулятор вкладов</a></li>
          <li><a href="/calculators/finance/credit/">Кредитный калькулятор</a></li>
          <li><a href="/calculators/finance/ndfl/">Калькулятор НДФЛ</a></li>
          <li><a href="/calculators/finance/vat/">Калькулятор НДС</a></li>
          <li><a href="/calculators/construction/wallpaper/">Калькулятор обоев</a></li>
          <li><a href="/generators/">Генераторы документов</a></li>
        </ol>
        <p id="popularNote" style="margin:12px 0 0;font-size:13px;color:var(--text-muted)">Пока показаны самые
          востребованные инструменты: список по данным счётчика обновится, когда наберётся статистика
          (панель собирает его раз в сутки).</p>
      </section>
      <p style="display:flex;gap:12px;flex-wrap:wrap;margin-top:14px">
        <a class="btn btn-glass" href="/calculators/">Все калькуляторы</a>
        <a class="btn btn-glass" href="/generators/">Генераторы документов</a>
        <a class="btn btn-glass" href="/blog/">Статьи и инструкции</a>
      </p>
    </div>
    <script type="module" src="/js/popular-page.js?v=1"></script>
  </main>
'@

$text = [regex]::Replace($text, '(?s)<main>.*?</main>', $main.TrimEnd("`r", "`n"), 1)
# убираем скрипт контактной формы — на этой странице его нет
$text = [regex]::Replace($text, '(?m)^\s*<script src="/js/contact\.js[^"]*" defer></script>\r?\n', '')

if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir | Out-Null }
[System.IO.File]::WriteAllText($file, $text, (New-Object System.Text.UTF8Encoding($false)))
Write-Host ('создал /popular/index.html (' + $text.Length + ' байт)')
