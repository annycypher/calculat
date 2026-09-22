# HTTPS на sweb: как включено и что важно знать

## Итог (12.09.2026)

- Сертификат: **Let's Encrypt**, выдающий центр `YR1`, домен `CN=calc-doc.ru`,
  покрывает `calc-doc.ru` и `*.calc-doc.ru` (то есть www тоже), действует до **11.12.2026**.
- Работает по обоим именам: `https://calc-doc.ru/` и `https://www.calc-doc.ru/` (200, проверка сертификата проходит).
- Канонический адрес — `https://calc-doc.ru/`. Любая комбинация `http/https` + `с www/без`
  переходит на него **за один редирект** (проверено, петель нет).
- Внутренние ссылки на сайте относительные, поэтому переход на HTTPS ничего не сломал.

## ⚠️ Главная ловушка sweb: `%{HTTPS}` в .htaccess не работает

На sweb **SSL завершается на nginx**, а Apache получает уже обычный http-запрос.
Поэтому в `.htaccess`:

- `%{HTTPS}` **не выставляется вообще** (проверено тестовым php-скриптом: переменной нет);
- `SERVER_PORT` всегда `80`;
- корректный признак TLS — заголовок **`X-Forwarded-Proto`** (`http` или `https`).

Попытка сделать редирект «по учебнику» (`RewriteCond %{HTTPS} !=on`) приводит к
**бесконечному циклу**: правило срабатывает и на https-запросе, перенаправляя адрес сам на себя
(`https://calc-doc.ru/ → 301 → https://calc-doc.ru/`). Такой случай уже был и исправлен.

## Правильные правила (сейчас в `.htaccess`)

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On

  # http -> https, сразу к каноническому хосту без www (один переход вместо двух)
  RewriteCond %{HTTP:X-Forwarded-Proto} !^https$ [NC]
  RewriteCond %{REQUEST_URI} !^/\.well-known/ [NC]
  RewriteRule ^(.*)$ https://calc-doc.ru/$1 [R=301,L]

  # www -> без www
  RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]
  RewriteCond %{REQUEST_URI} !^/\.well-known/ [NC]
  RewriteRule ^(.*)$ https://calc-doc.ru/$1 [R=301,L]
</IfModule>
```

Канонический хост указан явно (а не через `%{HTTP_HOST}`) — это защищает от подмены
заголовка `Host` и исключает лишний переход.

Исключение `/.well-known/` оставлено, чтобы проверка сертификата никогда не попадала под редирект.

## Как проверить состояние

```powershell
.\sweb-migration\enable-https.ps1            # сертификаты + редиректы + отсутствие петли
.\sweb-migration\enable-https.ps1 -Apply     # залить .htaccess (если сертификат выпущен)
.\sweb-migration\enable-https.ps1 -DeleteProbe
```

## Что дальше

1. **HSTS** — включать после суток стабильного HTTPS. Рекомендуется сначала мягкий режим:
   `Header always set Strict-Transport-Security "max-age=600"`, через неделю —
   `max-age=31536000; includeSubDomains`. Значение на год сразу опасно: если что-то
   сломается, браузеры будут требовать https на весь срок.
2. Автопродление сертификата выполняет sweb; раз в несколько месяцев полезно смотреть
   срок в панели (текущий — до 11.12.2026).
3. После перехода на HTTPS можно открыть сайт для поисковиков (`robots.txt`).
