# fix2g-banner-compact.ps1 - Fix 2g: compact cookie-banner (one line ~54-72px).
# 1) markup: mass .Replace old static banner -> compact on 94 pages + _template.html (95 total);
# 2) inline critical CSS: compact .cookie-banner rules (padding 10px 14px, font 13px,
#    one-line flex, text without <p> -> <span class="cookie-text">). Height-driving rules get
#    !important so external bundle.css/home.css/styles.css/home-inline.css cannot override them
#    after async load (would otherwise grow the fixed banner -> CLS).
# 3) SW VERSION -> calcdoc-2026-09-27-5 (prod is -4; local is already -5 from deferred 2e).
# Copies of edited files: _backup/<date>-fix2g-banner-compact/. Idempotent.
# JS delegated handler (#cookieAccept -> consentLS, #cookieBanner.remove) untouched.
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$sl = [char]92
$q  = [char]34
$root = Split-Path -Parent $PSScriptRoot

$oldMarkup = '<div class=' + $q + 'cookie-banner' + $q + ' id=' + $q + 'cookieBanner' + $q + ' role=' + $q + 'dialog' + $q + ' aria-label=' + $q + 'Уведомление о файлах cookie' + $q + '><div class=' + $q + 'container' + $q + '><p>Мы используем cookie и обезличенные технологии для работы сайта (тема, настройки) и, при включении, для аналитики и рекламы. Продолжая пользоваться сайтом, вы соглашаетесь с <a href=' + $q + '/privacy/' + $q + ' target=' + $q + '_blank' + $q + ' rel=' + $q + 'noopener' + $q + '>политикой конфиденциальности</a>.</p><div class=' + $q + 'cookie-actions' + $q + '><button type=' + $q + 'button' + $q + ' class=' + $q + 'btn btn-primary' + $q + ' id=' + $q + 'cookieAccept' + $q + '>Принять</button><a class=' + $q + 'btn btn-ghost' + $q + ' href=' + $q + '/privacy/' + $q + '>Подробнее</a></div></div></div>'

$newMarkup = '<div class=' + $q + 'cookie-banner' + $q + ' id=' + $q + 'cookieBanner' + $q + ' role=' + $q + 'dialog' + $q + ' aria-label=' + $q + 'Уведомление о файлах cookie' + $q + '><div class=' + $q + 'container' + $q + '><span class=' + $q + 'cookie-text' + $q + '>Мы используем cookie. <a href=' + $q + '/privacy/' + $q + '>Подробнее</a></span><div class=' + $q + 'cookie-actions' + $q + '><button type=' + $q + 'button' + $q + ' class=' + $q + 'btn btn-primary' + $q + ' id=' + $q + 'cookieAccept' + $q + '>Принять</button></div></div></div>'

# new compact inline CSS (6 rules, joined by file EOL; height drivers carry !important)
$cssRules = @(
  '.cookie-banner { position: fixed; left: 0px; right: 0px; bottom: 0px; z-index: 1000; background: var(--card-bg); color: var(--text); border-top: 1px solid var(--border); box-shadow: rgba(0, 0, 0, 0.08) 0px -6px 24px; padding: 10px 14px !important; }',
  '.cookie-banner .container { display: flex; align-items: center; gap: 12px !important; flex-wrap: wrap; justify-content: space-between; }',
  '.cookie-banner .cookie-text { margin: 0px; font-size: 13px; color: var(--text-muted); line-height: 1.4; }',
  '.cookie-banner a { color: var(--primary); text-decoration: underline; }',
  '.cookie-actions { display: flex; gap: 10px; flex-wrap: wrap; }',
  '.cookie-actions .btn { padding: 8px 14px !important; font-size: 13px !important; }'
)

$cssAnchorStart = '.cookie-banner { position: fixed'
$cssAnchorEnd   = '.cookie-actions .btn'
$cssNewMarker   = 'padding: 10px 14px !important'

$excl = @('_backup','backups','_archive','admin-panel-x7k2','sweb-migration','_game-test','shots','_sys') | ForEach-Object { $_ + [char]47 }
$backupDir = Join-Path $root ('_backup' + $sl + (Get-Date -Format 'yyyy-MM-dd') + '-fix2g-banner-compact')
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
$utf8bom = New-Object Text.UTF8Encoding($true)
$utf8    = New-Object Text.UTF8Encoding($false)
$crlf = [char]13 + [char]10
$lf = [char]10

