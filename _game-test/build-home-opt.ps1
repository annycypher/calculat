# build-home-opt.ps1 — две правки главной по протоколу PROMPT-PROFILE-MAIN-PAGE.md.
#
#   (1) Инлайн home.css: содержимое home.css вставляется в <head> как <style id="home-inline">
#       на место строки <link rel="stylesheet" href="/home.css?v=N"> — на главной остаётся
#       ОДИН блокирующий CSS (bundle.css). Сам файл home.css на сервере остаётся.
#   (2) Склейка JS: js/ui.js + js/home.js + js/tool-of-day.js и их статические зависимости
#       (print-result, share-params, share, ads, share-png) → /js/home-bundle.js, БЕЗ минификации.
#       Каждый файл — в своей IIFE с "use strict" (файлы были ES-модулями, значит строгий режим
#       и своя область видимости — так коллизии имён вроде четырёх разных toast() невозможны).
#       Статические import внутри ui.js сняты (зависимости уже внутри бандла), import chunkload
#       в share-png.js заменён локальной обёрткой с динамическим import, export в tool-of-day.js снят.
#       Подключение: одна строка <script defer src="/js/home-bundle.js?v=42"> вместо трёх модулей.
#
# Копии правимых файлов кладутся в backups\files\. Запуск с -DryRun — только план, без записи.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\build-home-opt.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\build-home-opt.ps1
param([switch]$DryRun)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root  = Split-Path -Parent $PSScriptRoot
$jsDir = Join-Path $root 'js'
$back  = Join-Path $root 'backups\files'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$bundleVersion = 42
$chunkVersion  = 8
if (-not (Test-Path $back)) { New-Item -ItemType Directory -Force -Path $back | Out-Null }

function Read-Text([string]$p) { [IO.File]::ReadAllText($p, [Text.Encoding]::UTF8) }
function Write-Text([string]$p, [string]$t) { [IO.File]::WriteAllText($p, $t, (New-Object Text.UTF8Encoding($false))) }

# ── 1. Сборка home-bundle.js ──────────────────────────────────────────────────
$parts = @(
  @{ file = 'print-result.js'; note = 'печать только результата (был статический импорт ui.js)' },
  @{ file = 'share-params.js'; note = '«поделиться» с параметрами расчёта' },
  @{ file = 'share.js';        note = 'кнопки «поделиться»' },
  @{ file = 'ads.js';          note = 'рекламные блоки' },
  @{ file = 'share-png.js';    note = 'PNG-карточка расчёта (import chunkload → динамический)' },
  @{ file = 'ui.js';           note = 'ядро интерфейса (минифицирован, статические import сняты)' },
  @{ file = 'home.js';         note = 'интерактив главной страницы' },
  @{ file = 'tool-of-day.js';  note = 'инструмент дня (export снят — никто не импортирует)' }
)

$out = New-Object Text.StringBuilder
[void]$out.AppendLine('/* home-bundle.js — склейка без минификации (шаг 5.1-5.4 PROMPT-PROFILE-MAIN-PAGE.md).')
[void]$out.AppendLine('   Собран скриптом _game-test\build-home-opt.ps1 из: print-result.js, share-params.js, share.js,')
[void]$out.AppendLine('   ads.js, share-png.js, ui.js, home.js, tool-of-day.js — каждый файл в своей IIFE со строгим режимом.')
[void]$out.AppendLine('   Руками не править: правьте исходные файлы в /js/ и пересоберите бандл. */')

