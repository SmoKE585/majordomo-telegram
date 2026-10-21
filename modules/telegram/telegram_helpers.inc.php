<?php
/**
 * Display and configuration helpers for the telegram module.
 *
 * Only formatting, metadata and small utilities live here; update processing
 * and API calls are in telegram.class.php / TelegramApi.php.
 */

if (!function_exists('md_tlg_h')) {
    /**
     * Escape a value for HTML output.
     *
     * @param mixed $value
     * @return string
     */
    function md_tlg_h($value)
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('md_tlg_plural')) {
    /**
     * @param int    $number
     * @param string $one
     * @param string $few
     * @param string $many
     * @return string
     */
    function md_tlg_plural($number, $one, $few, $many)
    {
        $number = abs((int)$number);
        $mod10 = $number % 10;
        $mod100 = $number % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            return $few;
        }
        return $many;
    }
}

if (!function_exists('md_tlg_relative_time')) {
    /**
     * @param string $datetime
     * @param bool   $future
     * @return string
     */
    function md_tlg_relative_time($datetime, $future = false)
    {
        $datetime = trim((string)$datetime);
        if ($datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return '—';
        }
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return $datetime;
        }

        $diff = $timestamp - time();
        if ($future) {
            if ($diff <= 0) {
                return 'сейчас';
            }
            $abs = $diff;
        } else {
            if ($diff >= 0) {
                return 'только что';
            }
            $abs = -$diff;
        }

        if ($abs < 604800) {
            if ($abs < 60) {
                $value = max(1, (int)$abs);
                $unit = md_tlg_plural($value, 'секунду', 'секунды', 'секунд');
            } elseif ($abs < 3600) {
                $value = (int)floor($abs / 60);
                $unit = md_tlg_plural($value, 'минуту', 'минуты', 'минут');
            } elseif ($abs < 86400) {
                $value = (int)floor($abs / 3600);
                $unit = md_tlg_plural($value, 'час', 'часа', 'часов');
            } else {
                $value = (int)floor($abs / 86400);
                $unit = md_tlg_plural($value, 'день', 'дня', 'дней');
            }
            $label = $value . ' ' . $unit;
            return $future ? 'через ' . $label : $label . ' назад';
        }

        return date('d.m.Y H:i', $timestamp);
    }
}

if (!function_exists('md_tlg_mask_token')) {
    /**
     * Show a token without leaking the secret part.
     *
     * @param string $token
     * @return string
     */
    function md_tlg_mask_token($token)
    {
        $token = trim((string)$token);
        if ($token === '') {
            return 'не задан';
        }
        if (strlen($token) <= 12) {
            return str_repeat('•', strlen($token));
        }
        $id = '';
        $secret = $token;
        if (strpos($token, ':') !== false) {
            list($id, $secret) = explode(':', $token, 2);
            $id .= ':';
        }
        return $id . substr($secret, 0, 4) . str_repeat('•', 10) . substr($secret, -4);
    }
}

if (!function_exists('md_tlg_token_is_valid')) {
    /**
     * Basic BotFather token shape check.
     *
     * @param string $token
     * @return bool
     */
    function md_tlg_token_is_valid($token)
    {
        return (bool)preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', trim((string)$token));
    }
}

