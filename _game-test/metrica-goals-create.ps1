# метрика-goals-create.ps1 — завести шесть целей в счётчике Метрики (JavaScript-событие).
#
# Зачем: цели создаются записью в API (POST /management/v1/counter/<id>/goals). У приложения
# владельца есть право metrika:write, но НЕ metrika:read — поэтому чтение (список счётчиков,
# список целей) отвечает 403, а запись работает. Этот скрипт не читает ничего лишнего:
# он создаёт цели и показывает ответ API по каждой (id, имя, условие) — это и есть проверка.
#
# Запуск из папки calc_docs:
#   powershell -File _game-test\metrica-goals-create.ps1 -DryRun
#   powershell -File _game-test\metrica-goals-create.ps1
#
# Настройки — из sweb-migration\metrica.env (METRIKA_TOKEN, METRIKA_COUNTER).
param([switch]$DryRun)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$root = Split-Path -Parent $PSScriptRoot

$envFile = Join-Path $root 'sweb-migration\metrica.env'
if (-not (Test-Path $envFile)) { throw ('нет файла ' + $envFile) }
$cfg = @{}
foreach ($l in Get-Content $envFile) {
  $s = $l.Trim()
  if ($s -and -not $s.StartsWith('#') -and $s.Contains('=')) { $i = $s.IndexOf('='); $cfg[$s.Substring(0, $i).Trim()] = $s.Substring($i + 1).Trim() }
}
$token   = [string]$cfg['METRIKA_TOKEN']
$counter = [string]$cfg['METRIKA_COUNTER']
if (-not $token)   { throw 'в metrica.env нет METRIKA_TOKEN' }
if (-not $counter) { throw 'в metrica.env нет METRIKA_COUNTER' }

# Идентификатор события (условие цели) → человеческое имя цели
$goals = [ordered]@{
  'расчёт'          = 'Расчёт в калькуляторе'
  'отзыв'           = 'Отправка отзыва'
  'сообщение'       = 'Сообщение через форму'
  'pdf'             = 'Скачивание документа'
  'qr'              = 'Создание QR-кода'
  'расчёт выполнен' = 'Досчитал до результата'
}

Write-Host ('счётчик: ' + $counter + ' | целей к созданию: ' + $goals.Count)
if ($DryRun) {
  foreach ($k in $goals.Keys) { Write-Host ('  [план] JavaScript-событие «' + $k + '» → имя «' + $goals[$k] + '»') }
  Write-Host 'РЕЖИМ ПРОВЕРКИ — ничего не создано.'
  return
}

$log = New-Object System.Collections.Generic.List[string]
$log.Add('=== создание целей Метрики, ' + (Get-Date -Format 'dd.MM.yyyy HH:mm') + ' ===')
$ok = 0; $fail = 0
$i = 0
foreach ($k in $goals.Keys) {
  $i++
  $name = $goals[$k]
  $body = '{"goal":{"name":"' + $name + '","type":"action","conditions":[{"type":"exact","url":"' + $k + '"}]}}'
  $tmp = Join-Path $env:TEMP ('goal-' + $i + '.json')
  [IO.File]::WriteAllText($tmp, $body, (New-Object Text.UTF8Encoding($false)))

  $raw = (curl.exe -s -w '##%{http_code}' -X POST ('https://api-metrika.yandex.net/management/v1/counter/' + $counter + '/goals') `
        -H ('Authorization: OAuth ' + $token) -H 'Content-Type: application/json' --data ('@' + $tmp)) -join ''
  Remove-Item $tmp -Force -ErrorAction SilentlyContinue
  $cut = $raw.LastIndexOf('##')
  $code = $raw.Substring($cut + 2).Trim()
  $text = $raw.Substring(0, $cut)

  if ($code -eq '200') {
    $ok++
    $id = ([regex]::Match($text, '"id":\s*(\d+)')).Groups[1].Value
    $got = ([regex]::Match($text, '"url":\s*"([^"]*)"')).Groups[1].Value
    Write-Host ('  [+] «' + $k + '» → «' + $name + '», id ' + $id + ' (условие: ' + $got + ')') -ForegroundColor Green
    $log.Add('  [+] «' + $k + '» → «' + $name + '», id ' + $id)
  } else {
    $fail++
    Write-Host ('  [!] «' + $k + '» — код ' + $code + ': ' + $text.Substring(0, [Math]::Min(200, $text.Length))) -ForegroundColor Yellow
    $log.Add('  [!] «' + $k + '» — код ' + $code + ': ' + $text.Substring(0, [Math]::Min(200, $text.Length)))
  }
}

$log.Add('итог: создано ' + $ok + ' из ' + $goals.Count + ', ошибок ' + $fail)
[IO.File]::WriteAllLines((Join-Path $root 'shots\_metrica-goals-log.txt'), $log, (New-Object Text.UTF8Encoding($false)))
Write-Host ('итог: создано ' + $ok + ' из ' + $goals.Count + ', ошибок ' + $fail)
Write-Host '[+] журнал: shots\_metrica-goals-log.txt'
