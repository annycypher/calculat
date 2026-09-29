# fix2-minify-perpage.ps1 - minifitsirovat vse per-page skripty (calc-*/convert-*/gen-*/game-*/...),
# zamenit ssylki v HTML na .min.js s bump ?v. Ispravlyaet tolko podklyucheniya, ne libs/reviews/ui.
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$root = Split-Path -Parent $PSScriptRoot
$es = Join-Path $root '_game-test\tools\esbuild.exe'
$jsDir = Join-Path $root 'js'
$excl = '(_backup|backups|_archive|admin-panel-x7k2|sweb-migration|_game-test|\.git|shots|_sys)'
$files = @(Get-ChildItem $root -Recurse -Filter *.html -File | Where-Object { $_.FullName -notmatch $excl })

# 1. sobrat unikalnye per-page ssylki
$refs = @{}
foreach ($f in $files) {
  $t = [IO.File]::ReadAllText($f.FullName)
  foreach ($m in [regex]::Matches($t, 'src="/js/([^"]+)"')) {
    $src = $m.Groups[1].Value
    if ($src -match '^(ui-bundle\.min\.js|home-bundle\.min\.js|reviews\.js|ui\.js)') { continue }
    if ($src -notmatch '\.js\?v=\d+$') { continue }
    $refs[$src] = $true
  }
}
$names = @($refs.Keys) | Sort-Object
Write-Output ('per-page scripts: ' + $names.Count)

# 2. karta zameny src -> new
$map = @{}
foreach ($src in $names) {
  $base = $src -replace '\?v=\d+$', ''
  $ver = [int]([regex]::Match($src, 'v=(\d+)').Groups[1].Value)
  $map[$src] = ($base -replace '\.js$', '.min.js') + '?v=' + ($ver + 1)
}

# 3. minifikatsiya
$report = @()
$uniq = @($names | ForEach-Object { $_ -replace '\?v=\d+$', '' } | Sort-Object -Unique)
foreach ($base in $uniq) {
  $inp = Join-Path $jsDir $base
  $out = Join-Path $jsDir ($base -replace '\.js$', '.min.js')
  if (-not (Test-Path $inp)) { Write-Output ('NO FILE: ' + $base); continue }
  & $es $inp '--minify' '--charset=utf8' '--legal-comments=none' ('--outfile=' + $out) | Out-Null
  $a = (Get-Item $inp).Length; $b = (Get-Item $out).Length
  $report += ('{0}: {1} -> {2} KB' -f $base, [Math]::Round($a/1KB,1), [Math]::Round($b/1KB,1))
}

# 4. zamena v HTML
$bom = New-Object Text.UTF8Encoding($true)
$nobom = New-Object Text.UTF8Encoding($false)
$changed = 0
$totalRepl = 0
foreach ($f in $files) {
  $b = [IO.File]::ReadAllBytes($f.FullName)
  $hasBom = ($b.Length -ge 3 -and $b[0] -eq 0xEF -and $b[1] -eq 0xBB -and $b[2] -eq 0xBF)
  $off = if ($hasBom) { 3 } else { 0 }
  $t = [Text.Encoding]::UTF8.GetString($b, $off, $b.Length - $off)
  $orig = $t
  foreach ($src in $names) {
    $t = $t.Replace('/js/' + $src, '/js/' + $map[$src])
  }
  if ($t -ne $orig) {
    [IO.File]::WriteAllText($f.FullName, $t, $(if ($hasBom) { $bom } else { $nobom }))
    $changed++
    $totalRepl += [regex]::Matches($orig, '/js/[^"]*\.js\?v=\d+').Count
  }
}
Write-Output ('HTML files changed: ' + $changed)
Write-Output '--- weights ---'
$report | ForEach-Object { Write-Output ('  ' + $_) }
