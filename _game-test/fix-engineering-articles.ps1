# fix-engineering-articles.ps1 — привести семь статей отопительного кластера к общему шаблону.
#
# Что было не так (проверено 22.09.2026):
#   • сразу после <div class="prose"> стоял лишний символ «>» — он выводился на странице;
#   • не было рубрики .eyebrow;
#   • не было дисклеймера <p class="calc-note"> — из-за этого импортёр панели
#     (_game-test\import-blog-to-panel.php) не мог разобрать тело статьи
#     (он ищет диапазон от <div class="prose"> до calc-note) и статьи нельзя было завести в панель;
#   • ссылки «Читайте также» лежали в отдельной секции простым абзацем, а шаблон ждёт блок .seo-links.
#
# Что делает скрипт: убирает «>», ставит рубрику «Инженерные расчёты», переносит ссылки
# в блок .seo-links (соседние статьи + хаб инженерных расчётов + все статьи) и добавляет
# дисклеймер в конце тела. Файлы статей при этом вне заливки — это исходники сайта,
# они уедут вместе с обычным деплоем.
param([switch]$DryRun)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$enc = New-Object Text.UTF8Encoding($false)

$slugs = @(
  'cirkulyacionnyy-nasos-podbor', 'diametr-trub-otopleniya-raschet', 'gidrostrelka-raschet',
  'moshchnost-kotla-raschet', 'obem-sistemy-otopleniya-raschet', 'rasshiritelnyy-bak-podbor',
  'teploventilyator-vulkan'
)
$disc = '<p class="calc-note">Расчёты носят справочный характер: точные значения уточняйте в проекте, паспортах оборудования и у профильного специалиста. Все вычисления выполняются в браузере и не покидают ваше устройство.</p>'

foreach ($slug in $slugs) {
  $file = Join-Path $root ('blog\' + $slug + '\index.html')
  if (-not (Test-Path $file)) { throw "нет файла статьи: $file" }
  $lines = [IO.File]::ReadAllLines($file)

  # ── исходные ориентиры ──
  $i1 = -1; $i2 = -1
  for ($i = 0; $i -lt $lines.Count; $i++) {
    if ($lines[$i] -match '<div class="prose">') { if ($i1 -lt 0) { $i1 = $i } elseif ($i2 -lt 0) { $i2 = $i } }
  }
  if ($i1 -lt 0 -or $i2 -lt 0) { throw "$slug : не нашёл два блока .prose" }
  $cta = -1
  for ($i = $i1; $i -lt $i2; $i++) {
    if ($lines[$i] -match '<a href="/calculators/engineering/') { $cta = $i }
  }
  if ($cta -lt 0) { throw "$slug : не нашёл абзац со ссылкой на калькулятор" }
  $h2 = -1
  for ($i = $i2; $i -lt $lines.Count; $i++) { if ($lines[$i] -match '<h2>Читайте также</h2>') { $h2 = $i; break } }
  if ($h2 -lt 0) { throw "$slug : не нашёл блок «Читайте также»" }
  $rev = -1
  for ($i = $h2; $i -lt $lines.Count; $i++) { if ($lines[$i] -match '<section class="reviews"') { $rev = $i; break } }
  if ($rev -lt 0) { throw "$slug : не нашёл блок отзывов" }

  # ── ссылки из старого блока «Читайте также» ──
  $oldLinks = [regex]::Matches(($lines[$h2..($rev - 1)] -join "`n"), '<a href="([^"]+)">([^<]+)</a>')
  if ($oldLinks.Count -lt 2) { throw "$slug : в блоке «Читайте также» меньше двух ссылок" }

  # ── новое тело статьи ──
  $new = New-Object System.Collections.Generic.List[string]
  for ($i = 0; $i -le $cta; $i++) { $new.Add($lines[$i]) }
  $new.Add('')
  $new.Add('<span class="eyebrow">Смотрите также</span>')
  $new.Add('<div class="seo-links">')
  foreach ($m in $oldLinks) { $new.Add('  <a href="' + $m.Groups[1].Value + '">' + $m.Groups[2].Value + '</a>') }
  $new.Add('  <a href="/calculators/engineering/">Инженерные расчёты</a>')
  $new.Add('  <a href="/blog/">Все статьи</a>')
  $new.Add('</div>')
  $new.Add('')
  $new.Add($disc)
  $new.Add('</div>')
  $new.Add('</div>')
  $new.Add('')
  for ($i = $rev; $i -lt $lines.Count; $i++) { $new.Add($lines[$i]) }

  # ── рубрика в начале тела и удаление лишнего «>» ──
  $res = New-Object System.Collections.Generic.List[string]
  $seen = 0
  for ($i = 0; $i -lt $new.Count; $i++) {
    $res.Add($new[$i])
    if ($new[$i] -match '<div class="prose">') {
      $seen++
      if ($seen -eq 1) {
        $res.Add('<span class="eyebrow">Инженерные расчёты</span>')
        # лишний «>» стоит сразу после открытия блока
        if ($new[$i + 1].Trim() -eq '>') { $i++ }
        elseif ($new[$i + 2].Trim() -eq '>') { $res.Add($new[$i + 1]); $i += 2 }
      }
    }
  }

  if ($DryRun) { Write-Output ("  " + $slug + " : строк " + $lines.Count + " -> " + $res.Count + " (примерка)") }
  else {
    [IO.File]::WriteAllLines($file, $res, $enc)
    Write-Output ("  " + $slug + " : строк " + $lines.Count + " -> " + $res.Count + ", ссылок в блоке " + $oldLinks.Count + " + 2")
  }
}
