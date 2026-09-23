# metrica-token-from-code.ps1 — обмен кода подтверждения Яндекс.OAuth на токен Метрики.
#
# Зачем: ClientID и секрет приложения сами токен не выдают — нужно, чтобы владелец аккаунта
# открыл ссылку авторизации, вошёл и разрешил доступ. После этого Яндекс показывает короткий
# код; этот скрипт меняет код на постоянный токен и аккуратно кладёт его в sweb-migration\metrica.env
# (файл в .gitignore). Токен нигде не печатается.
#
# Запуск (из корня проекта):
#   powershell -File _game-test\metrica-token-from-code.ps1 -ShowAuthUrl      # показать ссылку для браузера
#   powershell -File _game-test\metrica-token-from-code.ps1 -Code 1234567     # обменять код на токен
#   powershell -File _game-test\metrica-token-from-code.ps1                   # спросит код скрытно

param([string]$Code, [switch]$ShowAuthUrl)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$root = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $root 'sweb-migration\metrica.env'
if (-not (Test-Path $envFile)) { throw ('Нет файла ' + $envFile) }

$cfg = @{}
foreach ($l in Get-Content $envFile) {
  $line = $l.Trim()
  if ($line -and -not $line.StartsWith('#') -and $line.Contains('=')) {
    $i = $line.IndexOf('='); $cfg[$line.Substring(0, $i).Trim()] = $line.Substring($i + 1).Trim()
  }
}
$clientId = [string]$cfg['METRIKA_CLIENT_ID']
$clientSecret = [string]$cfg['METRIKA_CLIENT_SECRET']

if ($ShowAuthUrl) {
  if (-not $clientId) { throw 'В metrica.env нет строки METRIKA_CLIENT_ID=' }
  Write-Host 'Откройте ссылку в браузере, войдите в аккаунт Яндекс (владелец счётчика) и разрешите доступ:' -ForegroundColor Cyan
  Write-Host ('  https://oauth.yandex.ru/authorize?response_type=code&client_id=' + $clientId)
  Write-Host 'После «Разрешить» страница покажет код подтверждения — пришлите его (или запустите скрипт с -Code).' -ForegroundColor Cyan
  return
}

if (-not $clientId -or -not $clientSecret) { throw 'В metrica.env нужны METRIKA_CLIENT_ID= и METRIKA_CLIENT_SECRET=' }
if (-not $Code) { $Code = (Read-Host 'Код подтверждения из браузера').Trim() }
if (-not $Code) { throw 'Код не введён.' }

Write-Host ('меняю код на токен (код: ' + $Code.Substring(0, [Math]::Min(3, $Code.Length)) + '…)') -ForegroundColor Cyan
$body = 'grant_type=authorization_code&code=' + [Uri]::EscapeDataString($Code) + '&client_id=' + [Uri]::EscapeDataString($clientId) + '&client_secret=' + [Uri]::EscapeDataString($clientSecret)
$raw = (curl.exe -s -w '##%{http_code}' -X POST 'https://oauth.yandex.ru/token' -H 'Content-Type: application/x-www-form-urlencoded' --max-time 40 --data $body) -join ''
$i = $raw.LastIndexOf('##')
$httpCode = if ($i -ge 0) { $raw.Substring($i + 2).Trim() } else { 'нет' }
$text = if ($i -ge 0) { $raw.Substring(0, $i) } else { $raw }

if ($httpCode -ne '200') {
  Write-Host ('[!] Яндекс не выдал токен (код ' + $httpCode + ').') -ForegroundColor Yellow
  Write-Host ('    ответ: ' + $text.Substring(0, [Math]::Min(300, $text.Length)))
  Write-Host '    Частые причины: код одноразовый и уже использован, истёк (код живёт минуты),' -ForegroundColor Yellow
  Write-Host '    у приложения не отмечен доступ metrika:write или токен выпущен под другим аккаунтом.' -ForegroundColor Yellow
  return
}

$json = $text | ConvertFrom-Json
$token = [string]$json.access_token
if (-not $token) { Write-Host ('[!] В ответе нет access_token: ' + $text.Substring(0, [Math]::Min(300, $text.Length))) -ForegroundColor Yellow; return }

# Кладём токен в metrica.env, сохраняя остальные строки как есть.
$lines = Get-Content $envFile
$found = $false
$out = foreach ($l in $lines) { if ($l.TrimStart().StartsWith('METRIKA_TOKEN=')) { $found = $true; 'METRIKA_TOKEN=' + $token } else { $l } }
if (-not $found) { $out = @($out) + ('METRIKA_TOKEN=' + $token) }
[IO.File]::WriteAllLines($envFile, $out, (New-Object Text.UTF8Encoding($false)))

Write-Host ('[+] токен получен (' + $token.Length + ' знаков) и записан в sweb-migration\metrica.env') -ForegroundColor Green
Write-Host '    дальше: powershell -File _game-test\metrica-goals-api.ps1 -Check' -ForegroundColor Green
