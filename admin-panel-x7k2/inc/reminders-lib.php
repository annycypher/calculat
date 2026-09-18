<?php
/* inc/reminders-lib.php — движок раздела «Напоминания» (шаг 7.5 задания MASTER-FINAL.md).

   Что здесь есть:
     reminders_items()          — все задачи владельца (файла нет — заводим стартовый набор);
     reminders_groups()         — четыре группы экрана: просрочено / пора / скоро (7 дней) / выполнено;
     reminders_state()          — состояние одной задачи по периоду и дате последнего выполнения;
     reminders_mark_done()      — «сделано» (крупный чекбокс) — проставляет дату;
     reminder_mark_done($id)    — то же по имени задачи: так раздел «Безопасность» сам отмечает
                                  задачу «смена пароля» после смены пароля (см. шаг 7.2);
     reminders_postpone()       — «Отложить на 3 дня»;
     reminders_add()            — своя задача; reminders_delete() — удалить свою;
     reminders_hide() / reminders_show() — скрыть и вернуть стандартную;
     reminders_summary()        — числа для карточек и подпись «ближайшее».

   Как храним: content/reminders.json — {version, items:[…]}, задача:
     id, title, desc, howto, category (security|seo|content|money),
     period (once|weekly|monthly|quarterly|half_year|yearly), month (для сезонных — 1..12),
     own (своя), hidden (скрытая), last_done ('ГГГГ-ММ-ДД' или пусто), postponed_to, created.

   Когда задача «пора»: разовая (once) — пока не выполнена; остальные — last_done + интервал ≤ сегодня.
   Просрочено — срок наступил раньше сегодня; скоро — наступит в ближайшие 7 дней; выполнено — ждём
   следующего срока. Сезонная задача (month) показывается только в свой месяц.
   Файл закрыт .htaccess и не попадает в git (content/reminders.json в .gitignore).
*/

declare(strict_types=1);

/* Прямой заход браузером в этот файл — закрываем (см. пояснение в config.php). */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/** Сколько дней между выполнениями. Для разовой — 0 (срок не повторяется). */
function reminders_interval_days(string $period): int {
    switch ($period) {
        case 'weekly':    return 7;
        case 'monthly':   return 30;
        case 'quarterly': return 91;
        case 'half_year': return 182;
        case 'yearly':    return 365;
        case 'once':      return 0;
    }
    return 0;
}

/** Как называть период по-русски. */
function reminders_period_word(string $period): string {
    switch ($period) {
        case 'weekly':    return 'каждую неделю';
        case 'monthly':   return 'каждый месяц';
        case 'quarterly': return 'раз в квартал';
        case 'half_year': return 'раз в полгода';
        case 'yearly':    return 'раз в год';
        case 'once':      return 'один раз';
    }
    return $period;
}

/** Категории: слово и тон значка. */
function reminders_categories(): array {
    return array(
        'security' => array('word' => 'безопасность', 'tone' => 'vio'),
        'seo'      => array('word' => 'продвижение',  'tone' => 'warn'),
        'content'  => array('word' => 'контент',      'tone' => 'ok'),
        'money'    => array('word' => 'деньги',       'tone' => 'mut'),
    );
}

/** Категория по-русски ('' — неизвестная). */
function reminders_category_word(string $key): string {
    $all = reminders_categories();
    return isset($all[$key]) ? (string)$all[$key]['word'] : $key;
}

/** Файл напоминаний. */
function reminders_file(): string {
    return CONTENT_DIR . '/reminders.json';
}

