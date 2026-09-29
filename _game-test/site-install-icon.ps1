# site-install-icon.ps1 — заменить эмодзи ⬇️ на SVG-стрелку в кнопке «Скачать на рабочий стол» (24.09.2026).
#
# Зачем: эмодзи рисуется системным цветным шрифтом — на iPhone и Windows это синяя стрелка,
# и перекрасить её через CSS нельзя. SVG-стрелка наследует color кнопки, поэтому её можно
# сделать фиолетовой в цвет темы сайта.
#
# Запуск из корня проекта:
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-install-icon.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-install-icon.ps1
#
# Копия каждой изменённой страницы — в backups\files\. В конце скрипт проверяет, что кнопок
# с эмодзи не осталось, и возвращает код 1, если что-то не заменилось.
param([switch]$DryRun)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root    = Split-Path -Parent $PSScriptRoot
$backDir = Join-Path $root 'backups\files'
$stamp   = Get-Date -Format 'yyyyMMdd-HHmmss'
if (-not (Test-Path $backDir)) { New-Item -ItemType Directory -Path $backDir | Out-Null }

$skipRe = '\\backups\\|\\_backup\\|\\_archive\\|\\_game-test\\|\\shots\\|\\admin-panel|\\sweb-migration|\\content\\|Инженерные|Досрочное'
$old = '<button class="icon-btn" type="button" id="installBtn" hidden aria-label="Скачать на рабочий стол" title="Скачать на рабочий стол">⬇️</button>'
# Второй вариант разметки: на странице /calculators/finance/dosrochnoe/ эмодзи записан
# без служебного символа U+FE0F — учитываем и его, иначе кнопка остаётся с синей стрелкой.
$old2 = '<button class="icon-btn" type="button" id="installBtn" hidden aria-label="Скачать на рабочий стол" title="Скачать на рабочий стол">⬇</button>'
$new = '<button class="icon-btn" type="button" id="installBtn" hidden aria-label="Скачать на рабочий стол" title="Скачать на рабочий стол"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><polyline points="7 10 12 15 17 10"/><path d="M5 20h14"/></svg></button>'

$files = Get-ChildItem $root -Recurse -File -Filter '*.html' |
         Where-Object { $_.FullName -notmatch $skipRe }
$changed = 0; $hits = 0

foreach ($f in $files) {
  $text = [IO.File]::ReadAllText($f.FullName, [Text.Encoding]::UTF8)
  $n = ([regex]::Matches($text, [regex]::Escape($old))).Count + ([regex]::Matches($text, [regex]::Escape($old2))).Count
  if ($n -eq 0) { continue }
  $hits += $n
  $changed++
  $rel = $f.FullName.Substring($root.Length).TrimStart('\')
  if ($DryRun) { Write-Host ('  ' + $n + ' замен  ' + $rel); continue }
  Copy-Item $f.FullName (Join-Path $backDir (($rel -replace '[\\/]', '-') + '.' + $stamp + '.bak')) -Force
  $updated = $text.Replace($old, $new).Replace($old2, $new)
  [IO.File]::WriteAllText($f.FullName, $updated, (New-Object Text.UTF8Encoding($false)))
}

if ($DryRun) {
  Write-Host ('РЕЖИМ ПРОВЕРКИ — файлы не менялись. Страниц с заменой: ' + $changed + ', замен: ' + $hits)
  return
}

$left = 0
foreach ($f in $files) {
  $t = [IO.File]::ReadAllText($f.FullName, [Text.Encoding]::UTF8)
  if ($t.Contains('id="installBtn"') -and $t -notmatch 'id="installBtn"[^>]*><svg') { $left++ }
}
Write-Host ('[+] страниц изменено: ' + $changed + ', замен: ' + $hits + ', кнопок без SVG осталось: ' + $left)
Write-Host ('[+] бэкапы: backups\files\*.html.' + $stamp + '.bak')
if ($left -gt 0) { write-Host 'ПЛОХО: часть страниц осталась с эмодзи'; exit 1 }
