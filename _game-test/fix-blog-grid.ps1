$ErrorActionPreference = 'Stop'
$path = 'C:\Users\krs3d\.cline\data\workspaces\chat\calc_docs\blog\index.html'

# ── читаем, сохраняя BOM ──
$bytes = [IO.File]::ReadAllBytes($path)
$hasBom = $bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF
$raw = [Text.Encoding]::UTF8.GetString($bytes)
if ($hasBom) { $raw = $raw.TrimStart([char]0xFEFF) }

# ── резервная копия ──
$bakDir = 'C:\Users\krs3d\.cline\data\workspaces\chat\calc_docs\_backup\2026-09-28-blog-grid-fix'
New-Item -ItemType Directory -Force -Path $bakDir | Out-Null
Copy-Item $path (Join-Path $bakDir 'index.html') -Force
Write-Output ('backup: ' + (Join-Path $bakDir 'index.html'))

# ── целевой порядок карточек (свежие сверху, по дате) ──
$order = @(
  'skolko-nezamerzayki-nuzhno-dlya-sistemy-otopleniya-raschet-o',   # 28 сентября
  'sokrashchenie-sroka-ili-platezh',                                  # 22 сентября
  'gibridnaya-strategiya-dosrochnogo',
  'dosrochno-ili-na-vklad',
  'kak-oformit-dosrochnoe-pogashenie',
  'kombinirovannoe-dosrochnoe-pogashenie',
  'nalogovy-vychet-i-dosrochnoe',
  'obem-sistemy-otopleniya-raschet',                                  # 21 сентября
  'gidrostrelka-raschet',
  'teploventilyator-vulkan',
  'moshchnost-kotla-raschet',
  'diametr-trub-otopleniya-raschet',
  'rasshiritelnyy-bak-podbor',
  'cirkulyacionnyy-nasos-podbor',
  'avto-rashod-topliva',
  'avto-vladenie',
  'avto-osago-kbm',
  'kak-sostavit-raspisku',                                            # 20 сентября
  'otpusknye',                                                        # 16 сентября
  'nalogovy-vychet-kvartira',
  'neustoyka-alimenty'
)

# ── 1. извлекаем все 21 карточку (целыми строками) ──
$cards = @()
foreach ($slug in $order) {
  $rx = '(?m)^[ \t]*<a class="card" href="/blog/' + [regex]::Escape($slug) + '/".*?</a>[ \t]*(?:\r?\n)?'
  $m = [regex]::Match($raw, $rx)
  if (-not $m.Success) { throw "CARD NOT FOUND: $slug" }
  $cards += ('        ' + $m.Value.Trim())
}

# ── 2. удаляем все 21 карточку из файла ──
foreach ($slug in $order) {
  $rx = '(?m)^[ \t]*<a class="card" href="/blog/' + [regex]::Escape($slug) + '/".*?</a>[ \t]*\r?\n?'
  $raw = [regex]::Replace($raw, $rx, '', 1)
}

# ── 3. удаляем битый комментарий-инструкцию + вытекший текст (до утёкшего <div class="grid">) ──
$raw = [regex]::Replace($raw, '(?s)\s*<!-- Иконка RSS.*?<div class="grid">', '', 1)

# ── 4. удаляем остаток-маркер <!--/EDIT:catalog--> ──
$raw = [regex]::Replace($raw, '(?m)^[ \t]*<!--/EDIT:catalog-->[ \t]*\r?\n?', '', 1)

# ── 5. вставляем 21 карточку сразу после <div class="grid"> ──
$cardBlock = ($cards -join "`n") + "`n"
$gridRx = '(?m)(<div class="grid">[ \t]*\r?\n)'
if (-not [regex]::IsMatch($raw, $gridRx)) { throw 'GRID not found' }
$raw = [regex]::Replace($raw, $gridRx, '${1}' + $cardBlock, 1)

# ── пишем обратно, сохраняя BOM ──
$outBytes = [Text.Encoding]::UTF8.GetBytes($raw)
if ($hasBom) { $outBytes = [byte[]](0xEF, 0xBB, 0xBF) + $outBytes }
[IO.File]::WriteAllBytes($path, $outBytes)

Write-Output ('OK: в сетку пересобрано карточек = ' + $cards.Count)
