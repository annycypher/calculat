# build-home-bundle.ps1 - tolko skleika js/home-bundle.js (nuzhna dlya Fix 2c, chast B).
# build-home-opt.ps1 zdes ne goditsya: on posle skleiki pravit index.html, a na tekuschei
# versii stranitsy padaet (ozhidaetsya odin blok iz tryoh modulei, naydeno 0).
# Skleika zdes - identichna pervoy polovine build-home-opt.ps1.
param([switch]$DryRun)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$root = Split-Path -Parent $PSScriptRoot
$jsDir = Join-Path $root 'js'
$chunkVersion = 8
function Read-Text([string]$p) { [IO.File]::ReadAllText($p, [Text.Encoding]::UTF8) }
function Write-Text([string]$p, [string]$t) { [IO.File]::WriteAllText($p, $t, (New-Object Text.UTF8Encoding($false))) }

$parts = @(
  @{ file = 'print-result.js'; note = 'печать только результата (был статический импорт ui.js)' },
  @{ file = 'share-params.js'; note = '«поделиться» с параметрами расчёта' },
  @{ file = 'share.js';        note = 'кнопки «поделиться»' },
  @{ file = 'ads.js';          note = 'рекламные блоки' },
  @{ file = 'share-png.js';    note = 'PNG-карточка расчёта (import chunkload → динамический)' },
  @{ file = 'ui.js';           note = 'ядро интерфейса (минифицирован, статические import сняты)' },
  @{ file = 'home.js';         note = 'интерактив главной страницы' },
  @{ file = 'tool-of-day.js';  note = 'инструмент дня (export снят — никто не импортирует)' },
  @{ file = 'metrica-goals.js'; note = 'цели Метрики: data-metric-goal → reachGoal (клик и отправка формы)' }
)

$out = New-Object Text.StringBuilder
[void]$out.AppendLine('/* home-bundle.js — склейка без минификации (шаг 5.1-5.4 PROMPT-PROFILE-MAIN-PAGE.md).')
[void]$out.AppendLine('   Собран скриптом _game-test\build-home-opt.ps1 из: print-result.js, share-params.js, share.js,')
[void]$out.AppendLine('   ads.js, share-png.js, ui.js, home.js, tool-of-day.js, metrica-goals.js — каждый файл в своей IIFE со строгим режимом.')
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


$target = Join-Path $jsDir 'home-bundle.js'
$old = if (Test-Path $target) { Read-Text $target } else { '' }
Write-Host ('home-bundle.js: ' + $bundle.Length + ' simvolov, uchastnikov ' + $parts.Count + ', izmenenii ' + [int]($old -ne $bundle))
if ($DryRun) { Write-Host 'REZHIM PROVERKI - faily ne trognuty.'; return }
if ($old -ne $bundle) { Write-Text $target $bundle; Write-Host ('ZAPISANO: js/home-bundle.js (' + (Get-Item $target).Length + ' B)') } else { Write-Host 'js/home-bundle.js uzhe aktualen' }

