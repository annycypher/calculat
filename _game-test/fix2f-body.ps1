# fix2f-body.ps1 — вставить открывающий <body> сразу после </head>
# на 10 страницах, где его нет (7 blog/* + 3 calculators/engineering/*).
# Идемпотентно: файл, где <body> уже есть, пропускается.
# Бэкап — в _backup\2026-09-27-fix2f-body\ (относительные пути как на сайте).
param([switch]$DryRun)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

$files = @(
  'blog\cirkulyacionnyy-nasos-podbor\index.html',
  'blog\diametr-trub-otopleniya-raschet\index.html',
  'blog\gidrostrelka-raschet\index.html',
  'blog\moshchnost-kotla-raschet\index.html',
  'blog\obem-sistemy-otopleniya-raschet\index.html',
  'blog\rasshiritelnyy-bak-podbor\index.html',
  'blog\teploventilyator-vulkan\index.html',
  'calculators\engineering\gidrostrelka\index.html',
  'calculators\engineering\otoplenie-obem\index.html',
  'calculators\engineering\vulkan\index.html'
)

$stamp = '2026-09-27-fix2f-body'
$bkRoot = Join-Path $root ("_backup\" + $stamp)
$utf8 = New-Object System.Text.UTF8Encoding($false)

$todo = @()
foreach ($f in $files) {
  $p = Join-Path $root $f
  if (-not (Test-Path $p)) { throw "нет файла: $f" }
  $t = [IO.File]::ReadAllText($p)
  if ($t -match '(?i)<body\b') { Write-Host ("  skip (уже есть <body>): " + $f) -ForegroundColor DarkGray; continue }
  $todo += $f
}

if ($todo.Count -eq 0) { Write-Host 'Нечего делать: все файлы уже имеют <body>.'; return }

Write-Host ("К правке: {0} файл(ов)" -f $todo.Count)

# 1. Бэкап
foreach ($f in $todo) {
  $src = Join-Path $root $f
  $dst = Join-Path $bkRoot $f
  $dir = Split-Path -Parent $dst
  if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
  Copy-Item $src $dst -Force
}
Write-Host ("Бэкап: " + $bkRoot)

if ($DryRun) { Write-Host 'DryRun: бэкап сделан, правки НЕ внесены.' -ForegroundColor Yellow; return }

# 2. Правка: вставить <body> сразу после </head>
$done = 0
foreach ($f in $todo) {
  $p = Join-Path $root $f
  $t = [IO.File]::ReadAllText($p)
  $idx = $t.IndexOf('</head>', [StringComparison]::OrdinalIgnoreCase)
  if ($idx -lt 0) { throw ("нет </head>: " + $f) }
  $t = $t.Substring(0, $idx) + "</head>`r`n<body>" + $t.Substring($idx + 7)
  [IO.File]::WriteAllText($p, $t, $utf8)
  $done++
  Write-Host ("  + " + $f)
}
Write-Host ("Готово: <body> внесён в {0} файл(ов)." -f $done)