/** Стартовый набор: 18 обычных задач и 4 сезонные (приходят в свой месяц). */
function reminders_starter(): array {
    return array(
        array('id' => 'login_journal', 'title' => 'Проверить журнал входов', 'category' => 'security', 'period' => 'weekly',
            'desc' => 'Раз в неделю смотрим, не входил ли в панель кто-то чужой.',
            'howto' => 'Раздел «Безопасность» → «Журнал входов»: смотрите строки «провал» и входы с незнакомых устройств. Если что-то насторожило — смените пароль там же, в карточке «Пароль».'),
        array('id' => 'twofa_spaceweb', 'title' => 'Включить двухфакторную защиту на SpaceWeb', 'category' => 'security', 'period' => 'once',
            'desc' => 'Даже с украденным паролем в панель не войдут.',
            'howto' => 'cp.sweb.ru → «Безопасность» → двухфакторная аутентификация: включите и сохраните резервные коды. Задача разовая: сделали — отмечайте.'),
        array('id' => 'passwords_manager', 'title' => 'Перенести пароли в менеджер паролей', 'category' => 'security', 'period' => 'once',
            'desc' => 'Один менеджер вместо паролей «в голове» и на бумажках.',
            'howto' => 'Поставьте менеджер (Bitwarden, 1Password или «менеджер паролей» браузера) и перенесите туда пароли панели, хостинга и почты. Новые пароли пусть придумывает он.'),
        array('id' => 'password_change', 'title' => 'Сменить пароль панели', 'category' => 'security', 'period' => 'half_year',
            'desc' => 'Раз в полгода — свежий длинный пароль. Панель отметит эту задачу сама.',
            'howto' => 'Раздел «Безопасность» → карточка «Пароль»: новый пароль от 12 знаков. После смены задача отметится автоматически, вручную галочку ставить не нужно.'),
        array('id' => 'php_version', 'title' => 'Проверить версию PHP', 'category' => 'security', 'period' => 'yearly',
            'desc' => 'Старая версия PHP — слабое место хостинга.',
            'howto' => 'SpaceWeb → раздел хостинга → версия PHP: посмотрите, поддерживается ли она ещё, и переключитесь на актуальную (8.2–8.4). После переключения откройте панель и сайт — всё должно работать как обычно.'),
        array('id' => 'domain_deadline', 'title' => 'Продлить домен calc-doc.ru', 'category' => 'money', 'period' => 'yearly',
            'desc' => 'Забыть про домен — потерять сайт и позиции.',
            'howto' => 'Панель регистратора (SpaceWeb): проверьте дату окончания домена и включите авто-продление. Запишите дату в задачу, чтобы не гадать.'),
        array('id' => 'seo_scan', 'title' => 'Прогнать SEO-скан', 'category' => 'seo', 'period' => 'weekly',
            'desc' => 'Панель оценивает страницы по критериям поиска — так видно, что подтянуть.',
            'howto' => 'Раздел «SEO-центр» → «Просканировать сайт». Смотрите страницы с оценкой ниже остальных: обычно дело в заголовке, вводном абзаце или в FAQ.'),
        array('id' => 'orphans_pages', 'title' => 'Сироты и слабые страницы', 'category' => 'seo', 'period' => 'weekly',
            'desc' => 'Страницы, о которых знают только меню и карта сайта, плохо растут в поиске.',
            'howto' => 'Раздел «Перелинковка» → «Просканировать сайт» → сироты (0–1 ссылка) и слабые (2–3). Добавьте упоминание в подходящую статью — панель предложит варианты.'),
        array('id' => 'traffic_no_money', 'title' => 'Посмотреть «трафик без денег»', 'category' => 'seo', 'period' => 'weekly',
            'desc' => 'Страницы, которые читают, но по рекламе с них нет кликов.',
            'howto' => 'Раздел «Аналитика» → блок «Трафик без денег»: страницы с просмотрами и без переходов по рекламе. Решение — убрать рекламу с этих страниц или добавить свой полезный блок.'),
        array('id' => 'reviews_moderation', 'title' => 'Проверить отзывы на модерации', 'category' => 'content', 'period' => 'weekly',
            'desc' => 'Отзыв, который ждёт неделю, — потерянный отзыв.',
            'howto' => 'Раздел «Отзывы» → очередь модерации: одобрите нормальные, отклоните спам. Чёрный список слов живёт в «Настройках».'),
        array('id' => 'backlinks_webmaster', 'title' => 'Перенести новые бэклинки из Вебмастера', 'category' => 'seo', 'period' => 'monthly',
            'desc' => 'Внешние ссылки на сайт — топливо для продвижения.',
            'howto' => 'Яндекс.Вебмастер → «Ссылки на сайт»: новые ссылки добавьте в раздел «Бэклинки» панели, чтобы видеть их рост и статус.'),
        array('id' => 'outreach_letters', 'title' => 'Аутрич: написать 2–3 письма', 'category' => 'seo', 'period' => 'monthly',
            'desc' => 'Ссылки не появляются сами — о сайте нужно рассказать.',
            'howto' => 'Раздел «Аутрич»: возьмите карточки без движения и напишите 2–3 письма. Шаблоны формулировок есть в самой карточке.'),
        array('id' => 'positions', 'title' => 'Проверить позиции запросов', 'category' => 'seo', 'period' => 'monthly',
            'desc' => 'Раз в месяц смотрим, что растёт, а что просело.',
            'howto' => 'Яндекс.Вебмастер → «Поисковые запросы»: выпишите 5–10 запросов с показами и сравните с прошлым месяцем. Просевшие темы — кандидаты на обновление статей.'),
        array('id' => 'article_update', 'title' => 'Опубликовать или заметно обновить статью', 'category' => 'content', 'period' => 'monthly',
            'desc' => 'Одна хорошая статья в месяц — и сайт растёт.',
            'howto' => 'Раздел «Статьи»: либо новая статья по частому вопросу, либо обновление старой (новые цифры, примеры, ответы на вопросы из отзывов).'),
        array('id' => 'calc_numbers', 'title' => 'Сверить цифры на страницах калькуляторов', 'category' => 'content', 'period' => 'quarterly',
            'desc' => 'Ставки, лимиты и проценты меняются — неверная цифра бьёт по доверию.',
            'howto' => 'Пройдите по популярным калькуляторам и статьям: страховые взносы, ключевая ставка, лимиты вычета, МРОТ. Обновляйте не только числа, но и примеры расчётов.'),
        array('id' => 'broken_links_check', 'title' => 'Проверить битые ссылки вручную', 'category' => 'seo', 'period' => 'quarterly',
            'desc' => 'Панель ищет битые адреса внутри сайта; внешние ссылки стоит проверять глазами.',
            'howto' => 'Раздел «Перелинковка» → битые адреса → почините. Затем пройдите по внешним ссылкам в статьях (например на сайты ведомств): страницы переезжают и адреса меняются.'),
        array('id' => 'rsa_income', 'title' => 'Посмотреть доход РСЯ', 'category' => 'money', 'period' => 'monthly',
            'desc' => 'Раз в месяц — цифра дохода и вывод.',
            'howto' => 'Яндекс.Реклама → отчёты по площадке: запишите доход за месяц и сравните с прошлым. Если доход падает, проверьте «трафик без денег» и настройки блоков в разделе «Рекламные блоки».'),
        array('id' => 'original_texts', 'title' => 'Отправить «Оригинальные тексты» в Яндекс', 'category' => 'seo', 'period' => 'once',
            'desc' => 'Помогает поиску быстрее понять, что тексты ваши.',
            'howto' => 'Яндекс.Вебмастер → «Оригинальные тексты»: отправьте свои статьи и описания калькуляторов. Делается один раз, потом добавляйте новые тексты по мере выхода.'),
        array('id' => 'season_deductions', 'title' => 'Сезонная статья про налоговые вычеты', 'category' => 'content', 'period' => 'yearly', 'month' => 12,
            'desc' => 'В декабре люди планируют вычеты на будущий год.',
            'howto' => 'Обновите статьи о вычетах: сроки подачи, лимиты, примеры. Напомните читателю, какие документы собрать заранее.'),
        array('id' => 'season_otpusknye', 'title' => 'Обновить статью про отпускные (к майским)', 'category' => 'content', 'period' => 'yearly', 'month' => 4,
            'desc' => 'Перед майскими праздниками спрос на расчёт отпускных растёт.',
            'howto' => 'Проверьте статью и калькулятор отпускных: средний заработок, премии, индексация. Добавьте пример с праздничными днями.'),
        array('id' => 'season_education', 'title' => 'Статья: вычет за обучение', 'category' => 'content', 'period' => 'yearly', 'month' => 9,
            'desc' => 'В сентябре начинается учебный год — тема снова в спросе.',
            'howto' => 'Обновите статью о вычете за обучение: лимиты, оплата за детей и за себя, очная форма. Проверьте формулировки о сроках возврата.'),
        array('id' => 'season_cb_rate', 'title' => 'Сверить ключевую ставку и вклады', 'category' => 'content', 'period' => 'yearly', 'month' => 1,
            'desc' => 'В январе банки пересматривают ставки — цифры на страницах устаревают.',
            'howto' => 'Проверьте ключевую ставку ЦБ и ставки в калькуляторах вкладов и кредитов: обновите цифры и дату актуальности на страницах.'),
    );
}