if (!function_exists('md_tlg_event_types')) {
    /**
     * Event types available in the module.
     *
     * @return array<int,array<string,string>>
     */
    function md_tlg_event_types()
    {
        return array(
            1 => array(
                'label' => 'Текстовое сообщение',
                'short' => 'Текст',
                'icon' => 'message',
                'tone' => 'primary',
                'description' => 'Обычное текстовое сообщение в личном чате, группе или канале.',
                'vars' => '$text, $chat_id, $username, $fullname, $message, $user',
            ),
            2 => array(
                'label' => 'Фото',
                'short' => 'Фото',
                'icon' => 'photo',
                'tone' => 'info',
                'description' => 'Фотография. При включённой загрузке файл сохраняется в хранилище.',
                'vars' => '$file_id, $file_path, $caption, $chat_id',
            ),
            3 => array(
                'label' => 'Голосовое сообщение',
                'short' => 'Голос',
                'icon' => 'microphone',
                'tone' => 'warning',
                'description' => 'Голосовое сообщение (voice).',
                'vars' => '$file_id, $file_path, $chat_id',
            ),
            4 => array(
                'label' => 'Аудио',
                'short' => 'Аудио',
                'icon' => 'music',
                'tone' => 'warning',
                'description' => 'Аудиофайл с метаданными (исполнитель, название).',
                'vars' => '$file_id, $file_path, $audio, $chat_id',
            ),
            5 => array(
                'label' => 'Видео',
                'short' => 'Видео',
                'icon' => 'video',
                'tone' => 'info',
                'description' => 'Видеофайл или видеосообщение.',
                'vars' => '$file_id, $file_path, $caption, $chat_id',
            ),
            6 => array(
                'label' => 'Документ',
                'short' => 'Файл',
                'icon' => 'file',
                'tone' => 'neutral',
                'description' => 'Документ или произвольный файл.',
                'vars' => '$file_id, $file_path, $file_name, $chat_id',
            ),
            7 => array(
                'label' => 'Стикер',
                'short' => 'Стикер',
                'icon' => 'mood-smile',
                'tone' => 'warning',
                'description' => 'Стикер; доступен set_name и emoji.',
                'vars' => '$file_id, $file_path, $sticker, $chat_id',
            ),
            8 => array(
                'label' => 'Геолокация',
                'short' => 'Гео',
                'icon' => 'map-pin',
                'tone' => 'success',
                'description' => 'Точка на карте или live-локация.',
                'vars' => '$latitude, $longitude, $accuracy, $chat_id',
            ),
            9 => array(
                'label' => 'Нажатие inline-кнопки',
                'short' => 'Callback',
                'icon' => 'hand-click',
                'tone' => 'primary',
                'description' => 'Пользователь нажал кнопку под сообщением бота.',
                'vars' => '$callback_id, $callback_data, $message_id, $chat_id',
            ),
            10 => array(
                'label' => 'Ответ на опрос',
                'short' => 'Опрос',
                'icon' => 'chart-bar',
                'tone' => 'info',
                'description' => 'Пользователь проголосовал в опросе, отправленном ботом.',
                'vars' => '$poll_id, $option_ids, $chat_id, $user',
            ),
            11 => array(
                'label' => 'Видеосообщение',
                'short' => 'Кружок',
                'icon' => 'video',
                'tone' => 'info',
                'description' => 'Круглое видеосообщение (video note).',
                'vars' => '$file_id, $file_path, $chat_id',
            ),
            12 => array(
                'label' => 'Контакт',
                'short' => 'Контакт',
                'icon' => 'address-book',
                'tone' => 'neutral',
                'description' => 'Пользователь поделился контактом.',
                'vars' => '$contact, $chat_id',
            ),
            13 => array(
                'label' => 'Место',
                'short' => 'Место',
                'icon' => 'building-store',
                'tone' => 'success',
                'description' => 'Место с названием и адресом (venue).',
                'vars' => '$venue, $latitude, $longitude, $chat_id',
            ),
            14 => array(
                'label' => 'Dice / игра',
                'short' => 'Dice',
                'icon' => 'dice',
                'tone' => 'warning',
                'description' => 'Анимированный эмодзи-кубик или игра.',
                'vars' => '$dice, $chat_id',
            ),
            15 => array(
                'label' => 'Анимация / GIF',
                'short' => 'GIF',
                'icon' => 'photo',
                'tone' => 'info',
                'description' => 'Анимация (GIF или беззвучное видео).',
                'vars' => '$file_id, $file_path, $chat_id',
            ),
            16 => array(
                'label' => 'Изменённое сообщение',
                'short' => 'Правка',
                'icon' => 'pencil',
                'tone' => 'neutral',
                'description' => 'Пользователь отредактировал сообщение.',
                'vars' => '$text, $message_id, $chat_id',
            ),
            17 => array(
                'label' => 'Пост в канале',
                'short' => 'Канал',
                'icon' => 'broadcast',
                'tone' => 'primary',
                'description' => 'Новый пост в канале, где бот администратор.',
                'vars' => '$text, $message, $chat_id',
            ),
            18 => array(
                'label' => 'Участники и сервисные события',
                'short' => 'Участники',
                'icon' => 'users',
                'tone' => 'neutral',
                'description' => 'Вход/выход участников, заявки на вступление, смена статуса бота.',
                'vars' => '$chat_member, $chat_join_request, $chat_id, $user',
            ),
            19 => array(
                'label' => 'Реакция на сообщение',
                'short' => 'Реакция',
                'icon' => 'heart',
                'tone' => 'warning',
                'description' => 'Пользователь поставил или снял реакцию.',
                'vars' => '$message_reaction, $message_id, $chat_id, $user',
            ),
            20 => array(
                'label' => 'Inline-запрос',
                'short' => 'Inline',
                'icon' => 'search',
                'tone' => 'info',
                'description' => 'Запрос через @bot в строке ввода любого чата.',
                'vars' => '$inline_query_id, $query, $user_id',
            ),
            21 => array(
                'label' => 'Business-сообщение',
                'short' => 'Business',
                'icon' => 'briefcase',
                'tone' => 'primary',
                'description' => 'Сообщение в business-чате (Telegram Business).',
                'vars' => '$text, $business_connection_id, $chat_id',
            ),
            22 => array(
                'label' => 'Платежи',
                'short' => 'Платежи',
                'icon' => 'credit-card',
                'tone' => 'success',
                'description' => 'Pre-checkout, доставка и успешная оплата.',
                'vars' => '$pre_checkout_query, $successful_payment, $chat_id',
            ),
        );
    }
}

