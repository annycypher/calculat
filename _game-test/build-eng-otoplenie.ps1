# build-eng-otoplenie.ps1 — собирает страницу /calculators/engineering/otoplenie-obem/ (фаза 2.1).
#
# Источник содержимого — файл владельца «Инженерные\otoplenie-obem.html»: калькулятор,
# SEO-текст, JSON-LD и скрипт переносятся 1:1, ничего не переписывается.
# Оболочка (шапка, крошки, слоты рекламы, форма отзыва, подвал) берётся у эталонного
# живого калькулятора /calculators/auto/fuel/ — так страница совпадает с оформлением сайта.
#
# Правки содержимого владельца — только необходимые:
#   1) его палитра (:root) переназначается на переменные сайта (истина = styles.css),
#      правило body убирается — иначе оно спорит с оформлением сайта;
#   2) результату (.res) даётся id="result" — так его находит печать сайта;
#   3) кнопке копирования добавляется data-metric-goal="расчёт выполнен",
#      кнопке печати — data-print="btn";
#   4) адреса в canonical, og и JSON-LD переводятся на /calculators/engineering/otoplenie-obem/.
#
# Копия прежней версии страницы (если есть) — в backups\files\.
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\build-eng-otoplenie.ps1

param([switch]$DryRun)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root = Split-Path -Parent $PSScriptRoot
$src  = Join-Path $root 'Инженерные\otoplenie-obem.html'
$shell= Join-Path $root 'calculators\auto\fuel\index.html'
$outDir = Join-Path $root 'calculators\engineering\otoplenie-obem'
$out   = Join-Path $outDir 'index.html'
$back  = Join-Path $root 'backups\files'
if (-not (Test-Path $back)) { New-Item -ItemType Directory -Path $back | Out-Null }
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$URL   = 'https://calc-doc.ru/calculators/engineering/otoplenie-obem/'

$S = [IO.File]::ReadAllText($shell, [Text.Encoding]::UTF8)
$O = [IO.File]::ReadAllText($src,   [Text.Encoding]::UTF8)

function Part([string]$text, [string]$from, [string]$to, [string]$label) {
  $i = $text.IndexOf($from); if ($i -lt 0) { throw "маркер начала не найден: $label" }
  $j = $text.IndexOf($to, $i); if ($j -lt 0) { throw "маркер конца не найден: $label" }
  return $text.Substring($i, $j - $i + $to.Length)
}

# ── оболочка сайта (из эталонного калькулятора) ──
$head    = Part $S '<!DOCTYPE html>' '</head>' 'голова эталона'
$header  = Part $S '<header class="site-header app-header">' '</header>' 'шапка эталона'
$footer  = Part $S '<footer class="site-footer">' '</footer>' 'подвал эталона'
$reviews = Part $S '<section class="reviews"' '</section>' 'форма отзыва'

# ── содержимое владельца ──
$style = Part $O '<style>' '</style>' 'стили владельца'
$iCalc = $O.IndexOf('<!-- ================= КАЛЬКУЛЯТОР'); if ($iCalc -lt 0) { throw 'блок калькулятора владельца не найден' }
$iJson = $O.IndexOf('<script type="application/ld+json">'); if ($iJson -lt 0) { throw 'JSON-LD владельца не найден' }
$content = $O.Substring($iCalc, $iJson - $iCalc)
$content = $content -replace '(?s)\s*</div>\s*$', ''            # закрывающий </div> обёртки .wrap
$json = ([regex]::Matches($O, '(?s)<script type="application/ld\+json">.*?</script>') | ForEach-Object { $_.Value }) -join "`r`n"
$iScr = $O.LastIndexOf('<script>'); if ($iScr -lt 0) { throw 'скрипт владельца не найден' }
$iEnd = $O.LastIndexOf('</script>'); if ($iEnd -lt 0) { throw 'конец скрипта владельца не найден' }
$script = $O.Substring($iScr, $iEnd - $iScr + 9)

# ── палитра владельца → переменные сайта (истина = styles.css) ──
$style = $style -replace '(?s):root\s*\{.*?\}', @"
:root{
  /* Палитра сайта (styles.css): истина там. Здесь только имена, которыми
     пользуется вёрстка калькулятора владельца. */
  --surface: var(--card-bg); --txt: var(--text); --mut: var(--text-muted);
  --vio: var(--primary); --vio2: var(--primary-dark); --cyan: var(--icon-cyan);
  --r: var(--radius);
}
"@
$style = $style -replace '(?s)body\{[^}]*\}\s*', ''            # правило body убрано: им заведует сайт

# ── минимальные правки разметки владельца ──
$content = $content -replace '<div class="res">', '<div class="res" id="result">'
$content = $content -replace '(<button class="btn btn-vio" id="copyBtn")', '$1 data-metric-goal="расчёт выполнен"'

$content = $content -replace 'onclick="window\.print\(\)"', 'data-print="btn"'

# «Поделиться»: на телефонах сначала системное меню, иначе — как у владельца, копирование ссылки
# Кнопка «Поделиться»: обработчик владельца копирует ссылку — оставляем его как есть и добавляем
# перехват на мобильных (navigator.share), чтобы вместо копирования открылось системное меню.
# Слушатель ставится в фазе перехвата на тот же элемент и вставляется в конец скрипта владельца:
# текст владельца не переписывается вообще, а на десктопе поведение остаётся прежним.
$shareHook = @"
/* CalcDoc: на мобильных — системное «Поделиться» (navigator.share), иначе работает копирование ссылки. */
  var shareBtnEl = document.getElementById('shareBtn');
  if (shareBtnEl) {
    shareBtnEl.addEventListener('click', function (ev) {
      if (navigator.share) {
        ev.stopImmediatePropagation();
        navigator.share({ title: document.title, url: location.href }).catch(function () {});
      }
    }, true);
  }