/* ───────────── чтение, запись, стартовый набор ───────────── */

/** Привести задачу к известному виду: файл и стартовый набор читаются одинаково. */
function reminders_normalize(array $t): array {
    $period = (string)($t['period'] ?? 'once');
    if (!in_array($period, array('once', 'weekly', 'monthly', 'quarterly', 'half_year', 'yearly'), true)) { $period = 'once'; }
    $cat = (string)($t['category'] ?? 'content');
    if (!isset(reminders_categories()[$cat])) { $cat = 'content'; }
    return array(
        'id'           => (string)($t['id'] ?? ''),
        'title'        => (string)($t['title'] ?? ''),
        'desc'         => (string)($t['desc'] ?? ''),
        'howto'        => (string)($t['howto'] ?? ''),
        'category'     => $cat,
        'period'       => $period,
        'month'        => (int)($t['month'] ?? 0),
        'own'          => !empty($t['own']),
        'hidden'       => !empty($t['hidden']),
        'last_done'    => (string)($t['last_done'] ?? ''),
        'postponed_to' => (string)($t['postponed_to'] ?? ''),
        'created'      => (string)($t['created'] ?? ''),
    );
}

function reminders_save(array $items): bool {
    $out = array();
    foreach ($items as $t) { $out[] = reminders_normalize((array)$t); }
    return json_write(reminders_file(), array('version' => 1, 'items' => $out));
}

