# fix2d-banner-pos.ps1 - Fix 2d: staticheskiy cookie-banner perenositsya iz 'pered </body>'
# v 'srazu posle otkryvayushchego <body>'. Pravka tolko pozitsii v HTML razmetke
# (vizualno identichno - banner position:fixed bottom:0). SW VERSION -> calcdoc-2026-09-27-4.
# Bundly i ?v= NE trogayutsya. Kopii pravimyh failov: _backup/<data>-fix2d-banner-pos/<put>.
# Strukturnyi metod (ne zavisit ot CRLF/LF/smeshannyh okonchaniy/minifikacii). Idempotentno.
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$sl = [char]92
$q  = [char]34
$q1 = [char]39
$root = Split-Path -Parent $PSScriptRoot

$excl = @('_backup','backups','_archive','admin-panel-x7k2','sweb-migration','_game-test','shots','_sys') | ForEach-Object { $_ + [char]47 }

$backupDir = Join-Path $root ('_backup' + $sl + (Get-Date -Format 'yyyy-MM-dd') + '-fix2d-banner-pos')
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
$utf8bom = New-Object Text.UTF8Encoding($true)
$utf8    = New-Object Text.UTF8Encoding($false)

$anchor = 'id=' + $q + 'cookieBanner' + $q

$files = @(Get-ChildItem $root -Recurse -Filter *.html -File)
$cMoved = 0; $cAlready = 0; $cNoBanner = 0; $cNoBody = 0; $cNoBodyTag = 0; $cPrivacy = 0; $cNoBundle = 0; $cBad = 0

foreach ($f in $files) {
  $rel = $f.FullName.Substring($root.Length + 1).Replace($sl, [char]47)
  $skip = $false
  foreach ($x in $excl) { if ($rel.Contains($x)) { $skip = $true } }
  if ($skip) { continue }

  $t = [IO.File]::ReadAllText($f.FullName, [Text.Encoding]::UTF8)
  if (-not ($t.Contains('/js/ui-bundle.min.js') -or $t.Contains('/js/home-bundle.min.js'))) { $cNoBundle++; continue }
  if ($rel.StartsWith('privacy/') -or $rel.Contains('/privacy/')) { $cPrivacy++; continue }

  $a = $t.IndexOf($anchor)
  if ($a -lt 0) { $cNoBanner++; continue }
  $bo0 = $t.IndexOf('<body')
  $he0 = $t.IndexOf('</head>')
  $ref0 = if ($bo0 -ge 0) { $bo0 } else { $he0 }
  if ($ref0 -lt 0) { $cNoBody++; continue }
  if (($a - $ref0) -gt 0 -and ($a - $ref0) -lt 300) { $cAlready++; continue }   # uje perenesyon

  # granicy bankera: ot poslednego '<div' pered yakorem do '</body>' posle yakorya
  $bs = $t.LastIndexOf('<div', $a)
  $bc = $t.IndexOf('</body>', $a)
  if ($bs -lt 0 -or $bc -lt 0) { $cBad++; Write-Output ('VNIMANIE: granicy ne naydeny: ' + $rel); continue }

  $banner = $t.Substring($bs, $bc - $bs).Trim()
  if (-not ($banner.StartsWith('<div') -and $banner.EndsWith('</div>'))) {
    $cBad++; Write-Output ('VNIMANIE: blok bankera ne korrekten: ' + $rel); continue
  }
  if ($banner.Length -gt 3000) { $cBad++; Write-Output ('VNIMANIE: blok bankera slishkom bolshoy: ' + $rel); continue }

  # 1) vyrezat bankera pered </body>
  $t = $t.Remove($bs, $bc - $bs)

  # 2) vstavit v nachalo body (posle <body ...> ili, esli tega <body> net, posle </head>)
  $bo = $t.IndexOf('<body')
  $he = $t.IndexOf('</head>')
  if ($bo -ge 0) {
    $gt = $t.IndexOf('>', $bo)
    if ($gt -lt 0) { $cNoBody++; continue }
    $ins = $gt + 1
  } elseif ($he -ge 0) {
    $ins = $he + 7
    $cNoBodyTag++
  } else {
    $cNoBody++; continue
  }
  $nl = [char]10
  if ($ins -lt $t.Length -and $t[$ins] -eq [char]13) { $nl = [char]13 + [char]10 }
  if ($ins -lt $t.Length -and $t[$ins] -eq [char]13) { $ins++ }
  if ($ins -lt $t.Length -and $t[$ins] -eq [char]10) { $ins++ }
  $t = $t.Insert($ins, $banner + $nl)

  # kontrol: distanciya ot nachala body do bankera dolzhna byt < 300 simvolov
  $ref2 = if ($t.IndexOf('<body') -ge 0) { $t.IndexOf('<body') } else { $t.IndexOf('</head>') }
  $dist = $t.IndexOf($anchor) - $ref2
  if ($dist -le 0 -or $dist -gt 300) { Write-Output ('VNIMANIE: dist = ' + $dist + ' : ' + $rel) }

  $dst = Join-Path $backupDir $rel.Replace([char]47, $sl)
  New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dst) | Out-Null
  Copy-Item -LiteralPath $f.FullName -Destination $dst -Force

  $bytes = [IO.File]::ReadAllBytes($f.FullName)
  $hasBom = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
  if ($hasBom) { [IO.File]::WriteAllText($f.FullName, $t, $utf8bom) } else { [IO.File]::WriteAllText($f.FullName, $t, $utf8) }
  $cMoved++
}

# SW VERSION -> calcdoc-2026-09-27-4
$swPath = Join-Path $root 'service-worker.js'
$sw = [IO.File]::ReadAllText($swPath, [Text.Encoding]::UTF8)
$cur = [regex]::Match($sw, 'calcdoc-[0-9-]+').Value
if ($cur -eq 'calcdoc-2026-09-27-3') {
  Copy-Item -LiteralPath $swPath -Destination (Join-Path $backupDir 'service-worker.js') -Force
  $sw2 = $sw.Replace('calcdoc-2026-09-27-3', 'calcdoc-2026-09-27-4')
  [IO.File]::WriteAllText($swPath, $sw2, (New-Object Text.UTF8Encoding($false)))
  Write-Output 'SW VERSION: calcdoc-2026-09-27-3 -> calcdoc-2026-09-27-4'
} else {
  Write-Output ('SW VERSION (уже): ' + $cur)
}

Write-Output ('pereneseno: ' + $cMoved + ' | uje perenesyon: ' + $cAlready + ' | bez bankera: ' + $cNoBanner + ' | net <body> i net </head>: ' + $cNoBody + ' | bez tega <body> (vstavleno posle </head>): ' + $cNoBodyTag + ' | privacy: ' + $cPrivacy + ' | bez bundla: ' + $cNoBundle + ' | bad: ' + $cBad)
Write-Output ('backup: ' + $backupDir)