$cBundle=0; $cMarkup=0; $cCss=0; $cAlreadyMk=0; $cAlreadyCss=0; $cNoMarkup=0; $cNoCss=0; $cBad=0; $warn=@()

$files = @(Get-ChildItem $root -Recurse -Filter *.html -File)
foreach ($f in $files) {
  $rel = $f.FullName.Substring($root.Length + 1).Replace($sl, [char]47)
  $skip = $false
  foreach ($x in $excl) { if ($rel.Contains($x)) { $skip = $true } }
  if ($skip) { continue }
  $t = [IO.File]::ReadAllText($f.FullName, [Text.Encoding]::UTF8)
  if (-not ($t.Contains('/js/ui-bundle.min.js') -or $t.Contains('/js/home-bundle.min.js'))) { continue }
  $cBundle++

  $bytes = [IO.File]::ReadAllBytes($f.FullName)
  $hasBom = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
  $eol = if ($t.Contains($crlf)) { $crlf } else { $lf }
  $changed = $false

  # --- markup ---
  if ($t.Contains($newMarkup)) { $cAlreadyMk++ }
  elseif ($t.Contains($oldMarkup)) { $t = $t.Replace($oldMarkup, $newMarkup); $cMarkup++; $changed = $true }
  else { $cNoMarkup++ }   # privacy page (banner hidden) has CSS but no markup

  # --- inline CSS ---
  if ($t.Contains($cssNewMarker)) { $cAlreadyCss++ }
  else {
    $s = $t.IndexOf($cssAnchorStart)
    if ($s -lt 0) { $cNoCss++; $warn += ('NO CSS anchor start: ' + $rel) }
    else {
      $b = $t.IndexOf($cssAnchorEnd, $s)
      $e = if ($b -ge 0) { $t.IndexOf('}', $b) } else { -1 }
      if ($b -lt 0 -or $e -lt 0) { $cBad++; $warn += ('BAD CSS bounds: ' + $rel) }
      else {
        $e = $e + 1
        $old = $t.Substring($s, $e - $s)
        if (-not $old.Contains('padding: 14px 20px')) { $cBad++; $warn += ('UNEXPECTED old CSS (no 14px 20px): ' + $rel) }
        else {
          $new = $cssRules -join $eol
          $t = $t.Substring(0, $s) + $new + $t.Substring($e)
          $cCss++; $changed = $true
        }
      }
    }
  }

  if (-not $changed) { continue }
  $dst = Join-Path $backupDir $rel.Replace([char]47, $sl)
  New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dst) | Out-Null
  Copy-Item -LiteralPath $f.FullName -Destination $dst -Force
  if ($hasBom) { [IO.File]::WriteAllText($f.FullName, $t, $utf8bom) } else { [IO.File]::WriteAllText($f.FullName, $t, $utf8) }
}

# SW VERSION -> calcdoc-2026-09-27-5
$swPath = Join-Path $root 'service-worker.js'
$sw = [IO.File]::ReadAllText($swPath, [Text.Encoding]::UTF8)
$cur = [regex]::Match($sw, 'calcdoc-[0-9-]+').Value
if ($cur -eq 'calcdoc-2026-09-27-4') {
  Copy-Item -LiteralPath $swPath -Destination (Join-Path $backupDir 'service-worker.js') -Force
  $sw2 = $sw.Replace('calcdoc-2026-09-27-4', 'calcdoc-2026-09-27-5')
  [IO.File]::WriteAllText($swPath, $sw2, (New-Object Text.UTF8Encoding($false)))
  Write-Output 'SW VERSION: calcdoc-2026-09-27-4 -> calcdoc-2026-09-27-5'
} elseif ($cur -eq 'calcdoc-2026-09-27-5') {
  Write-Output ('SW VERSION (уже): ' + $cur)
} else {
  Write-Output ('SW VERSION (неожиданный): ' + $cur)
}

Write-Output ('bundle pages: ' + $cBundle)
Write-Output ('markup replaced: ' + $cMarkup + ' | already compact: ' + $cAlreadyMk + ' | no markup (privacy): ' + $cNoMarkup)
Write-Output ('CSS replaced: ' + $cCss + ' | already compact: ' + $cAlreadyCss + ' | no CSS: ' + $cNoCss + ' | bad: ' + $cBad)
Write-Output ('expectation: markup 95 / CSS 96')
foreach ($w in $warn) { Write-Output ('  WARN: ' + $w) }
Write-Output ('backup: ' + $backupDir)