/** Все задачи. Файла нет или он пуст — заводим стартовый набор и сохраняем. */
function reminders_items(): array {
    $data  = json_read(reminders_file(), array());
    $items = (isset($data['items']) && is_array($data['items'])) ? array_values($data['items']) : array();
    if (count($items) === 0) {
        foreach (reminders_starter() as $t) {
            $items[] = reminders_normalize($t + array('own' => false, 'hidden' => false,
                'last_done' => '', 'postponed_to' => '', 'created' => date('Y-m-d')));
        }
        reminders_save($items);
    }
    return $items;
}

/** Задача по id (null — такой нет). */
function reminders_find(string $id): ?array {
    foreach (reminders_items() as $t) { if ((string)$t['id'] === $id) { return $t; } }
    return null;
}

/* ───────────── когда что делать ───────────── */

/** Дата, когда задача снова нужна ('ГГГГ-ММ-ДД'); '' — разовая и уже сделана.
    $today можно подставить — нужно тестам. */
function reminders_due_at(array $t, ?string $today = null): string {
    $today = $today !== null ? $today : date('Y-m-d');
    $last  = (string)$t['last_done'];

    if ((string)$t['period'] === 'once') {
        if ($last !== '') { return ''; }
        $post = (string)$t['postponed_to'];
        return ($post !== '' && $post > $today) ? $post : $today;
    }

    $base = $last !== '' ? $last : ((string)$t['created'] !== '' ? (string)$t['created'] : $today);
    /* Свою задачу владелец завёл, чтобы сделать её: если она ещё ни разу не выполнена — сразу «пора». */
    $due = ($last === '' && !empty($t['own']))
        ? $today
        : date('Y-m-d', (int)strtotime($base) + reminders_interval_days((string)$t['period']) * 86400);
    $post = (string)$t['postponed_to'];
    if ($post !== '' && $post > $due) { $due = $post; }        // «Отложить» сдвигает срок
    return $due;
}