if (!function_exists('md_tlg_event_type_meta')) {
    /**
     * @param int $type
     * @return array<string,string>
     */
    function md_tlg_event_type_meta($type)
    {
        $types = md_tlg_event_types();
        $type = (int)$type;
        if (isset($types[$type])) {
            return $types[$type];
        }
        return array(
            'label' => 'Неизвестный тип (' . $type . ')',
            'short' => '—',
            'icon' => 'question-mark',
            'tone' => 'neutral',
            'description' => '',
            'vars' => '',
        );
    }
}

if (!function_exists('md_tlg_direction_meta')) {
    /**
     * @param int $direction
     * @return array<string,string>
     */
    function md_tlg_direction_meta($direction)
    {
        switch ((int)$direction) {
            case 0:
                return array('label' => 'Входящее', 'tone' => 'info', 'icon' => 'arrow-down-left', 'state' => 'idle');
            case 1:
                return array('label' => 'Отправлено', 'tone' => 'success', 'icon' => 'arrow-up-right', 'state' => 'online');
            case 2:
                return array('label' => 'Ошибка', 'tone' => 'danger', 'icon' => 'alert-circle', 'state' => 'error');
            case 3:
                return array('label' => 'Повтор', 'tone' => 'warning', 'icon' => 'refresh', 'state' => 'warning');
            case 4:
                return array('label' => 'Пропущено', 'tone' => 'neutral', 'icon' => 'minus', 'state' => 'offline');
            case 5:
                return array('label' => 'Сбой цикла', 'tone' => 'danger', 'icon' => 'alert-octagon', 'state' => 'error');
            default:
                return array('label' => '—', 'tone' => 'neutral', 'icon' => 'help', 'state' => 'idle');
        }
    }
}

if (!function_exists('md_tlg_message_type_meta')) {
    /**
     * @param int $type
     * @return array<string,string>
     */
    function md_tlg_message_type_meta($type)
    {
        switch ((int)$type) {
            case 1:
                return array('label' => 'Текст', 'icon' => 'message');
            case 2:
                return array('label' => 'Фото', 'icon' => 'photo');
            case 3:
                return array('label' => 'Голос', 'icon' => 'microphone');
            case 4:
                return array('label' => 'Аудио', 'icon' => 'music');
            case 5:
                return array('label' => 'Видео', 'icon' => 'video');
            case 6:
                return array('label' => 'Документ', 'icon' => 'file');
            case 7:
                return array('label' => 'Стикер', 'icon' => 'mood-smile');
            case 8:
                return array('label' => 'Гео', 'icon' => 'map-pin');
            case 10:
                return array('label' => 'Callback', 'icon' => 'hand-click');
            case 11:
                return array('label' => 'Кружок', 'icon' => 'video');
            case 12:
                return array('label' => 'Контакт', 'icon' => 'address-book');
            case 13:
                return array('label' => 'Место', 'icon' => 'building-store');
            case 14:
                return array('label' => 'Dice', 'icon' => 'dice');
            case 15:
                return array('label' => 'GIF', 'icon' => 'photo');
            case 16:
                return array('label' => 'Правка', 'icon' => 'pencil');
            case 17:
                return array('label' => 'Канал', 'icon' => 'broadcast');
            case 18:
                return array('label' => 'Участники', 'icon' => 'users');
            case 19:
                return array('label' => 'Реакция', 'icon' => 'heart');
            case 20:
                return array('label' => 'Inline', 'icon' => 'search');
            case 21:
                return array('label' => 'Business', 'icon' => 'briefcase');
            default:
                return array('label' => 'Событие', 'icon' => 'bolt');
        }
    }
}

