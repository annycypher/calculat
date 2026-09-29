# metrika-goals-reaches.ps1 — сколько раз срабатывали цели счётчика за период (24.09.2026).
#
# Зачем: важно не только то, что цели созданы, но и что они живые. Этот скрипт запрашивает
# статистику по каждой цели отдельным запросом (несколько goal-метрик в одном запросе API не отдаёт).
#
# Токен берётся из настроек панели (content/secrets.json) и в вывод не попадает — печатаются только числа.
#
# Запуск из корня проекта:
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\metrika-goals-reaches.ps1
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\metrika-goals-reaches.ps1 -Days 30

param([int]$Days = 7)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root = Split-Path -Parent $PSScriptRoot
$s = Get-Content (Join-Path $root 'content\secrets.json') -Raw | ConvertFrom-Json
$token = [string]$s.metrika.token
$counter = [string]$s.metrika.counter
if (-not $token -or -not $counter) { throw 'В content/secrets.json нет токена или номера счётчика Метрики.' }

$goals = [ordered]@{
  '662100526' = 'Расчёт в калькуляторе (метка «расчёт»)'
  '662100527' = 'Отправка отзыва («отзыв»)'
  '662100528' = 'Сообщение через форму («сообщение»)'
  '662100529' = 'Скачивание документа («pdf»)'
  '662100530' = 'Создание QR-кода («qr»)'
  '662100547' = 'Досчитал до результата («расчёт выполнен»)'
}

Write-Host ('Цели счётчика ' + $counter + ', срабатывания за ' + $Days + ' дн.:')
$sum = 0
foreach ($id in $goals.Keys) {
  $url = 'https://api-metrika.yandex.net/stat/v1/data?ids=' + $counter + '&metrics=ym:s:goal' + $id +
         'reaches&date1=' + $Days + 'daysAgo&date2=today'
  try {
    $r = Invoke-RestMethod -Uri $url -Headers @{ Authorization = 'OAuth ' + $token } -TimeoutSec 25 -ErrorAction Stop
    $n = [int]$r.totals[0]
    $sum += $n
    '{0,-48} {1,4}' -f $goals[$id], $n
  } catch {
    '{0,-48} ошибка запроса' -f $goals[$id]
  }
}
Write-Host ('Всего срабатываний за период: ' + $sum)