/** Состояние задачи: overdue (просрочено) | due (пора) | soon (скоро) | done (выполнено) | hidden. */
function reminders_state(array $t, ?string $today = null): string {
    $today = $today !== null ? $today : date('Y-m-d');
    if (!empty($t['hidden'])) { return 'hidden'; }

    $last  = (string)$t['last_done'];
    $month = (int)($t['month'] ?? 0);

    /* Сезонные задачи («статья про вычеты» — декабрь): ждём свой месяц и не чаще раза в год. */
    if ($month > 0) {
        if ((int)date('n', strtotime($today)) !== $month) { return 'done'; }
        $yearStart = date('Y', strtotime($today)) . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '-01';
        return ($last === '' || $last < $yearStart) ? 'due' : 'done';
    }

    if ((string)$t['period'] === 'once') {
        if ($last !== '') { return 'done'; }
        return reminders_due_at($t, $today) > $today ? 'soon' : 'due';   // отложенную разовую не красим красным
    }

    $due = reminders_due_at($t, $today);
    if ($due === '') { return 'done'; }
    if ($due < $today) { return 'overdue'; }
    if ($due === $today) { return 'due'; }
    /* «Скоро» — срок в ближайшие дни, но не весь интервал: недельную задачу, сделанную сегодня,
       не показываем в «Скоро» (иначе она там висит сразу после галочки). */
    return ($due <= date('Y-m-d', (int)strtotime($today) + 6 * 86400)) ? 'soon' : 'done';
}

/** Четыре группы экрана плюс скрытые. Просроченные и «пора» — по возрастанию срока. */
function reminders_groups(?array $items = null, ?string $today = null): array {
    $items = $items !== null ? $items : reminders_items();
    $today = $today !== null ? $today : date('Y-m-d');
    $g = array('overdue' => array(), 'due' => array(), 'soon' => array(), 'done' => array(), 'hidden' => array());
    foreach ($items as $t) {
        $t['state']  = reminders_state($t, $today);
        $t['due_at'] = reminders_due_at($t, $today);
        $g[(string)$t['state']][] = $t;
    }
    $byDue = function ($a, $b) { return strcmp((string)$a['due_at'], (string)$b['due_at']); };
    usort($g['overdue'], $byDue);
    usort($g['due'], $byDue);
    usort($g['soon'], $byDue);
    usort($g['done'], function ($a, $b) { return strcmp((string)$b['last_done'], (string)$a['last_done']); });
    return $g;
}

/** Числа для карточек и подпись «ближайшее». */
function reminders_summary(?array $items = null, ?string $today = null): array {
    $g    = reminders_groups($items, $today);
    $next = '';
    foreach (array('due', 'soon') as $k) {
        if (count($g[$k]) > 0) { $next = (string)$g[$k][0]['title'] . ' — ' . (string)$g[$k][0]['due_at']; break; }
    }
    if ($next === '') {
        foreach ($g['done'] as $t) {
            if ((string)$t['due_at'] !== '') { $next = (string)$t['title'] . ' — ' . (string)$t['due_at']; break; }
        }
    }
    return array(
        'overdue' => count($g['overdue']), 'due' => count($g['due']), 'soon' => count($g['soon']),
        'done' => count($g['done']), 'hidden' => count($g['hidden']),
        'total' => count($g['overdue']) + count($g['due']) + count($g['soon']) + count($g['done']) + count($g['hidden']),
        'next' => $next,
    );
}

/* ───────────── что делает владелец ───────────── */

/** «Сделано»: ставим дату, снимаем отложенность. */
function reminders_mark_done(string $id, string $date = ''): bool {
    $items = reminders_items();
    $done  = false;
    foreach ($items as $i => $t) {
        if ((string)$t['id'] !== $id) { continue; }
        $items[$i]['last_done']    = $date !== '' ? $date : date('Y-m-d');
        $items[$i]['postponed_to'] = '';
        $done = true;
    }
    if (!$done || !reminders_save($items)) { return false; }
    log_action('Напоминание выполнено', $id, '');
    return true;
}

