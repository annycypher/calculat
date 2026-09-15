<#
  upload-file.ps1 — залить на хостинг ОДИН (или несколько) файлов, не гоняя весь сайт.
  Нужен для точечных правок: полная заливка занимает минуты и срывается на
  троттлинге FTP (ошибка 553 "File name not allowed" — просто повторите запуск).

  Запуск (из корня проекта):
    powershell -ExecutionPolicy Bypass -File sweb-migration\upload-file.ps1 styles.css
    powershell -ExecutionPolicy Bypass -File sweb-migration\upload-file.ps1 header.css styles.css
    powershell -ExecutionPolicy Bypass -File sweb-migration\upload-file.ps1 -ListFile list.txt

  Пути — относительно корня сайта (как их видит браузер): styles.css, games/2048/index.html.
  Для длинного списка используйте -ListFile: при запуске через powershell -File
  именованный параметр надёжнее, чем перечисление через пробел (после -File в
  позиционный массив попадает только одно значение).
  Настройки берутся из sweb-migration\deploy.env (тот же файл, что у deploy.ps1).
#>
param(
  [string[]]$Path,
  [string]$ListFile,
  [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

# Собираем список бережно: @($null) в PowerShell — это массив с одним $null,
# поэтому пустые значения отбрасываем явно, иначе получится путь «корень сайта».
$todo = New-Object System.Collections.Generic.List[string]
foreach ($x in @($Path)) { if ($x -and $x.Trim()) { $todo.Add($x.Trim()) } }
if ($ListFile) {
  if (-not (Test-Path $ListFile)) { throw "Не найден список файлов: $ListFile" }
  foreach ($l in Get-Content $ListFile) { if ($l -and $l.Trim()) { $todo.Add($l.Trim()) } }
}
if ($todo.Count -eq 0) { throw 'Укажите, что заливать: -Path styles.css или -ListFile список.txt' }
$Path = $todo.ToArray()

$envFile = Join-Path $PSScriptRoot 'deploy.env'
if (-not (Test-Path $envFile)) { throw "Нет файла $envFile — скопируйте deploy.env.example в deploy.env и заполните." }
$cfg = @{}
Get-Content $envFile | ForEach-Object {
  $l = $_.Trim()
  if ($l -and -not $l.StartsWith('#') -and $l.Contains('=')) {
    $i = $l.IndexOf('='); $cfg[$l.Substring(0, $i).Trim()] = $l.Substring($i + 1).Trim()
  }
}
$hostName = $cfg['HOST']; $user = $cfg['USER']; $remote = $cfg['REMOTE_PATH'].TrimEnd('/')
$port = if ($cfg['PORT']) { [int]$cfg['PORT'] } else { 21 }
$pass = $cfg['PASS']; if (-not $pass) { throw 'Для точечной заливки нужно поле PASS в deploy.env' }

Write-Host ("К заливке: {0} файл(ов):" -f $Path.Count)
foreach ($rel in $Path) { Write-Host ('  ' + $rel) }
if ($DryRun) { Write-Host 'DryRun: ничего не отправлено.' -ForegroundColor Yellow; return }

foreach ($rel in $Path) {
  $rel = $rel -replace '\\', '/'
  $full = Join-Path $root $rel
  if (-not (Test-Path $full)) { throw "Не найден локальный файл: $full" }
  $bytes = [IO.File]::ReadAllBytes($full)
  # FTP-сервер sweb иногда отдаёт 553 "File name not allowed" при частых подряд
  # запросах — это троттлинг, помогает повтор через пару секунд.
  $ok = $false
  for ($try = 1; $try -le 3 -and -not $ok; $try++) {
    try {
      $r = [System.Net.FtpWebRequest]::Create("ftp://${hostName}:${port}${remote}/${rel}")
      $r.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
      $r.UseBinary = $true; $r.UsePassive = $true; $r.KeepAlive = $false
      $r.Credentials = New-Object System.Net.NetworkCredential($user, $pass)
      $r.ContentLength = $bytes.Length
      $s = $r.GetRequestStream(); $s.Write($bytes, 0, $bytes.Length); $s.Close()
      $r.GetResponse().Close()
      $ok = $true
      Write-Host ("  + {0}  ({1} Б){2}" -f $rel, $bytes.Length, $(if ($try -gt 1) { "  со $try-й попытки" } else { '' }))
    } catch {
      if ($try -eq 3) { throw ("Не удалось залить $rel : " + $_.Exception.Message) }
      Write-Host ("  ... {0}: попытка {1} не прошла ({2}), повтор через 3 с" -f $rel, $try, $_.Exception.Message) -ForegroundColor DarkYellow
      Start-Sleep -Seconds 3
    }
  }
}
Write-Host 'Готово (FTP, точечно).' -ForegroundColor Green