foreach ($p in $parts) {
  $path = Join-Path $jsDir $p.file
  if (-not (Test-Path $path)) { throw ('нет файла ' + $path) }
  $c = Read-Text $path
  if ($p.file -eq 'ui.js') {
    $c = [regex]::Replace($c, 'import"/js/(print-result|share-params|share-png|share|ads)\.js\?v=\d+";', '')
  }
  if ($p.file -eq 'share-png.js') {
    $c = [regex]::Replace($c, "(?m)^\s*import\s*\{[^}]*\}\s*from\s*'/js/chunkload\.js\?v=\d+';?[ \t]*\r?\n", '')
    $shim = "function loadChunkedScript(name){return import('/js/chunkload.js?v=" + $chunkVersion + "').then(function(m){return m.loadChunkedScript(name)})}"
    $c = $shim + "`r`n" + $c
  }
  if ($p.file -eq 'tool-of-day.js') { $c = [regex]::Replace($c, '(?m)^export\s+', '') }
  [void]$out.AppendLine('')
  [void]$out.AppendLine('/* === ' + $p.file + ' — ' + $p.note + ' === */')
  [void]$out.AppendLine('(function(){"use strict";')
  [void]$out.AppendLine($c.TrimEnd())
  [void]$out.AppendLine('})();')
}
$bundle = $out.ToString()
if ([regex]::IsMatch($bundle, '(?m)^\s*(import|export)\s')) { throw 'в бандле остались import/export — сборка отменена' }

# ── 2. Правка index.html: инлайн home.css + одна строка бандла ────────────────
$idxPath = Join-Path $root 'index.html'
$html    = Read-Text $idxPath
$css     = Read-Text (Join-Path $root 'home.css')
if ([regex]::IsMatch($css, '</style')) { throw 'в home.css есть </style> — инлайн отменён' }

$linkRe   = [regex]'(?m)^(?<ind>[ \t]*)<link rel="stylesheet" href="/home\.css\?v=\d+" />[ \t]*\r?\n'
$linkHits = $linkRe.Matches($html).Count
if ($linkHits -eq 0 -and $html.Contains('id="home-inline"')) {
  Write-Host 'инлайн home.css уже сделан — шаг пропущен'
} elseif ($linkHits -ne 1) {
  throw ('ожидалась ровно одна строка home.css в index.html, найдено ' + $linkHits)
} else {
  $html = $linkRe.Replace($html, ('${ind}<style id="home-inline">' + "`r`n" + $css.TrimEnd() + "`r`n" + '${ind}</style>' + "`r`n"), 1)
}

$scriptRe   = [regex]'(?m)^[ \t]*<script type="module" src="/js/ui\.js\?v=\d+"></script>\r?\n[ \t]*<script type="module" src="/js/home\.js\?v=\d+"></script>\r?\n[ \t]*<script type="module" src="/js/tool-of-day\.js\?v=\d+"></script>'
$scriptHits = $scriptRe.Matches($html).Count
if ($scriptHits -eq 0 -and $html.Contains('home-bundle.js?v=')) {
  Write-Host 'подключение бандла уже сделано — шаг пропущен'
} elseif ($scriptHits -ne 1) {
  throw ('ожидался один блок из трёх модулей в index.html, найдено ' + $scriptHits)
} else {
  $html = $scriptRe.Replace($html, ('  <script defer src="/js/home-bundle.js?v=' + $bundleVersion + '"></script>'), 1)
}

Write-Host ('home-bundle.js: ' + $bundle.Length + ' символов, участников ' + $parts.Count)
Write-Host ('index.html: было ' + (Read-Text $idxPath).Length + ' → станет ' + $html.Length + ' символов')
Write-Host ('вставок: home.css → <style id="home-inline"> ' + $linkHits + ', три модуля → один defer ' + $scriptHits)

if ($DryRun) { Write-Host 'РЕЖИМ ПРОВЕРКИ — файлы не тронуты.'; return }

foreach ($f in @('index.html', 'js\home-bundle.js')) {
  $src = Join-Path $root $f
  if (Test-Path $src) {
    Copy-Item $src (Join-Path $back (($f -replace '[\\/]', '-') + '.' + $stamp + '.bak')) -Force
  }
}
Write-Text (Join-Path $root 'js\home-bundle.js') $bundle
Write-Text $idxPath $html
Write-Host ('ЗАПИСАНО: js\home-bundle.js, index.html. Копии — backups\files\*.' + $stamp + '.bak')