/** Отметка по имени задачи из других разделов: так «Безопасность» сама отмечает «смену пароля» (шаг 7.2). */
function reminder_mark_done(string $id, string $date = ''): bool {
    if (reminders_find($id) === null) { return false; }
    return reminders_mark_done($id, $date);
}

/** «Отложить на N дней»: задача не спрашивает раньше, чем через N дней. */
function reminders_postpone(string $id, int $days = 3): bool {
    $items = reminders_items();
    $done  = false;
    $today = date('Y-m-d');
    foreach ($items as $i => $t) {
        if ((string)$t['id'] !== $id) { continue; }
        $items[$i]['postponed_to'] = date('Y-m-d', (int)strtotime($today) + max(1, $days) * 86400);
        $done = true;
    }
    if (!$done || !reminders_save($items)) { return false; }
    log_action('Напоминание отложено', $id . ' — на ' . max(1, $days) . ' дн.', '');
    return true;
}

/** Своя задача. Возвращает id ('' — пустое название или не получилось сохранить). */
function reminders_add(string $title, string $period, string $category, string $desc = '', string $howto = ''): string {
    $title = trim($title);
    if ($title === '' || mb_strlen($title) > 120) { return ''; }
    if (!in_array($period, array('once', 'weekly', 'monthly', 'quarterly', 'half_year', 'yearly'), true)) { $period = 'once'; }
    if (!isset(reminders_categories()[$category])) { $category = 'content'; }

    $items = reminders_items();
    $base  = 'my-' . slugify($title, 24, 'task');
    $id    = $base;
    $n     = 2;
    while (reminders_find($id) !== null) { $id = $base . '-' . $n; $n++; }

    $items[] = array('id' => $id, 'title' => $title, 'desc' => mb_substr(trim($desc), 0, 300),
        'howto' => mb_substr(trim($howto), 0, 600), 'category' => $category, 'period' => $period,
        'month' => 0, 'own' => true, 'hidden' => false, 'last_done' => '', 'postponed_to' => '',
        'created' => date('Y-m-d'));
    if (!reminders_save($items)) { return ''; }
    log_action('Своё напоминание добавлено', $title, '');
    return $id;
}

/** Удалить свою задачу. Стандартные не удаляем — их можно только скрыть. */
function reminders_delete(string $id): bool {
    $items = reminders_items();
    $out   = array();
    $found = false;
    foreach ($items as $t) {
        if ((string)$t['id'] === $id) {
            if (empty($t['own'])) { return false; }
            $found = true;
            continue;
        }
        $out[] = $t;
    }
    if (!$found || !reminders_save($out)) { return false; }
    log_action('Своё напоминание удалено', $id, '');
    return true;
}

/** Скрыть стандартную задачу или вернуть её обратно. */
function reminders_hide(string $id, bool $hidden = true): bool {
    $items = reminders_items();
    $found = false;
    foreach ($items as $i => $t) {
        if ((string)$t['id'] !== $id) { continue; }
        $items[$i]['hidden'] = $hidden;
        $found = true;
    }
    if (!$found || !reminders_save($items)) { return false; }
    log_action($hidden ? 'Напоминание скрыто' : 'Напоминание возвращено', $id, '');
    return true;
}

/** Дата по-русски: 18.09.2026 ('' → «—»). */
function reminders_date_ru(string $date): string {
    $ts = strtotime($date);
    return ($date === '' || $ts === false) ? '—' : date('d.m.Y', $ts);
}

/** Слово и тон для состояния задачи. */
function reminders_state_word(string $state): array {
    switch ($state) {
        case 'overdue': return array('word' => 'просрочено', 'tone' => 'err');
        case 'due':     return array('word' => 'пора',       'tone' => 'warn');
        case 'soon':    return array('word' => 'скоро',      'tone' => 'mut');
        case 'hidden':  return array('word' => 'скрыто',     'tone' => 'mut');
    }
    return array('word' => 'выполнено', 'tone' => 'ok');
}
