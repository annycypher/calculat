# fix2g-banner-compact-v2.ps1 - Fix 2g v2: force one-line cookie-banner at <=360px.
# v1 left flex-wrap:wrap -> banner wrapped to 2 lines (90px) at <=375px (subpixel rounding).
# v2 sets flex-wrap:nowrap + text ellipsis so banner stays one line (~59px) at any width.
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$sl = [char]92
$root = Split-Path -Parent $PSScriptRoot

$pairs = @(
  @('.cookie-banner .container { display: flex; align-items: center; gap: 12px !important; flex-wrap: wrap; justify-content: space-between; }',
    '.cookie-banner .container { display: flex; align-items: center; gap: 12px !important; flex-wrap: nowrap !important; justify-content: space-between; }'),
  @('.cookie-banner .cookie-text { margin: 0px; font-size: 13px; color: var(--text-muted); line-height: 1.4; }',
    '.cookie-banner .cookie-text { margin: 0px; font-size: 13px; color: var(--text-muted); line-height: 1.4; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }'),
  @('.cookie-actions { display: flex; gap: 10px; flex-wrap: wrap; }',
    '.cookie-actions { display: flex; gap: 10px; flex-wrap: nowrap; flex-shrink: 0; }'),
  @('.cookie-actions .btn { padding: 8px 14px !important; font-size: 13px !important; }',
    '.cookie-actions .btn { padding: 8px 14px !important; font-size: 13px !important; white-space: nowrap; flex-shrink: 0; }')
)

$excl = @('_backup','backups','_archive','admin-panel-x7k2','sweb-migration','_game-test','shots','_sys') | ForEach-Object { $_ + [char]47 }
$backupDir = Join-Path $root ('_backup' + $sl + (Get-Date -Format 'yyyy-MM-dd') + '-fix2g-banner-compact-v2')
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
  $bytes = [IO.File]::ReadAllBytes($f.FullName)
  $hasBom = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
  $allPresent = $true
  foreach ($p in $pairs) {
    if (-not $t.Contains($p[0])) { $allPresent = $false; $warn += ('MISSING: ' + $rel + ' :: ' + $p[0].Substring(0, 45)) }
  }
  if (-not $allPresent) { $cSkip++; continue }
  foreach ($p in $pairs) { $t = $t.Replace($p[0], $p[1]) }
  $cChanged++
  $dst = Join-Path $backupDir $rel.Replace([char]47, $sl)
  New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dst) | Out-Null
  Copy-Item -LiteralPath $f.FullName -Destination $dst -Force
  if ($hasBom) { [IO.File]::WriteAllText($f.FullName, $t, $utf8bom) } else { [IO.File]::WriteAllText($f.FullName, $t, $utf8) }
}

Write-Output ('bundle pages: ' + $cFile)
Write-Output ('changed: ' + $cChanged + ' | skipped (missing old rule): ' + $cSkip)
Write-Output ('expectation: changed 96 / skipped 0')
foreach ($w in $warn) { Write-Output ('  WARN: ' + $w) }
Write-Output ('backup: ' + $backupDir)
