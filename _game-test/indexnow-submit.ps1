# indexnow-submit.ps1 — подача адресов сайта в IndexNow (Яндекс и Bing) для быстрой индексации.
#
# Что делает: находит ключ подтверждения в корне проекта (файл «<ключ>.txt», содержимое = имя файла),
# собирает список адресов (из sitemap.xml или переданные вручную) и отправляет его на сервис IndexNow.
#
# ВАЖНО ПРО ЗАКРЫТЫЙ САЙТ: пока в robots.txt стоит «Disallow: /» (режим переноса), подача бесполезна —
# робот не индексирует закрытые страницы. Скрипт сам это проверяет и останавливается; для осознанного
# теста есть ключ -Force.
#
# Запуск (из корня проекта):
#   powershell -File _game-test\indexnow-submit.ps1 -CheckKey        # проверить, что файл ключа отдаётся 200
#   powershell -File _game-test\indexnow-submit.ps1 -DryRun          # собрать список из sitemap.xml и показать, ничего не отправляя
#   powershell -File _game-test\indexnow-submit.ps1                  # отправить все адреса из sitemap.xml
#   powershell -File _game-test\indexnow-submit.ps1 -Url https://calc-doc.ru/   # отправить один адрес
#   powershell -File _game-test\indexnow-submit.ps1 -Force           # отправить, даже если сайт ещё закрыт (тест)

param(
  [string[]]$Url,
  [switch]$DryRun,
  [switch]$CheckKey,
  [switch]$Force,
  [string]$Host_ = 'calc-doc.ru',
  [string]$Endpoint = 'https://api.indexnow.org/indexnow'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # без полосы прогресса Invoke-WebRequest в выводе
$root = Split-Path -Parent $PSScriptRoot

# ── ключ подтверждения: файл «<ключ>.txt» в корне, содержимое = имя файла ──────────────────────────
$key = ''
$keyFile = ''
foreach ($f in (Get-ChildItem -LiteralPath $root -Filter '*.txt' -File | Where-Object { $_.BaseName -match '^[0-9a-fA-F]{8,128}$' })) {
  $content = ([IO.File]::ReadAllText($f.FullName, [Text.Encoding]::UTF8)).Trim()
  if ($content -eq $f.BaseName) { $key = $content; $keyFile = $f.FullName; break }
}
if ($key -eq '') { throw 'Не нашёл файл ключа вида «<ключ>.txt» с содержимым-ключом в корне проекта.' }
Write-Host ('ключ IndexNow: ' + $key.Substring(0, 8) + '… (' + $key.Length + ' знаков), файл: ' + (Split-Path $keyFile -Leaf)) -ForegroundColor Cyan

if ($CheckKey) {
  $live = 'https://' + $Host_ + '/' + (Split-Path $keyFile -Leaf)
  $raw = (curl.exe -s -L -w '##%{http_code}' --max-time 25 $live) -join "`n"
  $i = $raw.LastIndexOf('##'); $code = if ($i -ge 0) { $raw.Substring($i + 2).Trim() } else { 'нет' }
  $body = if ($i -ge 0) { $raw.Substring(0, $i).Trim() } else { '' }
  Write-Host ('файл ключа на живом: ' + $live) -ForegroundColor Cyan
  Write-Host ('  код: ' + $code + ' | содержимое совпадает: ' + $(if ($body -eq $key) { 'да' } else { 'НЕТ (' + $body + ')' }))
  return
}

# ── список адресов ────────────────────────────────────────────────────────────────────────────────
$list = @()
if ($Url -and $Url.Count -gt 0) {
  $list = @($Url)
} else {
  $sm = Join-Path $root 'sitemap.xml'
  if (-not (Test-Path $sm)) { throw 'Нет sitemap.xml — передайте адреса через -Url.' }
  $list = @([regex]::Matches([IO.File]::ReadAllText($sm, [Text.Encoding]::UTF8), '<loc>([^<]+)</loc>') | ForEach-Object { $_.Groups[1].Value })
}
$list = @($list | Where-Object { $_ -match '^https?://' } | Select-Object -Unique)
if ($list.Count -eq 0) { throw 'Список адресов пуст.' }

# ── проверка «сайт ещё закрыт?» ───────────────────────────────────────────────────────────────────
$robots = Join-Path $root 'robots.txt'
$closed = (Test-Path $robots) -and ((Get-Content -LiteralPath $robots -Raw) -match '(?m)^\s*Disallow:\s*/\s*$')
if ($closed -and -not $Force) {
  Write-Host '[!] Сайт закрыт: в robots.txt стоит «Disallow: /».' -ForegroundColor Yellow
  Write-Host '    Подача сейчас бесполезна — робот не индексирует закрытые страницы.' -ForegroundColor Yellow
  Write-Host '    Дождитесь команды «ОТКРЫВАЕМ САЙТ» или запустите с -Force для теста.' -ForegroundColor Yellow
  return
}
if ($closed) { Write-Host '[i] Сайт закрыт, но запущено с -Force — это тестовая подача.' -ForegroundColor Yellow }

$payload = [ordered]@{
  host        = $Host_
  key         = $key
  keyLocation = 'https://' + $Host_ + '/' + (Split-Path $keyFile -Leaf)
  urlList     = $list
}
$json = $payload | ConvertTo-Json -Depth 4 -Compress
Write-Host ('адресов к подаче: ' + $list.Count + ' | адрес сервиса: ' + $Endpoint) -ForegroundColor Cyan

if ($DryRun) {
  Write-Host 'РЕЖИМ ПРОВЕРКИ — ничего не отправлено. Тело запроса:' -ForegroundColor Yellow
  Write-Host ('  ' + $json.Substring(0, [Math]::Min(400, $json.Length)) + $(if ($json.Length -gt 400) { '…' } else { '' }))
  return
}

try {
  $resp = Invoke-WebRequest -Uri $Endpoint -Method Post -ContentType 'application/json; charset=utf-8' -Body $json -UseBasicParsing -TimeoutSec 60
  $code = [int]$resp.StatusCode
} catch {
  $code = if ($_.Exception.Response) { [int]$_.Exception.Response.StatusCode } else { 0 }
}
$note = switch ($code) {
  200 { 'принято (200)' }
  202 { 'принято, адреса в очереди на проверку (202)' }
  400 { 'ошибка в запросе — проверьте формат (400)' }
  403 { 'ключ не подтверждён: файл ключа недоступен или не совпадает (403)' }
  422 { 'адреса не подходят: другой хост или неверные URL (422)' }
  429 { 'слишком много запросов — подождите (429)' }
  default { 'ответ ' + $code }
}
Write-Host ('результат подачи: ' + $note) -ForegroundColor $(if ($code -eq 200 -or $code -eq 202) { 'Green' } else { 'Yellow' })