if (!function_exists('md_tlg_transport_options')) {
    /**
     * Connection modes shown on the settings page.
     *
     * @return array<string,array<string,string>>
     */
    function md_tlg_transport_options()
    {
        return array(
            'direct' => array(
                'label' => 'Напрямую',
                'icon' => 'world',
                'description' => 'Запросы идут напрямую к api.telegram.org.',
                'hint' => 'Подходит, если сервер имеет прямой доступ к Telegram.',
            ),
            'http' => array(
                'label' => 'HTTP-прокси',
                'icon' => 'route',
                'description' => 'Обычный HTTP-прокси (CONNECT).',
                'hint' => 'Например, корпоративный прокси или privoxy: 10.0.0.5:3128.',
            ),
            'https' => array(
                'label' => 'HTTPS-прокси',
                'icon' => 'shield-lock',
                'description' => 'Прокси с TLS до самого прокси-сервера.',
                'hint' => 'Нужен, если прокси принимает только HTTPS-соединения.',
            ),
            'socks5' => array(
                'label' => 'SOCKS5',
                'icon' => 'plug',
                'description' => 'SOCKS5-прокси, DNS решает локально.',
                'hint' => 'Типовой вариант для Tor (127.0.0.1:9050) или dante.',
            ),
            'socks5h' => array(
                'label' => 'SOCKS5 + remote DNS',
                'icon' => 'plug-connected',
                'description' => 'DNS-имена резолвит прокси, а не сервер MajorDoMo.',
                'hint' => 'Надёжнее при блокировке DNS: api.telegram.org резолвится на стороне прокси.',
            ),
            'transparent' => array(
                'label' => 'Прозрачный прокси / VPN',
                'icon' => 'arrows-shuffle',
                'description' => 'cURL не использует прокси, маршрут задаёт система.',
                'hint' => 'nginx stream + SNI-роутинг, redsocks, tun2socks, корпоративный VPN или маршрут через шлюз.',
            ),
            'local_api' => array(
                'label' => 'Локальный Bot API (MTProto)',
                'icon' => 'server-2',
                'description' => 'Свой сервер telegram-bot-api рядом с MajorDoMo.',
                'hint' => 'Самый устойчивый обход блокировок: локальный сервер общается с Telegram по MTProto.',
            ),
        );
    }
}

if (!function_exists('md_tlg_allowed_updates')) {
    /**
     * Update types that can be subscribed to.
     *
     * @return array<string,string>
     */
    function md_tlg_allowed_updates()
    {
        return array(
            'message' => 'Сообщения',
            'edited_message' => 'Изменённые сообщения',
            'channel_post' => 'Посты в каналах',
            'edited_channel_post' => 'Изменённые посты',
            'business_connection' => 'Business-подключения',
            'business_message' => 'Business-сообщения',
            'edited_business_message' => 'Изменённые business-сообщения',
            'deleted_business_messages' => 'Удалённые business-сообщения',
            'message_reaction' => 'Реакции',
            'message_reaction_count' => 'Счётчики реакций',
            'inline_query' => 'Inline-запросы',
            'chosen_inline_result' => 'Выбор inline-результата',
            'callback_query' => 'Callback-кнопки',
            'shipping_query' => 'Доставка',
            'pre_checkout_query' => 'Pre-checkout',
            'purchased_paid_media' => 'Покупка медиа',
            'poll' => 'Опросы',
            'poll_answer' => 'Ответы на опросы',
            'my_chat_member' => 'Статус бота в чате',
            'chat_member' => 'Участники чата',
            'chat_join_request' => 'Заявки на вступление',
            'chat_boost' => 'Бусты чата',
            'removed_chat_boost' => 'Снятые бусты',
        );
    }
}

if (!function_exists('md_tlg_default_config')) {
    /**
     * Defaults for every module setting. Unknown/legacy keys stay untouched.
     *
     * @return array<string,mixed>
     */
    function md_tlg_default_config()
    {
        return array(
            'TLG_TOKEN' => '',
            'TLG_API_BASE' => '',
            'TLG_TRANSPORT' => 'direct',
            'TLG_PROXY_HOST' => '',
            'TLG_PROXY_PORT' => 1080,
            'TLG_PROXY_LOGIN' => '',
            'TLG_PROXY_PASSWORD' => '',
            'TLG_PROXY_MT_URL' => '',
            'TLG_MT_API_ID' => '',
            'TLG_MT_API_HASH' => '',
            'TLG_HTTP_TIMEOUT' => 20,
            'TLG_TIMEOUT' => 30,
            'TLG_IPRESOLV' => 'any',
            'TLG_SSL_VERIFY' => 1,
            'TLG_DEBUG' => 0,
            'TLG_STORAGE' => '',
            'TLG_COUNT_ROW' => 3,
            'TLG_PLAYER' => 1,
            'TLG_REG_USER' => 0,
            'TLG_WEBHOOK' => 0,
            'TLG_WEBHOOK_URL' => '',
            'TLG_WEBHOOK_SECRET' => '',
            'TLG_WEBHOOK_CERT' => '',
            'TLG_WEBHOOK_MAX_CONN' => 40,
            'TLG_WEBHOOK_DROP_PENDING' => 0,
            'TLG_ALLOWED_UPDATES' => 'message,edited_message,callback_query,poll_answer,chat_member,chat_join_request,my_chat_member,message_reaction,business_message',
            'TLG_HISTORY_ONLY_ERRORS' => 0,
            'TLG_HISTORY_DAYS' => 7,
            'TLG_SAVE_UNICODE' => 1,
            'TLG_BOT_ID' => '',
            'TLG_BOT_NAME' => '',
            'TLG_BOT_USERNAME' => '',
            'TLG_BOT_JOIN_GROUP' => '',
            'TLG_BOT_READ_GROUP' => '',
            'TLG_BOT_SUPPORT_INLINE' => '',
            'TLG_BOT_CHECKED' => '',
            'TLG_LAST_CHECK' => '',
            'TLG_LAST_ERROR' => '',
            'TLG_LAST_ERROR_TIME' => '',
            'TLG_LAST_UPDATE_ID' => 0,
            'TLG_WEBHOOK_LAST_UPDATE_ID' => 0,
            'TLG_SCHEMA_VERSION' => 0,
            'TLG_SETUP_DONE' => 0,
        );
    }
}

