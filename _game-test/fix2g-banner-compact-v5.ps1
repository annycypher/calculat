# fix2g-banner-compact-v5.ps1 - Fix 2g v5: add padding:0 + justify-content:flex-start
# to .cookie-banner .container (neutralize generic .container padding 0 20px from styles.css).
# Uses [char]46 for dots to be immune to shell character mangling.
$ErrorActionPreference = 'Stop'
$sl = [char]92
$dot = [char]46
$root = Split-Path -Parent $PSScriptRoot

$old = $dot + 'cookie-banner ' + $dot + 'container { display: flex; align-items: center; gap: 12px !important; flex-wrap: nowrap !important; }'
$new = $dot + 'cookie-banner ' + $dot + 'container { display: flex; align-items: center; gap: 12px !important; flex-wrap: nowrap !important; justify-content: flex-start !important; padding: 0 !important; }'

$excl = @('_backup','backups','_archive','admin-panel-x7k2','sweb-migration','_game-test','shots','_sys') | ForEach-Object { $_ + [char]47 }
$backupDir = Join-Path $root ('_backup' + $sl + (Get-Date -Format 'yyyy-MM-dd') + '-fix2g-banner-compact-v5')
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
$utf8bom = New-Object Text.UTF8Encoding($true)
$utf8    = New-Object Text.UTF8Encoding($false)

$cFile = 0; $cChanged = 0; $cSkip = 0; $warn = @()

$files = @(Get-ChildItem $root -Recurse -Filter *.html -File)
foreach ($f in $files) {
  $rel = $f.FullName.Substring($root.Length + 1).Replace($sl, [char]47)
  $skip = $false
  foreach ($x in $excl) { if ($rel.Contains($x)) { $skip = $true } }
  if ($skip) { continue }
  $t = [IO.File]::ReadAllText($f.FullName, [Text.Encoding]::UTF8)
  if (-not ($t.Contains('/js/ui-bundle.min.js') -or $t.Contains('/js/home-bundle.min.js'))) { continue }
  $cFile++
  if (-not $t.Contains($old)) { $cSkip++; $warn += ('MISSING: ' + $rel); continue }
  $bytes = [IO.File]::ReadAllBytes($f.FullName)
  $hasBom = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
  $t = $t.Replace($old, $new)
  $cChanged++
  $dst = Join-Path $backupDir $rel.Replace([char]47, $sl)
  New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dst) | Out-Null
  Copy-Item -LiteralPath $f.FullName -Destination $dst -Force
  if ($hasBom) { [IO.File]::WriteAllText($f.FullName, $t, $utf8bom) } else { [IO.File]::WriteAllText($f.FullName, $t, $utf8) }
}

Write-Output ('bundle pages: ' + $cFile)
Write-Output ('changed: ' + $cChanged + ' | skipped: ' + $cSkip)
foreach ($w in $warn) { Write-Output ('  WARN: ' + $w) }
Write-Output ('backup: ' + $backupDir)
