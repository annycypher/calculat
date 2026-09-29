param(
  [Parameter(Mandatory=$true)][string]$Url,
  [string]$Label = '',
  [string]$OutJson = ''
)
$ErrorActionPreference = 'Stop'
$key = 'AIzaSyBM9mCJ0Z4cZBNaIvywLxZZ1NG27RMLJ-0'
$api = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=' + [uri]::EscapeDataString($Url) + '&strategy=mobile&category=performance&key=' + $key

$resp = Invoke-RestMethod -Uri $api -Method Get -TimeoutSec 180
$lh = $resp.lighthouseResult
$cats = $lh.categories
$score = [math]::Round([double]$cats.performance.score * 100)
$aud = $lh.audits

function Num($a, $k) { if ($null -ne $a -and $null -ne $a.$k -and $null -ne $a.$k.numericValue) { return $a.$k.numericValue } return $null }

$fcp = Num $aud 'first-contentful-paint'
$lcp = Num $aud 'largest-contentful-paint'
$cls = Num $aud 'cumulative-layout-shift'
$tbt = Num $aud 'total-blocking-time'

$ls = $aud.'layout-shifts'
$items = @()
if ($null -ne $ls -and $null -ne $ls.details -and $null -ne $ls.details.items) {
  $items = $ls.details.items
}

$rows = @()
foreach ($it in $items) {
  $node = $it.node
  $sel = ''
  $rect = $null
  if ($null -ne $node) {
    $sel = $node.selector
    $rect = $node.boundingRect
  }
  $sub = @()
  if ($null -ne $it.subItems -and $null -ne $it.subItems.items) {
    foreach ($s in $it.subItems.items) {
      $sub += [ordered]@{
        cause = $s.cause
        previousRect = $s.previousRect
        currentRect = $s.currentRect
        node_selector = if ($null -ne $s.node) { $s.node.selector } else { $null }
        score = $s.score
      }
    }
  }
  $rows += [ordered]@{
    score = $it.score
    selector = $sel
    boundingRect = $rect
    nodeLabel = if ($null -ne $node) { $node.nodeLabel } else { $null }
    snippet = if ($null -ne $node) { $node.snippet } else { $null }
    subItems = $sub
  }
}

$out = [ordered]@{
  label = $Label
  url = $Url
  score = $score
  fcp_ms = if ($null -ne $fcp) { [math]::Round($fcp) } else { $null }
  lcp_ms = if ($null -ne $lcp) { [math]::Round($lcp) } else { $null }
  cls = $cls
  tbt_ms = if ($null -ne $tbt) { [math]::Round($tbt) } else { $null }
  layoutShifts = $rows
}

if ($OutJson -ne '') {
  $out | ConvertTo-Json -Depth 12 | Out-File -FilePath $OutJson -Encoding utf8
}

# компактный вывод
Write-Output ("URL=" + $Url)
Write-Output ("score=" + $score + "  FCP=" + $(if($null -ne $fcp){[math]::Round($fcp)}else{'n/a'}) + "  LCP=" + $(if($null -ne $lcp){[math]::Round($lcp)}else{'n/a'}) + "  CLS=" + $cls + "  TBT=" + $(if($null -ne $tbt){[math]::Round($tbt)}else{'n/a'}))
Write-Output ("layoutShifts items=" + $rows.Count)
$i = 0
foreach ($r in $rows) {
  $i++
  Write-Output ("[" + $i + "] score=" + $r.score + "  selector=" + $r.selector)
  if ($null -ne $r.boundingRect) {
    $br = $r.boundingRect
    Write-Output ("     rect: x=" + $br.left + " y=" + $br.top + " w=" + $br.width + " h=" + $br.height)
  }
  if ($r.subItems.Count -gt 0) {
    foreach ($s in $r.subItems) {
      Write-Output ("     sub: cause=" + $s.cause + "  prev=" + $(if($null -ne $s.previousRect){'w'+$s.previousRect.width+' h'+$s.previousRect.height}else{'n/a'}) + "  curr=" + $(if($null -ne $s.currentRect){'w'+$s.currentRect.width+' h'+$s.currentRect.height}else{'n/a'}))
    }
  }
}
