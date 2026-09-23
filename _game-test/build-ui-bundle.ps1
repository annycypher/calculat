# build-ui-bundle.ps1 — общий бандл интерфейса для всех страниц, КРОМЕ главной (фаза 7).
#
# Зачем: на 85 страницах ui.js подключается как модуль и тянет пять статических зависимостей
# (print-result, share-params, share, ads, share-png) — это шесть запросов на каждую страницу.
# Здесь они склеиваются в ОДИН общий файл /js/ui-bundle.js (кэшируется между страницами),
# без минификации, каждый файл — в своей IIFE со "use strict" (файлы были ES-модулями,
# поэтому строгий режим и своя область видимости сохранены — коллизии имён исключены).
# Статические import внутри ui.js снимаются, import chunkload в share-png.js заменяется
# локальной обёрткой с динамическим import (chunkload грузится только при PNG-шеринге).
#
# Домашний бандл (home-bundle.js, с home.js и tool-of-day.js) собирает build-home-opt.ps1.
# Подключение на страницах: <script defer src="/js/ui-bundle.js?v=42"> вместо модуля ui.js.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\build-ui-bundle.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\build-ui-bundle.ps1
param([switch]$DryRun)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root   = Split-Path -Parent $PSScriptRoot
$jsDir  = Join-Path $root 'js'
$back   = Join-Path $root 'backups\files'
$stamp  = Get-Date -Format 'yyyyMMdd-HHmmss'
$chunkVersion = 8
if (-not (Test-Path $back)) { New-Item -ItemType Directory -Force -Path $back | Out-Null }

function Read-Text([string]$p) { [IO.File]::ReadAllText($p, [Text.Encoding]::UTF8) }
function Write-Text([string]$p, [string]$t) { [IO.File]::WriteAllText($p, $t, (New-Object Text.UTF8Encoding($false))) }

$parts = @(
  @{ file = 'print-result.js'; note = 'печать только результата (был статический импорт ui.js)' },
  @{ file = 'share-params.js'; note = '«поделиться» с параметрами расчёта' },
  @{ file = 'share.js';        note = 'кнопки «поделиться»' },
  @{ file = 'ads.js';          note = 'рекламные блоки' },
  @{ file = 'share-png.js';    note = 'PNG-карточка расчёта (import chunkload → динамический)' },
  @{ file = 'ui.js';           note = 'ядро интерфейса (минифицирован, статические import сняты)' },
  @{ file = 'metrica-goals.js'; note = 'цели Метрики: data-metric-goal → reachGoal (клик и отправка формы)' }
)

$out = New-Object Text.StringBuilder
[void]$out.AppendLine('/* ui-bundle.js — общий бандл интерфейса для всех страниц, кроме главной (фаза 7).')
[void]$out.AppendLine('   Собран скриптом _game-test\build-ui-bundle.ps1 из: print-result.js, share-params.js, share.js,')
[void]$out.AppendLine('   ads.js, share-png.js, ui.js, metrica-goals.js — каждый файл в своей IIFE со строгим режимом.')
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
  [void]$out.AppendLine('')
  [void]$out.AppendLine('/* === ' + $p.file + ' — ' + $p.note + ' === */')
  [void]$out.AppendLine('(function(){"use strict";')
  [void]$out.AppendLine($c.TrimEnd())
  [void]$out.AppendLine('})();')
}
$bundle = $out.ToString()
if ([regex]::IsMatch($bundle, '(?m)^\s*(import|export)\s')) { throw 'в бандле остались import/export — сборка отменена' }

$target = Join-Path $jsDir 'ui-bundle.js'
$old = if (Test-Path $target) { Read-Text $target } else { '' }
Write-Host ('ui-bundle.js: ' + $bundle.Length + ' символов, участников ' + $parts.Count)
Write-Host ('изменений относительно текущего файла: ' + [int]($old -ne $bundle))
if ($DryRun) { Write-Host 'РЕЖИМ ПРОВЕРКИ — файлы не тронуты.'; return }
if ($old -ne $bundle) {
  if ($old -ne '') { Copy-Item $target (Join-Path $back ('js-ui-bundle.js.' + $stamp + '.bak')) -Force }
  Write-Text $target $bundle
  Write-Host ('ЗАПИСАНО: js\ui-bundle.js (' + (Get-Item $target).Length + ' Б)')
} else {
  Write-Host 'js\ui-bundle.js уже актуален — запись не требуется'
}
