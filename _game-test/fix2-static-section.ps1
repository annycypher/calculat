# fix2-static-section.ps1 - vstavit staticheskuyu sektsiyu «Drugie instrumenty» pered futerom
# vo vse 96 stranits (per-page minus svoy chip = ispravlen bug samovklyucheniya).
# Chistyi ASCII (kirillitsa/emoji iz kodov i dannyh ui.js) - chtoby PS 5.1 ne iskazil.
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$root = Split-Path -Parent $PSScriptRoot

# --- zagolovok "Drugie instrumenty" iz kodov simvolov ---
$title = -join ([char[]](0x0414,0x0440,0x0443,0x0433,0x0438,0x0435,0x20,0x0438,0x043D,0x0441,0x0442,0x0440,0x0443,0x043C,0x0435,0x043D,0x0442,0x044B))

# --- razbor TOOLS iz ui.js ---
$ui = [IO.File]::ReadAllText((Join-Path $root 'js\ui.js'))
$i = $ui.IndexOf('const TOOLS=')
$j = $ui.IndexOf('];', $i)
$lit = $ui.Substring($i + 12, $j + 1 - ($i + 12))

function Dec([string]$s) {
  $s = [regex]::Replace($s, '\\u\{([0-9a-fA-F]+)\}', { param($m) [char]::ConvertFromUtf32([Convert]::ToInt32($m.Groups[1].Value, 16)) })
  $s = [regex]::Replace($s, '\\u([0-9a-fA-F]{4})', { param($m) [string][char][Convert]::ToInt32($m.Groups[1].Value, 16) })
  return $s
}

$tools = @()
foreach ($m in [regex]::Matches($lit, '\["([^"]*)","([^"]*)","([^"]*)"\]')) {
  $tools += ,@((Dec $m.Groups[1].Value), (Dec $m.Groups[2].Value), $m.Groups[3].Value)
}
Write-Output ('TOOLS parsed: ' + $tools.Count)

function BuildChips($list) {
  $out = ''
  foreach ($t in $list) {
    $out += ('<a class="chip" href="{0}"><span>{1}</span>{2}</a>' -f $t[2], $t[0], $t[1])
  }
  return $out
}

$excl = '(_backup|backups|_archive|admin-panel-x7k2|sweb-migration|_game-test|\.git|shots|_sys)'
$files = @(Get-ChildItem $root -Recurse -Filter *.html -File | Where-Object {
  $_.FullName -notmatch $excl -and (Select-String -Path $_.FullName -Pattern 'ui-bundle\.min\.js|home-bundle\.min\.js' -Quiet)
})
Write-Output ('HTML pages: ' + $files.Count)

$utf8bom = New-Object Text.UTF8Encoding($true)
$utf8 = New-Object Text.UTF8Encoding($false)
$done = 0
$rx = [regex]'(?m)^([ \t]*)<footer'

foreach ($f in $files) {
  $rel = $f.FullName.Substring($root.Length + 1).Replace('\', '/')
  if ($rel -eq 'index.html') { $pageUrl = '/' }
  elseif ($rel -eq 'generators/_template.html') { $pageUrl = '' }
  else { $pageUrl = '/' + ($rel -replace '/index\.html$', '') + '/' }

  $list = @()
  foreach ($t in $tools) {
    if ($pageUrl -ne '' -and $t[2] -eq $pageUrl) { continue }
    $list += ,@($t[0], $t[1], $t[2])
  }
  $section = ('<section class="container section" aria-label="{0}"><h2 class="section-title">{0}</h2><div class="chips">{1}</div></section>' -f $title, (BuildChips $list))

  $bytes = [IO.File]::ReadAllBytes($f.FullName)
  $hasBom = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
  $off = if ($hasBom) { 3 } else { 0 }
  $text = [Text.Encoding]::UTF8.GetString($bytes, $off, $bytes.Length - $off)

  $m2 = $rx.Match($text)
  if (-not $m2.Success) { Write-Output ('NO <footer>: ' + $rel); continue }
  $ind = $m2.Groups[1].Value
  $replacement = $ind + $section + "`r`n" + $ind + '<footer'
  $new = $rx.Replace($text, $replacement, 1)
  if ($new -eq $text) { Write-Output ('UNCHANGED: ' + $rel); continue }

  [IO.File]::WriteAllText($f.FullName, $new, $(if ($hasBom) { $utf8bom } else { $utf8 }))
  $done++
}
Write-Output ('sections inserted: ' + $done)