if (!function_exists('md_tlg_storage_dir')) {
    /**
     * @param array $config
     * @return string Absolute path without trailing slash.
     */
    function md_tlg_storage_dir(array $config)
    {
        $path = isset($config['TLG_STORAGE']) ? trim((string)$config['TLG_STORAGE']) : '';
        if ($path === '') {
            $path = 'cms/cached/telegram';
        }
        if (!preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $path)) {
            $path = rtrim(ROOT, '/\\') . '/' . ltrim(str_replace('\\', '/', $path), '/');
        }
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}

if (!function_exists('md_tlg_bot_avatar')) {
    /**
     * @param string $botId
     * @return string URL or empty string.
     */
    function md_tlg_bot_avatar($botId)
    {
        $botId = trim((string)$botId);
        if ($botId === '' || !preg_match('/^\d+$/', $botId)) {
            return '';
        }
        $file = rtrim(ROOT, '/\\') . '/cms/cached/telegram/' . $botId . '.jpg';
        if (!is_file($file)) {
            return '';
        }
        return rtrim(ROOTHTML, '/') . '/cms/cached/telegram/' . $botId . '.jpg?v=' . filemtime($file);
    }
}

if (!function_exists('md_tlg_user_avatar')) {
    /**
     * @param string $chatId
     * @return string
     */
    function md_tlg_user_avatar($chatId)
    {
        $chatId = trim((string)$chatId);
        if ($chatId === '' || !preg_match('/^-?\d+$/', $chatId)) {
            return '';
        }
        $file = rtrim(ROOT, '/\\') . '/cms/cached/telegram/' . $chatId . '.jpg';
        if (!is_file($file)) {
            return '';
        }
        return rtrim(ROOTHTML, '/') . '/cms/cached/telegram/' . $chatId . '.jpg?v=' . filemtime($file);
    }
}

if (!function_exists('md_tlg_initials')) {
    /**
     * @param string $name
     * @return string
     */
    function md_tlg_initials($name)
    {
        $name = trim((string)$name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/u', $name);
        $first = mb_substr($parts[0], 0, 1, 'UTF-8');
        $second = isset($parts[1]) ? mb_substr($parts[1], 0, 1, 'UTF-8') : '';
        return mb_strtoupper($first . $second, 'UTF-8');
    }
}

if (!function_exists('md_tlg_format_bytes')) {
    /**
     * @param int $bytes
     * @return string
     */
    function md_tlg_format_bytes($bytes)
    {
        $bytes = (int)$bytes;
        if ($bytes <= 0) {
            return '—';
        }
        $units = array('Б', 'КБ', 'МБ', 'ГБ');
        $power = (int)floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        $value = $bytes / pow(1024, $power);
        return round($value, $value >= 10 ? 0 : 1) . ' ' . $units[$power];
    }
}

if (!function_exists('md_tlg_truncate')) {
    /**
     * @param string $text
     * @param int    $limit
     * @return string
     */
    function md_tlg_truncate($text, $limit = 180)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '…';
    }
}

