# verify-3layers.ps1 - sverka treh sloev dlya 7 stranits: lokal -> prod-disk(FTP) -> HTTP.
# Vyvodit: versiya bAndla, nalichie sektsii, hash-prefiksy i DIFF/= mezhdu sloyami.
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$root = Split-Path -Parent $PSScriptRoot

$pages = @(
  @('oboi',      'calculators/construction/wallpaper/index.html', 'https://calc-doc.ru/calculators/construction/wallpaper/'),
  @('bolnichny', 'calculators/finance/sick-leave/index.html',      'https://calc-doc.ru/calculators/finance/sick-leave/'),
  @('ipoteka',   'calculators/finance/mortgage/index.html',        'https://calc-doc.ru/calculators/finance/mortgage/'),
  @('glavnaya',  'index.html',                                     'https://calc-doc.ru/'),
  @('qr',        'converters/qr-generator/index.html',             'https://calc-doc.ru/converters/qr-generator/'),
  @('csv',       'converters/csv-to-xlsx/index.html',              'https://calc-doc.ru/converters/csv-to-xlsx/'),
  @('schet',     'generators/invoice/index.html',                  'https://calc-doc.ru/generators/invoice/')
)

$envFile = Join-Path $root 'sweb-migration\deploy.env'
$cfg = @{}
Get-Content $envFile | ForEach-Object {
  $l = $_.Trim(); if ($l -and -not $l.StartsWith('#') -and $l.Contains('=')) {
    $i = $l.IndexOf('='); $cfg[$l.Substring(0, $i).Trim()] = $l.Substring($i + 1).Trim()
  }
}
$hostName = $cfg['HOST']; $user = $cfg['USER']; $pass = $cfg['PASS']
$remote = $cfg['REMOTE_PATH'].TrimEnd('/'); $port = if ($cfg['PORT']) { [int]$cfg['PORT'] } else { 21 }

function SHA([byte[]]$b) { $s = [Security.Cryptography.SHA256]::Create(); ([BitConverter]::ToString($s.ComputeHash($b))).Replace('-', '').ToLower() }
function Get-Ftp([string]$rel) {
  $uri = "ftp://${hostName}:${port}${remote}/${rel}"
  for ($try = 1; $try -le 4; $try++) {
    try {
      $r = [Net.FtpWebRequest]::Create($uri)
      $r.Method = [Net.WebRequestMethods+Ftp]::DownloadFile
      $r.UseBinary = $true; $r.UsePassive = $true; $r.KeepAlive = $false
      $r.Credentials = New-Object Net.NetworkCredential($user, $pass)
      $resp = $r.GetResponse(); $ms = New-Object IO.MemoryStream
      $resp.GetResponseStream().CopyTo($ms); $resp.Close(); return $ms.ToArray()
    } catch {
      if ($try -eq 4) { throw $_.Exception }
      Start-Sleep -Seconds 3
    }
  }
}

$wc = New-Object Net.WebClient
$wc.Headers.Add('Accept-Encoding', 'identity')

Write-Output ('{0,-10} {1,-8} {2,-7} {3,-8} {4,-8}  {5}  {6}  {7}' -f 'page','bundle','section','http~ftp','ftp~local','local','ftp','http')
foreach ($p in $pages) {
  $name = $p[0]; $rel = $p[1]; $url = $p[2]
  $hLocal = SHA ([IO.File]::ReadAllBytes((Join-Path $root $rel)))
  $hFtp   = SHA (Get-Ftp $rel)
  $httpBytes = $wc.DownloadData($url); $hHttp = SHA $httpBytes
  $t = [Text.Encoding]::UTF8.GetString($httpBytes)
  $sec = $t -match 'aria-label="Другие инструменты"'
  $bver = if ($t -match 'ui-bundle\.min\.js\?v=(\d+)') { 'ui=' + $Matches[1] }
          elseif ($t -match 'home-bundle\.min\.js\?v=(\d+)') { 'home=' + $Matches[1] } else { '?' }
  $hf = if ($hHttp -eq $hFtp) { '=' } else { 'DIFF' }
  $fl = if ($hFtp -eq $hLocal) { '=' } else { 'DIFF' }
  Write-Output ('{0,-10} {1,-8} {2,-7} {3,-8} {4,-8}  {5}  {6}  {7}' -f $name, $bver, $sec, $hf, $fl, $hLocal.Substring(0,8), $hFtp.Substring(0,8), $hHttp.Substring(0,8))
}
