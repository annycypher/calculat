<#
  add-ssh-key.ps1 — одноразовая установка нашего публичного ключа на хостинг sweb.

  Что делает:
    1) подключается к серверу по SSH (пароль вы вводите РУКАМИ в терминале,
       он нигде не сохраняется и никуда мне не передаётся);
    2) добавляет наш публичный ключ в ~/.ssh/authorized_keys на сервере;
    3) сразу проверяет вход по ключу.

  Запуск (одна строка):
    powershell -ExecutionPolicy Bypass -File C:\Users\krs3d\.cline\data\workspaces\chat\calc_docs\sweb-migration\add-ssh-key.ps1

  Если увидите «SSH_OK» — доступ готов, напишите мне «готово».
#>
$ErrorActionPreference = 'Stop'
$hostName = '77.222.61.245'
$user = 'novidesiru'
$port = 22

$pubFile = Join-Path $PSScriptRoot 'id_sweb.pub'
$keyFile = Join-Path $PSScriptRoot 'id_sweb'
if (-not (Test-Path $pubFile)) { throw "Не найден $pubFile" }
if (-not (Get-Command ssh -ErrorAction SilentlyContinue)) {
  throw 'Не найден клиент ssh. Включите: Параметры - Приложения - Дополнительные компоненты - Клиент OpenSSH.'
}
$pub = (Get-Content $pubFile -Raw).Trim()

Write-Host 'Сейчас откроется SSH. Нужно:' -ForegroundColor Cyan
Write-Host '  • на вопрос про отпечаток сервера ответить  yes' -ForegroundColor Cyan
Write-Host '  • ввести пароль от аккаунта sweb (в панели он подписан «пароль от аккаунта»)' -ForegroundColor Cyan
Write-Host ''

$remote = 'mkdir -p ~/.ssh && chmod 700 ~/.ssh && (grep -qF ''' + $pub + ''' ~/.ssh/authorized_keys 2>/dev/null || echo ''' + $pub + ''' >> ~/.ssh/authorized_keys) && chmod 600 ~/.ssh/authorized_keys && echo KEY_INSTALLED'
# Публичный ключ при входе не предлагаем (он ещё не установлен) — идём сразу к запросу пароля,
# чтобы не было лишней попытки по ключу и строки «Permission denied» в выводе.
& ssh -p $port -o StrictHostKeyChecking=accept-new -o PubkeyAuthentication=no `
      -o PreferredAuthentications=keyboard-interactive,password -o NumberOfPasswordPrompts=3 `
      "$user@$hostName" $remote

if ($LASTEXITCODE -eq 0) {
  Write-Host ''
  Write-Host 'Ключ установлен. Проверяю вход без пароля...' -ForegroundColor Green
  & ssh -i $keyFile -p $port -o BatchMode=yes -o StrictHostKeyChecking=accept-new "$user@$hostName" 'echo SSH_OK; pwd; ls -la'
  Write-Host ''
  Write-Host 'Если выше есть SSH_OK — доступ готов, напишите мне «готово».' -ForegroundColor Green
} else {
  Write-Host ''
  Write-Host 'Не получилось войти по паролю. Варианты:' -ForegroundColor Yellow
  Write-Host '  • проверить пароль (он же пароль входа в панель / «пароль от аккаунта»);' -ForegroundColor Yellow
  Write-Host '  • либо создать в панели отдельного FTP-пользователя — тогда я залью файлы по FTP,' -ForegroundColor Yellow
  Write-Host '    а его логин/пароль положим в sweb-migration\deploy.env (MODE=ftp).' -ForegroundColor Yellow
}
