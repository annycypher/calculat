# fix2c-banner-static.ps1 - Fix 2c, chast A: cookie-banner iz JS perenositza v staticheskuyu razmetku.
# 1) head-glushitel (inline script v nachale <head>) - na vseh 96 stranitsah s bandom;
# 2) CSS-pravilo html.calcdoc-consent .cookie-banner{display:none!important} - otdelnoi strokoi
#    pered suschestvuyuschim pravilom '.cookie-banner {' (tolko tam, gde eto pravilo est);
# 3) staticheskaya razmetka bankera pered </body>, KROME generators/_template.html i KROME
#    stranits politiki (ui.js: onPrivacy = pathname.includes('/privacy/')).
# Razmetka i aria-label izvlekayutsya iz js/ui.js - identichnost garantirovana.
# Kopii pravimyh failov: _backup/<data>-fix2c-banner/<put>. Idempotentno (povtornyi zapusk bezopasen).
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$sl = [char]92
$q  = [char]34
$q1 = [char]39
$root = Split-Path -Parent $PSScriptRoot

$ui = [IO.File]::ReadAllText((Join-Path $root ('js' + $sl + 'ui.js')), [Text.Encoding]::UTF8)

function Dec([string]$s) {
  $s = [regex]::Replace($s, $sl + $sl + 'u' + $sl + '{([0-9a-fA-F]+)' + $sl + '}', { param($m) [char]::ConvertFromUtf32([Convert]::ToInt32($m.Groups[1].Value, 16)) })
  return [regex]::Unescape($s)
}

$i0 = $ui.IndexOf('b.innerHTML=' + $q1)
$i1 = $ui.IndexOf($q1 + ',document.body.appendChild(b)', $i0)
if ($i0 -lt 0 -or $i1 -lt 0) { throw 'v ui.js ne naydena razmetka bankera' }
$inner = Dec $ui.Substring($i0 + 13, $i1 - ($i0 + 13))

$p = 'setAttribute(' + $q + 'aria-label' + $q + ',' + $q
$k = $ui.IndexOf($p, [Math]::Max(0, $i0 - 400))
$ve = $ui.IndexOf($q, $k + $p.Length)
if ($k -lt 0 -or $ve -lt 0) { throw 'v ui.js ne nayden aria-label bankera' }
$label = Dec $ui.Substring($k + $p.Length, $ve - ($k + $p.Length))
if ($label.Length -eq 0) { throw 'aria-label pustoi' }
if ($inner.IndexOf('id=' + $q + 'cookieAccept' + $q) -lt 0) { throw 'v razmetke bankera net #cookieAccept' }

$banner = '<div class=' + $q + 'cookie-banner' + $q + ' id=' + $q + 'cookieBanner' + $q + ' role=' + $q + 'dialog' + $q + ' aria-label=' + $q + $label + $q + '>' + $inner + '</div>'
$silencer = '<script>try{if(localStorage.getItem(' + $q + 'calcdoc-consent' + $q + '))document.documentElement.classList.add(' + $q + 'calcdoc-consent' + $q + ')}catch(e){}</script>'
$cssRule = 'html.calcdoc-consent .cookie-banner{display:none!important}'
$silMark = 'classList.add(' + $q + 'calcdoc-consent' + $q + ')'
$crlf = [char]13 + [char]10
$lf = [char]10

$excl = @('_backup','backups','_archive','admin-panel-x7k2','sweb-migration','_game-test','shots','_sys') | ForEach-Object { $_ + [char]47 }

$files = @(Get-ChildItem $root -Recurse -Filter *.html -File)
$backupDir = Join-Path $root ('_backup' + $sl + (Get-Date -Format 'yyyy-MM-dd') + '-fix2c-banner')
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
$utf8bom = New-Object Text.UTF8Encoding($true)
$utf8 = New-Object Text.UTF8Encoding($false)
$cHead=0; $cCss=0; $cBanner=0; $cNoHead=0; $cNoCss=0; $cNoBody=0; $cAlready=0; $cSkipPrivacy=0; $cSkipTemplate=0; $cBundle=0

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
  $orig = $t

  if (-not $t.Contains($silMark)) {
    $h = $t.IndexOf('<head>')
    if ($h -lt 0) { $cNoHead++ } else {
      $t = $t.Substring(0, $h + 6) + $eol + '  ' + $silencer + $t.Substring($h + 6)
      $cHead++
    }
  } else { $cAlready++ }

  if (-not $t.Contains($cssRule)) {
    $k = $t.IndexOf('.cookie-banner {')
    if ($k -lt 0) { $cNoCss++ } else {
      $ls = $t.LastIndexOf($lf, $k) + 1
      $ind = $t.Substring($ls, $k - $ls)
      if ($ind.Trim().Length -gt 0) { Write-Output ('VNIMANIE: .cookie-banner { ne v nachale stroki: ' + $rel) }
      $t = $t.Substring(0, $ls) + $ind + $cssRule + $eol + $t.Substring($ls)
      $cCss++
    }
  }

  if ($rel.StartsWith('privacy/') -or $rel.Contains('/privacy/')) { $cSkipPrivacy++ }
  elseif ($rel -eq 'generators/_template.html') { $cSkipTemplate++ }
  elseif ($t.Contains('id=' + $q + 'cookieBanner' + $q)) { }
  else {
    $b = $t.IndexOf('</body>')
    if ($b -lt 0) { $cNoBody++ } else {
      $t = $t.Substring(0, $b) + '  ' + $banner + $eol + $t.Substring($b)
      $cBanner++
    }
  }

  if ($t -eq $orig) { continue }
  $dst = Join-Path $backupDir $rel.Replace([char]47, $sl)
  New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dst) | Out-Null
  Copy-Item -LiteralPath $f.FullName -Destination $dst -Force
  if ($hasBom) { [IO.File]::WriteAllText($f.FullName, $t, $utf8bom) } else { [IO.File]::WriteAllText($f.FullName, $t, $utf8) }
}
Write-Output ('HTML stranits s bandom: ' + $cBundle)
Write-Output ('head: ' + $cHead + ' | CSS: ' + $cCss + ' | banner: ' + $cBanner)
Write-Output ('propushcheno: privacy ' + $cSkipPrivacy + ', shablon ' + $cSkipTemplate + ' | bez <head>: ' + $cNoHead + ' | bez .cookie-banner {: ' + $cNoCss + ' | bez </body>: ' + $cNoBody + ' | glushitel uzhe byl: ' + $cAlready)
Write-Output ('ozhidanie: head 96 / CSS 95 / banner 94')
Write-Output ('backup: ' + $backupDir)