if (!function_exists('md_tlg_pagination')) {
    /**
     * Build server-side pagination data for templates.
     *
     * @param int    $total
     * @param int    $page     1-based
     * @param int    $perPage
     * @param string $baseUrl  URL without the `page` argument
     * @param int    $window   Number of page links around the current page
     * @return array<string,mixed>
     */
    function md_tlg_pagination($total, $page, $perPage, $baseUrl, $window = 2)
    {
        $total = max(0, (int)$total);
        $perPage = max(1, (int)$perPage);
        $pages = (int)ceil($total / $perPage);
        $page = max(1, min($pages > 0 ? $pages : 1, (int)$page));

        $separator = strpos($baseUrl, '?') === false ? '?' : '&';
        $urlFor = static function ($target) use ($baseUrl, $separator) {
            return $baseUrl . $separator . 'page=' . (int)$target;
        };

        $result = array(
            'TOTAL' => $total,
            'PAGE' => $page,
            'PER_PAGE' => $perPage,
            'PAGES' => $pages,
            'HAS_PAGES' => $pages > 1 ? 1 : 0,
            'SHOW_FROM' => $total === 0 ? 0 : (($page - 1) * $perPage + 1),
            'SHOW_TO' => min($total, $page * $perPage),
            'PAGE_PREV' => $page > 1 ? $urlFor($page - 1) : '',
            'PAGE_NEXT' => $page < $pages ? $urlFor($page + 1) : '',
            'PAGE_ITEMS' => array(),
        );

        if ($pages <= 1) {
            return $result;
        }

        $numbers = array();
        for ($i = 1; $i <= $pages; $i++) {
            if ($i === 1 || $i === $pages || abs($i - $page) <= $window) {
                $numbers[] = $i;
            }
        }
        $prev = 0;
        foreach ($numbers as $number) {
            if ($prev > 0 && $number - $prev > 1) {
                $result['PAGE_ITEMS'][] = array('ELLIPSIS' => 1);
            }
            $result['PAGE_ITEMS'][] = array(
                'NUM' => $number,
                'URL' => $urlFor($number),
                'SELECTED' => $number === $page ? 1 : 0,
            );
            $prev = $number;
        }

        return $result;
    }
}

if (!function_exists('md_tlg_log_path')) {
    /**
     * Path of today's DebMes log for the module.
     *
     * @param string $date Y-m-d, defaults to today
     * @return string
     */
    function md_tlg_log_path($date = '')
    {
        $date = $date !== '' ? $date : date('Y-m-d');
        $dir = str_replace('\\', '/', rtrim(ROOT, '/\\')) . '/cms/debmes';
        if (defined('SETTINGS_SYSTEM_DEBMES_PATH') && SETTINGS_SYSTEM_DEBMES_PATH != '') {
            $dir = str_replace('\\', '/', rtrim(SETTINGS_SYSTEM_DEBMES_PATH, '/\\'));
        } elseif (defined('LOG_DIRECTORY') && LOG_DIRECTORY != '') {
            $dir = str_replace('\\', '/', rtrim(LOG_DIRECTORY, '/\\'));
        }
        return $dir . '/' . $date . '_telegram.log';
    }
}

if (!function_exists('md_tlg_log_lines')) {
    /**
     * Read the tail of the module log.
     *
     * @param string $filter
     * @param int    $limit
     * @return array{lines:array<int,array{time:string,text:string}>,path:string,exists:bool,size:int}
     */
    function md_tlg_log_lines($filter = '', $limit = 200)
    {
        $path = md_tlg_log_path();
        $result = array('lines' => array(), 'path' => $path, 'exists' => is_file($path), 'size' => 0);
        if (!is_file($path)) {
            return $result;
        }
        $result['size'] = (int)filesize($path);
        $limit = max(10, min(2000, (int)$limit));
        $content = @file_get_contents($path);
        if ($content === false) {
            return $result;
        }
        $rawLines = preg_split('/\r?\n/', $content);
        $rawLines = array_reverse($rawLines);
        $filter = trim((string)$filter);
        foreach ($rawLines as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            if ($filter !== '' && mb_stripos($line, $filter, 0, 'UTF-8') === false) {
                continue;
            }
            $time = '';
            $text = $line;
            if (preg_match('/^(\d{2}:\d{2}:\d{2})\s+(.*)$/s', $line, $m)) {
                $time = $m[1];
                $text = $m[2];
            }
            $result['lines'][] = array('time' => $time, 'text' => $text);
            if (count($result['lines']) >= $limit) {
                break;
            }
        }
        return $result;
    }
}