"@
# Встречная ссылка: в блоке ссылок владельца добавляем путь к хабу категории (карточка хаба
# на эту страницу уже ведёт, а здесь — обратная связь для читателя и поиска).
$linkAnchor = '<a href="/calculators/construction/">'
$hubLink = '<a href="/calculators/engineering/">Инженерные расчёты: все расчёты раздела</a>
      '
if ($content.Contains($linkAnchor)) { $content = $content.Replace($linkAnchor, $hubLink + $linkAnchor) }
else { Write-Host 'ВНИМАНИЕ: в блоке ссылок владельца не найдена строка на «стройку» — встречная ссылка не добавлена' }

$iTail = $script.LastIndexOf('})();')
if ($iTail -ge 0) { $script = $script.Substring(0, $iTail) + $shareHook + $script.Substring($iTail) }
else { Write-Host 'ВНИМАНИЕ: не найден конец скрипта владельца — перехват «Поделиться» пропущен' }

  # Обратная ссылка: калькулятор → статья кластера (по заданию владельца)
$content = $content.Replace('<a href="/calculators/engineering/">Инженерные расчёты: все расчёты раздела</a>', '<a href="/calculators/engineering/">Инженерные расчёты: все расчёты раздела</a>
      <a href="/blog/obem-sistemy-otopleniya-raschet/">Статья: как рассчитать объём системы отопления</a>')

# ── голова: мета из файла владельца + наши адреса ──
$title = [regex]::Match($O, '<title>(.*?)</title>').Groups[1].Value
$desc  = [regex]::Match($O, 'name="description" content="([^"]+)"').Groups[1].Value
$head = $head -replace '(?s)<title>.*?</title>', ('<title>' + $title + '</title>')
$head = $head -replace '(?s)<meta name="description"[^>]*>', ('<meta name="description" content="' + $desc + '" />')
$head = $head -replace '(?s)<link rel="canonical"[^>]*>', ('<link rel="canonical" href="' + $URL + '" />')
$head = $head -replace '(?s)<meta property="og:title"[^>]*>', '<meta property="og:title" content="Калькулятор объёма системы отопления — CalcDoc" />'
$head = $head -replace '(?s)<meta property="og:description"[^>]*>', ('<meta property="og:description" content="' + $desc + '" />')
$head = $head -replace '(?s)<meta property="og:url"[^>]*>', ('<meta property="og:url" content="' + $URL + '" />')
$head = $head -replace '(?s)<script type="application/ld\+json">.*?</script>\s*', ''   # JSON-LD эталона убираем

$bread = @"
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    "itemListElement": [
      { "@type": "ListItem", "position": 1, "name": "Главная", "item": "https://calc-doc.ru/" },
      { "@type": "ListItem", "position": 2, "name": "Калькуляторы", "item": "https://calc-doc.ru/calculators/" },
      { "@type": "ListItem", "position": 3, "name": "Инженерные расчёты", "item": "https://calc-doc.ru/calculators/engineering/" },
      { "@type": "ListItem", "position": 4, "name": "Объём системы отопления", "item": "$URL" }
    ]
  }
  </script>
"@
$json = $json -replace 'https://calc-doc\.ru/calculators/construction/otoplenie-obem/', $URL
$head = $head -replace '</head>', ($style + "`r`n" + $json + "`r`n" + $bread + "`r`n</head>")

# ── сборка страницы ──
$page = @"
$head
$header

<main>
  <div class="container tool-hero">
    <nav class="breadcrumbs"><a href="/">Главная</a> / <a href="/calculators/">Калькуляторы</a> / <a href="/calculators/engineering/">Инженерные расчёты</a> / Объём системы отопления</nav>
    <h1>Калькулятор объёма системы отопления</h1>
    <p class="tool-meta">Радиаторы + трубы + котёл + тёплый пол → общий объём, подбор расширительного бака и количество теплоносителя к закупке.</p>
    <p class="calc-note">Расчёты носят справочный характер.</p>
  </div>

  <!--SLOT:banner-top-->
  <!--/SLOT:banner-top-->
  <!--SLOT:ads-top-->
  <!--/SLOT:ads-top-->

  <div class="container section">
$content
  </div>

  <!--SLOT:banner-after-tool-->
  <!--/SLOT:banner-after-tool-->
  <!--SLOT:ads-after-tool-->
  <!--/SLOT:ads-after-tool-->

$reviews
  <script src="/js/reviews.js?v=33" defer></script>
  <!--SLOT:ads-before-footer-->
  <!--/SLOT:ads-before-footer-->
</main>

$footer

<script type="module" src="/js/ui.js?v=33"></script>
$script

</body>
</html>
"@

if ($DryRun) {
  Write-Host ('ПРИМЕРКА: страница собралась бы на ' + $page.Length + ' знаков')
  Write-Host ('  голова ' + $head.Length + ' + стили владельца ' + $style.Length + ' + содержимое ' + $content.Length + ' + скрипт ' + $script.Length)
  Write-Host ('  заголовок: ' + $title)
  return
}

New-Item -ItemType Directory -Force -Path $outDir | Out-Null
if (Test-Path $out) { Copy-Item $out (Join-Path $back ('calculators-engineering-otoplenie-obem-index.html.' + $stamp + '.bak')) -Force }
[IO.File]::WriteAllText($out, $page, (New-Object System.Text.UTF8Encoding($false)))
Write-Host ('Собрана страница: ' + $out.Substring($root.Length) + '  (' + (Get-Item $out).Length + ' Б)')
