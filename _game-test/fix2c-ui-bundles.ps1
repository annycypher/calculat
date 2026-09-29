# fix2c-ui-bundles.ps1 - Fix 2c, chast B: ui.js bez sozdaniya bankera + peresborka + bump.
# 1) ui.js: udalyaem sozdanie bankera (SHOW_CONSENT_BANNER/onPrivacy/consented/innerHTML),
#    vmesto etogo - delegirovanie klika na document po closest('#cookieAccept').
# 2) Peresborka js/ui-bundle.js (build-ui-bundle.ps1) i js/home-bundle.js (build-home-bundle.ps1).
# 3) Minifikatsiya esbuild: --minify --charset=utf8 --legal-comments=none.
# 4) bump ?v v HTML: ui 54->55, home 55->56.
# 5) service-worker.js: VERSION bump (SHELL ne soderzhit ?v=).
# Kopii: _backup/<data>-fix2c-banner/. Idempotentno.
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$sl = [char]92
$q  = [char]34
$root  = Split-Path -Parent $PSScriptRoot
$jsDir = Join-Path $root 'js'
$es    = Join-Path $root ('_game-test' + $sl + 'tools' + $sl + 'esbuild.exe')
if (-not (Test-Path $es)) { throw ('net esbuild: ' + $es) }

function Read-Text([string]$p) { [IO.File]::ReadAllText($p, [Text.Encoding]::UTF8) }
function Write-Text([string]$p, [string]$t) { [IO.File]::WriteAllText($p, $t, (New-Object Text.UTF8Encoding($false))) }
function Kb([string]$p) { [Math]::Round((Get-Item $p).Length / 1KB, 1) }

$backupDir = Join-Path $root ('_backup' + $sl + (Get-Date -Format 'yyyy-MM-dd') + '-fix2c-banner')
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null

# 1. ui.js
$uiPath = Join-Path $jsDir 'ui.js'
$s = Read-Text $uiPath
$sm = 'const SHOW_CONSENT_BANNER=!0;if(SHOW_CONSENT_BANNER){'
$em = 'b.remove()})}}'
$i = $s.IndexOf($sm)
$j = $s.IndexOf($em, $i)
if ($i -lt 0 -or $j -lt 0) { throw 'blok consent-bankera v ui.js ne nayden' }
$e = $j + $em.Length
$oldBlock = $s.Substring($i, $e - $i)
foreach ($need in @('onPrivacy','calcdoc-consent','cookieAccept','cookie-banner','cookieBanner')) {
  if (-not $oldBlock.Contains($need)) { throw ('v udalyaemom bloke net: ' + $need) }
}
$nb = 'document.addEventListener(' + $q + 'click' + $q + ',e=>{const t=e.target;if(!t||!t.closest(' + $q + '#cookieAccept' + $q + '))return;try{localStorage.setItem(' + $q + 'calcdoc-consent' + $q + ',' + $q + '1' + $q + ')}catch(e){}const b=document.getElementById(' + $q + 'cookieBanner' + $q + ');b&&b.remove()});'
$newUi = $s.Substring(0, $i) + $nb + $s.Substring($e)
foreach ($gone in @('SHOW_CONSENT_BANNER','onPrivacy','consented','cookie-banner')) {
  if ($newUi.Contains($gone)) { throw ('posle pravki v ui.js ostalos: ' + $gone) }
}
if (([regex]::Matches($newUi, [regex]::Escape('calcdoc-consent'))).Count -ne 1) { throw 'ozhidalas 1 calcdoc-consent' }
if (([regex]::Matches($newUi, [regex]::Escape('closest(' + $q + '#cookieAccept' + $q + ')'))).Count -ne 1) { throw 'ozhidalos 1 closest(#cookieAccept)' }
Copy-Item -LiteralPath $uiPath -Destination (Join-Path $backupDir 'js-ui.js.bak') -Force
Write-Text $uiPath $newUi
Write-Host ('ui.js: ' + $s.Length + ' -> ' + $newUi.Length + ' simvolov (minus ' + ($s.Length - $newUi.Length) + ')')

# 2. peresborka
& (Join-Path $PSScriptRoot 'build-ui-bundle.ps1')
& (Join-Path $PSScriptRoot 'build-home-bundle.ps1')