if (!function_exists('md_tlg_command_examples')) {
    /**
     * Ready-to-use command snippets for the editor.
     *
     * @return array<string,array<string,string>>
     */
    function md_tlg_command_examples()
    {
        return array(
            'ping' => array(
                'title' => 'Ping → Pong',
                'description' => 'Минимальная команда проверки связи.',
                'code' => "return 'Pong!';",
            ),
            'params' => array(
                'title' => 'Команда с параметром',
                'description' => 'Первое слово — команда, остальное приходит в $args.',
                'code' => "if (\$args === '') {\n    return 'Использование: /echo текст';\n}\n\nreturn 'Вы сказали: ' . \$args;",
            ),
            'status' => array(
                'title' => 'Статус объекта',
                'description' => 'Читает свойство объекта и отдаёт его в чат.',
                'code' => "\$temperature = gg('Kitchen.Temperature');\n\$humidity = gg('Kitchen.Humidity');\n\nreturn 'Кухня: ' . \$temperature . ' °C, влажность ' . \$humidity . '%';",
            ),
            'inline' => array(
                'title' => 'Inline-кнопки',
                'description' => 'Сообщение с кнопками; обработчик — событие «Нажатие inline-кнопки».',
                'code' => "\$inline = \$this->buildInlineKeyBoard([\n    [\n        \$this->buildInlineKeyboardButton('Свет', '', 'cmd:light'),\n        \$this->buildInlineKeyboardButton('Статус', '', 'cmd:status'),\n    ],\n]);\n\n\$this->sendMessage(\$chat_id, 'Выберите действие:', '', 'HTML', \$inline);\n\nreturn true;",
            ),
            'silent' => array(
                'title' => 'Тихая отправка',
                'description' => 'Отправка без звукового уведомления.',
                'code' => "\$this->sendMessageToUser(\$chat_id, 'Ночной режим включён', null, '', true);\n\nreturn true;",
            ),
        );
    }
}

if (!function_exists('md_tlg_event_example')) {
    /**
     * Example handler for an event type.
     *
     * @param int $type
     * @return string
     */
    function md_tlg_event_example($type)
    {
        $examples = array(
            1 => "// Команды обрабатываются отдельно; здесь — свободный текст.\nif (mb_stripos(\$text, 'привет') !== false) {\n    \$this->sendMessageToUser(\$chat_id, 'Привет, ' . \$fullname . '!');\n    \$skip = true;\n}",
            2 => "\$this->sendMessageToUser(\$chat_id, 'Фото получено: ' . (\$caption !== '' ? \$caption : 'без подписи'));\n\$skip = true;",
            3 => "\$this->sendMessageToUser(\$chat_id, 'Голосовое сообщение сохранено: ' . \$file_path);",
            4 => "say('Получено аудио от ' . \$fullname);\n\$this->sendMessageToUser(\$chat_id, 'Аудио: ' . \$audio['title']);",
            5 => "\$this->sendMessageToUser(\$chat_id, 'Видео сохранено: ' . \$file_path);",
            6 => "\$this->sendMessageToUser(\$chat_id, 'Файл ' . \$file_name . ' сохранён');",
            7 => "say('Стикер от ' . \$fullname . ': ' . \$sticker['emoji']);",
            8 => "\$this->sendMessageToUser(\$chat_id, 'Координаты: ' . \$latitude . ', ' . \$longitude);\n// setGlobal('User.Coordinates', \$latitude . ',' . \$longitude);",
            9 => "// callback_data приходит из inline-кнопки.\n\$this->answerCallbackQuery(\$callback_id, 'Принято');\n\nif (\$callback_data === 'cmd:light') {\n    callMethod('Light.Toggle');\n    \$this->editMessage(\$chat_id, \$message_id, 'Свет переключён');\n}",
            10 => "say('Пользователь ' . \$fullname . ' проголосовал: ' . implode(',', \$option_ids));",
            12 => "\$this->sendMessageToUser(\$chat_id, 'Контакт: ' . \$contact['first_name'] . ' ' . \$contact['phone_number']);",
            13 => "\$this->sendMessageToUser(\$chat_id, 'Место: ' . \$venue['title'] . ', ' . \$venue['address']);",
            14 => "say('Dice: ' . \$dice['value'] . ' ' . \$dice['emoji']);",
            16 => "\$this->sendMessageToUser(\$chat_id, 'Сообщение изменено на: ' . \$text);",
            18 => "// Пришёл new_chat_members, left_chat_member или заявка.\nif (!empty(\$chat_member)) {\n    say('Статус участника изменён: ' . \$chat_member['new_chat_member']['status']);\n}",
            19 => "// reactions: массив эмодзи и custom emoji.\n\$labels = [];\nforeach ((array)\$message_reaction['reactions'] as \$reaction) {\n    \$labels[] = \$reaction['emoji'] ?? ('custom:' . (\$reaction['custom_emoji_id'] ?? ''));\n}\nsay('Реакция: ' . implode(' ', \$labels));",
            20 => "// Ответ на inline-запрос: вернуть массив результатов.\n// \$this->answerInlineQuery(\$inline_query_id, [\n//     [['type' => 'article', 'id' => '1', 'title' => 'Статус', 'input_message_content' => ['message_text' => 'OK']]],\n// ]);",
            21 => "\$this->sendMessageToUser(\$chat_id, 'Business-сообщение: ' . \$text);",
        );
        return isset($examples[(int)$type]) ? $examples[(int)$type] : "// \$chat_id, \$user, \$message и \$telegram_module доступны в этом обработчике.\nsay('Событие Telegram: ' . \$chat_id);";
    }
}

