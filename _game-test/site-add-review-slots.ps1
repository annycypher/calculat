# site-add-review-slots.ps1 — добавление слота отзывов и формы отзыва в страницы сайта
# (шаг 5.1 задания MASTER-FINAL.md).
#
# Что делает:
#   • берёт все страницы сайта, где уже стоит якорь <!--SLOT:ads-before-footer--> (48 контентных;
#     privacy/search/404 пропускаются — на них формы отзывов нет);
#   • перед этим якорем вставляет: слот <!--SLOT:reviews--> (его позже заполнит панель —
#     блок «Отзывы пользователей»), саму форму отзыва и подключение /js/reviews.js;
#   • перед каждой правкой кладёт копию файла в backups\files\<дата_время>__<путь>;
#   • повторный запуск ничего не меняет (идемпотентность).
#
# Запуск:
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-review-slots.ps1 -DryRun
#   powershell -NoProfile -ExecutionPolicy Bypass -File _game-test\site-add-review-slots.ps1
#
# Владелец разрешил правки страниц сайта этим шагом задания (фаза 5, «форма на страницах»).

param([switch]$DryRun)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root      = Split-Path -Parent $PSScriptRoot
$stamp     = Get-Date -Format 'yyyy-MM-dd_HH-mm-ss'
$backupDir = Join-Path $root 'backups\files'
$report    = Join-Path $env:TEMP ('calcdoc-review-slots-' + (Get-Date -Format 'HHmmss') + '.txt')
$skipDirs  = @('admin-panel-x7k2', '_archive', '_backup', 'backups', 'content', 'media', '_game-test', 'sweb-migration', 'node_modules', 'js', 'libs', 'api')
$anchor    = '<!--SLOT:ads-before-footer-->'
$marker    = '<!--SLOT:reviews-->'

$block = @'
      <!--SLOT:reviews-->
      <!--/SLOT:reviews-->
      <section class="reviews" id="reviews-form" data-page="__PAGE__" style="max-width:760px;margin:28px auto 10px;padding:18px;border:1px solid rgba(255,255,255,.10);border-radius:16px;background:rgba(255,255,255,.03)">
        <h2 style="margin:0 0 6px;font-size:20px">Оставить отзыв</h2>
        <p style="margin:0 0 14px;font-size:14px;color:#a9a4bb">Почту не спрашиваем: нужны только имя, текст и, если хотите, оценка. Отзыв появляется на сайте после проверки.</p>
        <form method="post" action="/api/reviews.php" data-reviews-form>
          <input type="hidden" name="page" value="__PAGE__" />
          <div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden" aria-hidden="true">
            <label for="reviews-website">Не заполняйте это поле</label>
            <input type="text" id="reviews-website" name="website" value="" tabindex="-1" autocomplete="off" />
          </div>
          <label for="reviews-name">Ваше имя</label>
          <input type="text" id="reviews-name" name="name" required minlength="2" maxlength="30" autocomplete="name" />
          <label for="reviews-text">Комментарий</label>
          <textarea id="reviews-text" name="text" required minlength="10" maxlength="1000" rows="4" style="width:100%"></textarea>
          <fieldset style="border:0;margin:12px 0 0;padding:0">
            <legend style="font-size:14px;color:#a9a4bb">Оценка — по желанию</legend>
            <label style="margin-right:12px"><input type="radio" name="rating" value="0" checked /> без оценки</label>
            <label style="margin-right:12px"><input type="radio" name="rating" value="5" /> ★★★★★</label>
            <label style="margin-right:12px"><input type="radio" name="rating" value="4" /> ★★★★</label>
            <label style="margin-right:12px"><input type="radio" name="rating" value="3" /> ★★★</label>
            <label style="margin-right:12px"><input type="radio" name="rating" value="2" /> ★★</label>
            <label><input type="radio" name="rating" value="1" /> ★</label>
          </fieldset>
          <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:14px">
            <button type="submit">Отправить отзыв</button>
            <span data-reviews-note style="font-size:13px;color:#a9a4bb">Публикуется после проверки.</span>
          </div>
        </form>
      </section>
      <script src="/js/reviews.js?v=1" defer></script>
'@

if (-not $DryRun -and -not (Test-Path $backupDir)) { New-Item -ItemType Directory -Path $backupDir -Force | Out-Null }

$pages = Get-ChildItem $root -Recurse -Filter '*.html' -File -ErrorAction SilentlyContinue | Where-Object {
    $rel = $_.FullName.Substring($root.Length + 1)
    $top = ($rel -split '[\\/]')[0]
    $skipDirs -notcontains $top
}

$lines = New-Object System.Collections.Generic.List[string]
$changed = 0; $already = 0; $noAnchor = 0

foreach ($page in $pages) {
    $rel  = $page.FullName.Substring($root.Length + 1) -replace '\\', '/'
    $text = [IO.File]::ReadAllText($page.FullName)

    if ($text.Contains($marker)) { $already++; $lines.Add('уже есть: ' + $rel); continue }
    if (-not $text.Contains($anchor)) { $noAnchor++; $lines.Add('нет якоря: ' + $rel); continue }

    $eol    = if ($text.Contains("`r`n")) { "`r`n" } else { "`n" }
    $pageUrl = '/' + ($rel -replace 'index\.html$', '')
    $insert = ($block -replace "`r?`n", $eol).Replace('__PAGE__', $pageUrl)

    $fresh = $text.Replace($anchor, $insert + $eol + $anchor)
    if ($fresh -eq $text) { $already++; $lines.Add('без изменений: ' + $rel); continue }

    if (-not $DryRun) {
        $safeName = ($rel -replace '[\\/]', '__')
        Copy-Item $page.FullName (Join-Path $backupDir ($stamp + '__' + $safeName)) -Force
        [IO.File]::WriteAllText($page.FullName, $fresh, (New-Object System.Text.UTF8Encoding($false)))
    }
    $changed++
    $lines.Add(($(if ($DryRun) { 'будет добавлено: ' } else { 'добавлено: ' })) + $rel + ' → ' + $pageUrl)
}

$lines | Set-Content -Path $report -Encoding UTF8

Write-Host ''
Write-Host ("Страниц с якорем: " + ($changed + $already))
Write-Host ("Изменено: " + $changed + "   Уже было: " + $already + "   Без якоря (пропущено): " + $noAnchor)
if ($DryRun) { Write-Host 'Режим -DryRun: файлы не менялись, копии не делались.' }
Write-Host ("Копии: " + $backupDir)
Write-Host ("Отчёт: " + $report)