# 3. minifikatsiya
foreach ($n in @('ui-bundle','home-bundle')) {
  $inp = Join-Path $jsDir ($n + '.js')
  $outp = Join-Path $jsDir ($n + '.min.js')
  $before = Kb $outp
  Copy-Item -LiteralPath $inp  -Destination (Join-Path $backupDir ('js-' + $n + '.js.bak')) -Force
  Copy-Item -LiteralPath $outp -Destination (Join-Path $backupDir ('js-' + $n + '.min.js.bak')) -Force
  & $es $inp '--minify' '--charset=utf8' '--legal-comments=none' ('--outfile=' + $outp) | Out-Null
  $after = Kb $outp
  Write-Host ('  ' + $n + ': ' + (Kb $inp) + ' KB -> .min.js ' + $before + ' -> ' + $after + ' KB (delta ' + [Math]::Round($after - $before, 1) + ' KB)')
}
$m = Read-Text (Join-Path $jsDir 'ui-bundle.min.js')
Write-Host ('  kontrol ui-bundle.min.js: cookie-banner=' + ([regex]::Matches($m, [regex]::Escape('cookie-banner'))).Count + ' (zhdyom 0), calcdoc-consent=' + ([regex]::Matches($m, [regex]::Escape('calcdoc-consent'))).Count + ' (zhdyom 1)')

# 4. bump ?v
$uiOld  = 'ui-bundle.min.js?v=54';  $uiNew  = 'ui-bundle.min.js?v=55'
$homeOld = 'home-bundle.min.js?v=55'; $homeNew = 'home-bundle.min.js?v=56'
$bumpDir = Join-Path $backupDir 'bump'
$excl = @('_backup','backups','_archive','admin-panel-x7k2','sweb-migration','_game-test','shots','_sys') | ForEach-Object { $_ + [char]47 }
$files = @(Get-ChildItem $root -Recurse -Filter *.html -File)
$utf8 = New-Object Text.UTF8Encoding($false)
$utf8bom = New-Object Text.UTF8Encoding($true)
$cFiles = 0; $cRepl = 0
foreach ($f in $files) {
  $rel = $f.FullName.Substring($root.Length + 1).Replace($sl, [char]47)
  $skip = $false
  foreach ($x in $excl) { if ($rel.Contains($x)) { $skip = $true } }
  if ($skip) { continue }
  $b = [IO.File]::ReadAllBytes($f.FullName)
  $hasBom = ($b.Length -ge 3 -and $b[0] -eq 0xEF -and $b[1] -eq 0xBB -and $b[2] -eq 0xBF)
  $off = if ($hasBom) { 3 } else { 0 }
  $t = [Text.Encoding]::UTF8.GetString($b, $off, $b.Length - $off)
  $o = $t
  if ($t.Contains($uiOld)) { $cRepl += ([regex]::Matches($t, [regex]::Escape($uiOld))).Count; $t = $t.Replace($uiOld, $uiNew) }
  if ($t.Contains($homeOld)) { $cRepl += ([regex]::Matches($t, [regex]::Escape($homeOld))).Count; $t = $t.Replace($homeOld, $homeNew) }
  if ($t -ne $o) {
    $dst = Join-Path $bumpDir $rel.Replace([char]47, $sl)
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dst) | Out-Null
    Copy-Item -LiteralPath $f.FullName -Destination $dst -Force
    if ($hasBom) { [IO.File]::WriteAllText($f.FullName, $t, $utf8bom) } else { [IO.File]::WriteAllText($f.FullName, $t, $utf8) }
    $cFiles++
  }
}
Write-Host ('bump ?v: failov ' + $cFiles + ', zamen ' + $cRepl + ' (ozhidanie: 96 failov, 96 zamen)')

# 5. service-worker.js
$swPath = Join-Path $root 'service-worker.js'
$sw = Read-Text $swPath
$a = $sw.IndexOf('const SHELL = [')
$b = $sw.IndexOf('];', $a)
if ($a -lt 0 -or $b -lt 0) { throw 'v service-worker.js ne nayden SHELL' }
$shellV = ([regex]::Matches($sw.Substring($a, $b - $a), [regex]::Escape('?v='))).Count
Write-Host ('SHELL: ssylok s ?v= - ' + $shellV + ' (ozhidanie 0)')
if ($shellV -gt 0) { throw 'v SHELL est ?v= - nuzhno pravit i ih' }
$oldV = 'calcdoc-2026-09-27-2'; $newV = 'calcdoc-2026-09-27-3'
if (-not $sw.Contains($oldV)) { throw ('v service-worker.js net ' + $oldV) }
Copy-Item -LiteralPath $swPath -Destination (Join-Path $backupDir 'service-worker.js.bak') -Force
Write-Text $swPath $sw.Replace($oldV, $newV)
Write-Host ('service-worker.js: VERSION ' + $oldV + ' -> ' + $newV)