if (!function_exists('md_tlg_example_bot_api_command')) {
    /**
     * Copyable command that starts a local Bot API server (MTProto upstream).
     *
     * @param array $config
     * @return string
     */
    function md_tlg_example_bot_api_command(array $config)
    {
        $apiId = trim((string)(isset($config['TLG_MT_API_ID']) ? $config['TLG_MT_API_ID'] : ''));
        $apiHash = trim((string)(isset($config['TLG_MT_API_HASH']) ? $config['TLG_MT_API_HASH'] : ''));
        if ($apiId === '') {
            $apiId = '<API_ID>';
        }
        if ($apiHash === '') {
            $apiHash = '<API_HASH>';
        }
        return "docker run -d --name telegram-bot-api \\\n" .
            "  --restart unless-stopped \\\n" .
            "  -p 127.0.0.1:8081:8081 \\\n" .
            "  -e TELEGRAM_API_ID=" . $apiId . " \\\n" .
            "  -e TELEGRAM_API_HASH=" . $apiHash . " \\\n" .
            "  -v /srv/telegram-bot-api:/var/lib/telegram-bot-api \\\n" .
            "  aiogram/telegram-bot-api:latest \\\n" .
            "  --local --api-id=" . $apiId . " --api-hash=" . $apiHash . " --http-port=8081";
    }
}

if (!function_exists('md_tlg_example_nginx_stream')) {
    /**
     * Copyable nginx stream snippet for SNI-based transparent routing.
     *
     * @return string
     */
    function md_tlg_example_nginx_stream()
    {
        return "stream {\n" .
            "    map \$ssl_preread_server_name \$telegram_upstream {\n" .
            "        api.telegram.org   telegram_tls;\n" .
            "        default            direct_tls;\n" .
            "    }\n\n" .
            "    upstream telegram_tls {\n" .
            "        server 149.154.167.220:443;\n" .
            "    }\n\n" .
            "    server {\n" .
            "        listen 127.0.0.1:8443;\n" .
            "        proxy_pass \$telegram_upstream;\n" .
            "        ssl_preread on;\n" .
            "    }\n" .
            "}";
    }
}

if (!function_exists('md_tlg_proxy_link_help')) {
    /**
     * Explanation for a `tg://proxy` / `tg://socks` deep link.
     *
     * @param string $link
     * @return array
     */
    function md_tlg_proxy_link_help($link)
    {
        $parsed = TelegramApi::parseTelegramProxyLink($link);
        if ($parsed['type'] === '') {
            return array('ok' => false, 'type' => '', 'message' => 'Ссылка должна начинаться с tg://socks? или tg://proxy?');
        }
        if ($parsed['type'] === 'socks') {
            return array(
                'ok' => true,
                'type' => 'socks',
                'server' => $parsed['server'],
                'port' => $parsed['port'],
                'user' => $parsed['user'],
                'pass' => $parsed['pass'],
                'message' => 'Это SOCKS5-прокси. Его можно указать в полях SOCKS5 выше.',
            );
        }
        return array(
            'ok' => true,
            'type' => 'mtproto',
            'server' => $parsed['server'],
            'port' => $parsed['port'],
            'secret' => $parsed['secret'],
            'message' => 'Это MTProto-прокси. Bot API не умеет работать через MTProto-прокси напрямую: используйте локальный Bot API сервер (он ходит в Telegram по MTProto) или прозрачный прокси/VPN.',
        );
    }
}

if (!function_exists('md_tlg_csv')) {
    /**
     * Send a CSV download and exit.
     *
     * @param string $filename
     * @param array  $header
     * @param array  $rows
     * @return void
     */
    function md_tlg_csv($filename, array $header, array $rows)
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, $header, ';');
        foreach ($rows as $row) {
            fputcsv($out, $row, ';');
        }
        fclose($out);
        exit;
    }
}

if (!function_exists('md_tlg_json')) {
    /**
     * Send a JSON response and exit.
     *
     * @param array $payload
     * @param int   $status
     * @return void
     */
    function md_tlg_json(array $payload, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
