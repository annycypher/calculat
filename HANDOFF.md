# HANDOFF — CalcDoc (сводка состояния)

## Проект
- Сайт calc-doc.ru (sweb, nginx, PHP 7.1). Локальный воркспейс: `C:\Users\krs3d\.cline\data\workspaces\chat\calc_docs`
- Админ-панель: локально `admin-panel-x7k2`, на проде `/_sysudh2xsye/` (маппинг делает `sweb-migration\upload-panel.ps1`)
- Правила: `clinerules` (файл без расширения). Журнал: `PROGRESS.md` — актуален: 29.09.2026 дописан эпизод индекс-чека pgen, добавлен раздел «Активный план»

## Инструменты / пути
- PHP: `C:\Users\krs3d\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe`
- Локально sqlite включать флагами: `php -d extension=pdo_sqlite -d extension=sqlite3`
- Локальный сервер: `127.0.0.1:8099` (поднят с sqlite). Локально у curl нет CA-бандла → для тестов `dev_ssl_noverify=1` в seo_settings (по умолчанию 0)
- Заливка: `sweb-migration\upload-panel.ps1` (файлы панели), `upload-file.ps1`, `ftp-mkdir.ps1`, `ftp-get-file.ps1`, `ftp-remove.ps1`. Креды: `sweb-migration\deploy.env` (MODE=ftp)
- Бэкапы: `_backup\2026-09-26-seo-phase1|phase2a|phase2b\`

## БД / хранение
- Одна SQLite: `content/seo/gsc.sqlite` (PDO, WAL; читать ТОЛЬКО через PDO, не как текст)
- Таблицы: `seo_daily_stats`, `seo_queries` (колонка `engine` = gsc/yandex), `seo_settings`, `seo_api_log`, `seo_yandex_issues`, `psi_pages`, `psi_results`
- Секреты (GSC-ключ, Яндекс client/токены, PSI-ключ) — в `content/seo/` (закрыт от веба, 403)

## Сделано (SEO-модули панели — всё ЗАЛИТО на прод)
1. Фаза 1 (GSC): `inc/seo-gsc.php`, `seo/index.php`, `seo/setup.php`, `seo/cron-gsc.php`, `assets/chart.umd.js`. Тренды/алерты/ретеншн (Фаза 2).
2. Фаза 2а (Яндекс): `inc/seo-yandex.php`, `seo/setup-yandex.php`, `seo/yandex-callback.php`, `seo/cron-yandex.php`. OAuth подключён, indexed_yandex=24, метрики запросов (`query_indicator=TOTAL_SHOWS/TOTAL_CLICKS/AVG_SHOW_POSITION`).
3. Фаза 2б (Скорость): `inc/seo-psi.php`, `seo/speed.php`, `seo/speed-setup.php`, `seo/cron-psi.php`. 13 страниц, дашборд. Замер: ни одной ≥95 (разброс 79–92).
4. Фаза 2 pSEO (Programmatic SEO, pgen) — генератор в панели (`pgen.php`, `inc/pgen.php`, `seo/cron-pgen.php`), партия units (10 страниц) залита 28.09.2026, cron-pgen отвечает 200 + JSON. 29.09.2026 залит индекс-чек: результат cron-проверки сохраняется в состояние кластера (`content/pgen-clusters.json` → `index_check`) и показывается в панели; `content/pgen-items.json` расширен с 10 до 30 позиций (10 published + 20 черновиков units-2).
- Меню панели: GSC-дашборд + Скорость (группа «Продвижение»). Яндекс без отдельного пункта (по дизайну).

## Фикс 1 (LCP) — ВЫПОЛНЕН И НА ПРОДЕ (сверено по живому HTML 29.09.2026)
- Цель: elementRenderDelay на QR/Главная/CSV с 1.3–2.5 с → <500 мс.
- Диагноз: синхронных скриптов НЕТ (convert-*.js = type=module, бандлы = defer). Тяжёлый в критическом пути — Metrika tag.js (423 мс scripting, audit bootup-time).
- Правка (1 diff × 3 страницы): обернуть инлайн Metrika в `window.addEventListener('load', function(){ ... })`.
- Файлы: `index.html`, `converters/qr-generator/index.html`, `converters/csv-to-xlsx/index.html`.
- Процесс (завершён): бэкап `_backup/2026-09-27-lcp-fix/` → `.Replace` (2 вставки на файл) → локальная проверка (8099, консоль headless-Chrome, H1/формы в DOM) → залито по команде владельца.
- Факт на 29.09.2026: в живом HTML главной (`https://calc-doc.ru/`) инлайн-Метрика обёрнута в `window.addEventListener('load', function(){ … })`, локальная версия и прод совпадают. Прежняя пометка «УТВЕРЖДЁН, НЕ ВЫПОЛНЕН» была устаревшей — снята.
- Ожидаемый эффект: −400–500 мс (уход Metrika из пути отрисовки); гарантии <500 мс нет — если не хватит, след. шаг = оптимизация бандлов/CSS (отдельная задача).

## Следующие задачи (строго по команде владельца)
- Фикс 2 (CLS): резерв высоты под поздние блоки (tool-of-day, отзывы, реклама) — НЕ начинать без команды.
- 2.5 indexed_google (выбрать источник); cron-строки GSC/Яндекс/PSI/pgen в планировщик sweb (владелец). Фаза 3 — не начинать без команды. Активный план недели 2 (units-2, публикация не раньше 12.10) и кластер «Ставка ЦБ» (неделя 3) — в PROGRESS.md, раздел «Активный план».