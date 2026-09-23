# metrica-goals-api.ps1 — создание целей счётчика Метрики через Management API.
#
# Что нужно: OAuth-токен Яндекса с правом metrika:write (создаётся на oauth.yandex.ru).
# Токен хранится в файле sweb-migration\metrica.env (он в .gitignore) — в чат не попадает.
# Формат файла:
#   METRIKA_TOKEN=ваш_токен
#   METRIKA_COUNTER=112558731
#
# Запуск (из корня проекта):
#   powershell -File _game-test\metrica-goals-api.ps1 -Check    # кто я, счётчики, какие цели уже есть
#   powershell -File _game-test\metrica-goals-api.ps1 -DryRun   # показать, какие цели будут созданы
#   powershell -File _game-test\metrica-goals-api.ps1 -Create   # создать цели

param(
  [switch]$Check,
  [switch]$DryRun,
  [switch]$Create,
  [string]$Api = 'https://api-metrika.yandex.net'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$root = Split-Path -Parent $PSScriptRoot

$envFile = Join-Path $root 'sweb-migration\metrica.env'
if (-not (Test-Path $envFile)) {
  Write-Host '[!] Нет файла sweb-migration\metrica.env с токеном.' -ForegroundColor Yellow
  Write-Host '    Создайте его (файл в .gitignore) со строками:' -ForegroundColor Yellow
  Write-Host '      METRIKA_TOKEN=ваш_токен' -ForegroundColor Yellow
  Write-Host '      METRIKA_COUNTER=112558731' -ForegroundColor Yellow
  return
}
$cfg = @{}
foreach ($l in Get-Content $envFile) {
  $line = $l.Trim()
  if ($line -and -not $line.StartsWith('#') -and $line.Contains('=')) {
    $i = $line.IndexOf('='); $cfg[$line.Substring(0, $i).Trim()] = $line.Substring($i + 1).Trim()
  }
}
$token = [string]$cfg['METRIKA_TOKEN']
$counter = if ($cfg['METRIKA_COUNTER']) { [string]$cfg['METRIKA_COUNTER'] } else { '112558731' }
if (-not $token -and -not $DryRun) { throw 'В metrica.env нет строки METRIKA_TOKEN= — впишите токен (см. metrica.env.example).' }
if ($token) { Write-Host ('токен: ' + $token.Substring(0, [Math]::Min(6, $token.Length)) + '… | счётчик: ' + $counter) -ForegroundColor Cyan }
else { Write-Host ('токен не вписан — будет показан только план | счётчик: ' + $counter) -ForegroundColor Yellow }

function Call([string]$method, [string]$path, [string]$body = '') {
  $headers = @{ Authorization = 'OAuth ' + $token; Accept = 'application/x-yametrika+json' }
  try {
    if ($method -eq 'GET') {
      $r = Invoke-WebRequest -Uri ($Api + $path) -Headers $headers -Method Get -UseBasicParsing -TimeoutSec 40
    } else {
      $r = Invoke-WebRequest -Uri ($Api + $path) -Headers $headers -Method Post -ContentType 'application/x-yametrika+json' -Body ([Text.Encoding]::UTF8.GetBytes($body)) -UseBasicParsing -TimeoutSec 40
    }
    return @{ code = [int]$r.StatusCode; body = $r.Content }
  } catch {
    $code = if ($_.Exception.Response) { [int]$_.Exception.Response.StatusCode } else { 0 }
    $text = ''
    if ($_.Exception.Response) {
      $sr = New-Object IO.StreamReader($_.Exception.Response.GetResponseStream())
      $text = $sr.ReadToEnd(); $sr.Close()
    }
    return @{ code = $code; body = $text; error = $_.Exception.Message }
  }
}

# ── кого видит токен и какие цели уже есть ───────────────────────────────────────────────────────
if ($token) {
$ping = Call 'GET' '/management/v1/counters?per_page=50'
Write-Host ('запрос счётчиков: код ' + $ping.code) -ForegroundColor $(if ($ping.code -eq 200) { 'Green' } else { 'Yellow' })
if ($ping.code -ne 200) {
  Write-Host ('  ответ API: ' + $ping.body.Substring(0, [Math]::Min(300, $ping.body.Length)))
  if ($ping.code -eq 401) { Write-Host '  → токен не принят: проверьте, что он создан для Метрики и не истёк.' -ForegroundColor Yellow }
  if ($ping.code -eq 403) {
  Write-Host '  → чтение недоступно: у токена нет права metrika:read (приложение просит только запись).' -ForegroundColor Yellow
  Write-Host '    Созданию целей это НЕ мешает — запись работает: powershell -File _game-test\metrica-goals-create.ps1' -ForegroundColor Yellow
  Write-Host '    Если нужно читать счётчики и цели через API — добавьте в приложении право «Метрика: чтение» и получите новый токен.' -ForegroundColor Yellow
}
  return
}
$counters = ($ping.body | ConvertFrom-Json).counters
Write-Host 'доступные счётчики:' -ForegroundColor Cyan
$counters | ForEach-Object { Write-Host ('  ' + $_.id + ' — ' + $_.name + ' (' + $_.site + ')') }

$goals = Call 'GET' ('/management/v1/counter/' + $counter + '/goals')
Write-Host ('цели счётчика ' + $counter + ': код ' + $goals.code)
if ($goals.code -eq 200) {
  $list = ($goals.body | ConvertFrom-Json).goals
  if (@($list).Count -eq 0) { Write-Host '  целей пока нет' } else {
    $list | ForEach-Object { Write-Host ('  [' + $_.type + '] ' + $_.name + ' (id ' + $_.id + ')') }
  }
} else {
  Write-Host ('  ответ: ' + $goals.body.Substring(0, [Math]::Min(300, $goals.body.Length)))
}

} else {
  Write-Host 'проверка доступа к API пропущена: нет токена (план ниже не требует прав).' -ForegroundColor Yellow
}

# ── какие цели создаём ───────────────────────────────────────────────────────────────────────────
# Тип action — это «Целевое событие»: сайт уже отправляет ym(счётчик, 'reachGoal', имя) для кнопок
# и форм с data-metric-goal (файл js/metrica-goals.js в бандлах), поэтому достаточно завести
# цели с этими идентификаторами. Имена ДОЛЖНЫ совпадать с тем, что уходит с сайта.
$wanted = @(
  @{ name = 'расчёт';           note = 'кнопки «Рассчитать» в калькуляторах' },
  @{ name = 'отзыв';            note = 'отправка отзыва' },
  @{ name = 'сообщение';        note = 'форма контактов' },
  @{ name = 'pdf';              note = 'скачивание PDF в генераторах' },
  @{ name = 'qr';               note = 'QR-генератор и главная' },
  @{ name = 'расчёт выполнен';  note = 'инженерные калькуляторы и досрочное погашение' }
)

Write-Host ''
Write-Host 'к созданию (тип «Целевое событие»):' -ForegroundColor Cyan
foreach ($w in $wanted) { Write-Host ('  ' + $w.name + ' — ' + $w.note) }

if ($DryRun -or (-not $Create)) {
  Write-Host 'РЕЖИМ ПРОВЕРКИ — цели не создавались.' -ForegroundColor Yellow
  return
}

foreach ($w in $wanted) {
  $body = @{ goal = @{ name = $w.name; type = 'action' } } | ConvertTo-Json -Depth 6 -Compress
  $res = Call 'POST' ('/management/v1/counter/' + $counter + '/goals') $body
  $mark = if ($res.code -eq 200 -or $res.code -eq 201) { 'создана' } else { 'ОШИБКА' }
  Write-Host ('  ' + $w.name + ': ' + $mark + ' (код ' + $res.code + ')') -ForegroundColor $(if ($mark -eq 'создана') { 'Green' } else { 'Yellow' })
  if ($mark -eq 'ОШИБКА') { Write-Host ('      ответ API: ' + $res.body.Substring(0, [Math]::Min(300, $res.body.Length))) }
}
