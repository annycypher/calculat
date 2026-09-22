# open-site.ps1 — открытие сайта для поиска (снятие режима переноса).
#
# Что делает:
#   1) переписывает robots.txt: вместо «Disallow: /» разрешает обход, оставляет закрытыми только
#      служебные адреса поиска и офлайна, добавляет Sitemap и Clean-param для utm-меток;
#   2) на страницах из sitemap.xml меняет <meta name="robots" content="noindex, nofollow"> на «index, follow»;
#      страницы вне карты (404, offline, search, privacy, шаблон) не трогает;
#   3) перед каждой правкой кладёт копию файла в backups\files\open-site-<дата>\<путь>;
#   4) пишет список изменённых файлов для заливки: shots\_upload-open.txt.
#
# Запуск (из корня проекта):
#   powershell -File _game-test\open-site.ps1 -DryRun   # показать, что изменится, ничего не правя
#   powershell -File _game-test\open-site.ps1 -Apply    # сделать правки и бэкапы
#   (заливка потом: powershell -File sweb-migration\upload-file.ps1 -ListFile shots\_upload-open.txt)

param([switch]$DryRun, [switch]$Apply, [string]$Base = 'https://calc-doc.ru')

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
if (-not $DryRun -and -not $Apply) { throw 'Укажите режим: -DryRun (показать) или -Apply (править).' }

$stamp = Get-Date -Format 'yyyy-MM-dd'
$backupDir = Join-Path $root ('backups\files\open-site-' + $stamp)

# ── список страниц из карты сайта ────────────────────────────────────────────────────────────────
$sm = [IO.File]::ReadAllText((Join-Path $root 'sitemap.xml'), [Text.Encoding]::UTF8)
$locs = @([regex]::Matches($sm, '<loc>([^<]+)</loc>') | ForEach-Object { $_.Groups[1].Value })
$files = @()
foreach ($u in $locs) {
  $path = ([Uri]$u).AbsolutePath                                   # «/», «/blog/», «/search.html»
  $rel = if ($path -eq '/') { 'index.html' }
         elseif ($path.EndsWith('/')) { ($path.TrimStart('/') + 'index.html') }
         else { $path.TrimStart('/') }
  $files += ($rel -replace '/', '\')
}
$files = @($files | Select-Object -Unique)
Write-Host ('адресов в карте: ' + $locs.Count + ' | файлов к правке: ' + $files.Count)

# ── robots.txt ───────────────────────────────────────────────────────────────────────────────────
$robotsNew = @(
  '# Открытый сайт CalcDoc (режим переноса снят 23.09.2026 по команде владельца).',
  '# До этого стояли «Disallow: /» и noindex на страницах — сайт не попадал в поиск.',
  '# Директива Host в Яндекс.Вебмастере не используется с 2018 года, поэтому её нет.',
  '',
  'User-agent: *',
  'Allow: /',
  'Disallow: /search.html',
  'Disallow: /offline.html',
  '',
  '# Метки систем аналитики не создают отдельных адресов',
  'Clean-param: utm_source&utm_medium&utm_campaign&utm_content&utm_term&yclid&gclid&fbclid',
  '',
  'Sitemap: https://calc-doc.ru/sitemap.xml',
  ''
) -join "`r`n"

# ── страницы: noindex -> index ───────────────────────────────────────────────────────────────────
$oldMeta = '<meta name="robots" content="noindex, nofollow" />'
$newMeta = '<meta name="robots" content="index, follow" />'
$plan = @()
$changedRel = New-Object System.Collections.Generic.List[string]
foreach ($rel in $files) {
  $full = Join-Path $root $rel
  if (-not (Test-Path $full)) { $plan += [pscustomobject]@{ rel = $rel; state = 'нет файла' }; continue }
  $text = [IO.File]::ReadAllText($full, [Text.Encoding]::UTF8)
  $has = $text.Contains($oldMeta)
  $plan += [pscustomobject]@{ rel = $rel; state = $(if ($has) { 'откроем' } else { 'уже открыта / нет метки' }) }
  if ($has -and $Apply) {
    $bak = Join-Path $backupDir $rel
    $dir = Split-Path -Parent $bak
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    Copy-Item -LiteralPath $full -Destination $bak -Force
    $text = $text.Replace($oldMeta, $newMeta)
    [IO.File]::WriteAllText($full, $text, (New-Object Text.UTF8Encoding($false)))
    $changedRel.Add($rel)
  }
}

if ($DryRun) {
  $plan | ForEach-Object { '  {0,-50} {1}' -f $_.rel, $_.state }
  Write-Host 'РЕЖИМ ПРОВЕРКИ — файлы не менялись.' -ForegroundColor Yellow
  Write-Host 'robots.txt станет таким:' -ForegroundColor Cyan
  $robotsNew -split "`r`n" | ForEach-Object { '  ' + $_ }
  return
}

# ── применяем robots.txt и список к заливке ──────────────────────────────────────────────────────
if (-not (Test-Path $backupDir)) { New-Item -ItemType Directory -Path $backupDir -Force | Out-Null }
Copy-Item -LiteralPath (Join-Path $root 'robots.txt') -Destination (Join-Path $backupDir 'robots.txt') -Force
[IO.File]::WriteAllText((Join-Path $root 'robots.txt'), $robotsNew, (New-Object Text.UTF8Encoding($false)))

$upload = New-Object System.Collections.Generic.List[string]
$upload.Add('robots.txt')
foreach ($p in $changedRel) { $upload.Add(($p -replace '\\', '/')) }
[IO.File]::WriteAllLines((Join-Path $root 'shots\_upload-open.txt'), $upload, (New-Object Text.UTF8Encoding($false)))

Write-Host ('[+] открыто страниц: ' + $changedRel.Count + ' из ' + $files.Count) -ForegroundColor Green
Write-Host ('[+] robots.txt перезаписан, бэкапы: backups\files\open-site-' + $stamp) -ForegroundColor Green
Write-Host ('[+] список к заливке: shots\_upload-open.txt (' + $upload.Count + ' позиций)') -ForegroundColor Green
