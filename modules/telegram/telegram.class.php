<?php
/**
 * Telegram bot module for MajorDoMo.
 *
 * The module keeps the historical public API (`sendMessageToUser()`,
 * `sendImageToAdmin()`, `sendContent()` …) so existing scenarios keep working,
 * but the transport, update parsing and admin UI were rebuilt:
 *
 * - Bot API client with proxies, custom base URL and TLS verification;
 * - polling offset persisted in the module config (no duplicate updates after
 *   a restart) and webhook secret-token support;
 * - new update types: reactions, business messages, chat member changes,
 *   join requests, inline queries, video notes, contacts, dice, animations;
 * - per-command run counters and per-event counters;
 * - a modern admin UI built on MDJUI.
 *
 * @package MajorDoMo
 */

require_once(__DIR__ . '/TelegramApi.php');
require_once(__DIR__ . '/TelegramUpdate.php');
require_once(__DIR__ . '/telegram_helpers.inc.php');

class telegram extends module
{
    const SCHEMA_VERSION = 4;

    /** @var TelegramApi */
    protected $api;

    /** @var TelegramUpdate|null */
    protected $current_update;

    /** @var int */
    protected $last_update_id = 0;

    /** @var array */
    protected $schema_cache = array();

    /**
     * telegram constructor.
     */
    public function __construct()
    {
        $this->name = 'telegram';
        $this->title = 'Telegram';
        $this->module_category = '<#LANG_SECTION_APPLICATIONS#>';

        $this->checkInstalled();
        $this->loadConfigSafe();
        $this->maybeUpgradeSchema();
        $this->initApi();

        if (!isset($this->id)) {
            $this->id = 0;
        }
    }

    /**
     * getConfig() wrapper that tolerates a missing project_modules row.
     *
     * @return array
     */
    protected function loadConfigSafe()
    {
        try {
            $this->getConfig();
        } catch (Throwable $e) {
            $this->config = array();
        }
        if (!is_array($this->config)) {
            $this->config = array();
        }
        $this->applyConfigDefaults();
        return $this->config;
    }

    /**
     * Fill defaults and migrate old proxy settings.
     *
     * @return void
     */
    protected function applyConfigDefaults()
    {
        $legacyUseProxy = !empty($this->config['TLG_USEPROXY']);
        $hasTransport = isset($this->config['TLG_TRANSPORT']) && $this->config['TLG_TRANSPORT'] !== '';

        if ($legacyUseProxy && !$hasTransport) {
            $this->config['TLG_TRANSPORT'] = TelegramApi::transportFromLegacy($this->config);
        }
        if (empty($this->config['TLG_PROXY_HOST']) && !empty($this->config['TLG_PROXY_URL'])) {
            $parsed = TelegramApi::parseProxyAddress($this->config['TLG_PROXY_URL']);
            if ($parsed['host'] !== '') {
                $this->config['TLG_PROXY_HOST'] = $parsed['host'];
                $this->config['TLG_PROXY_PORT'] = $parsed['port'] > 0
                    ? $parsed['port']
                    : (isset($this->config['TLG_PROXY_PORT']) ? (int)$this->config['TLG_PROXY_PORT'] : 1080);
                if ($parsed['login'] !== '' && empty($this->config['TLG_PROXY_LOGIN'])) {
                    $this->config['TLG_PROXY_LOGIN'] = $parsed['login'];
                    $this->config['TLG_PROXY_PASSWORD'] = $parsed['password'];
                }
            }
        }

        $defaults = md_tlg_default_config();
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $this->config) || $this->config[$key] === null) {
                $this->config[$key] = $value;
            }
        }

        if ((string)$this->config['TLG_STORAGE'] === '') {
            $this->config['TLG_STORAGE'] = 'cms/cached/telegram';
        }
        if ((int)$this->config['TLG_PROXY_PORT'] <= 0) {
            $this->config['TLG_PROXY_PORT'] = 1080;
        }
        if ((int)$this->config['TLG_HTTP_TIMEOUT'] <= 0) {
            $this->config['TLG_HTTP_TIMEOUT'] = 20;
        }
        if ((int)$this->config['TLG_TIMEOUT'] <= 0) {
            $this->config['TLG_TIMEOUT'] = 30;
        }
        if ((int)$this->config['TLG_HISTORY_DAYS'] <= 0) {
            $this->config['TLG_HISTORY_DAYS'] = 7;
        }
    }

    /**
     * @return void
     */
    protected function initApi()
    {
        $this->api = TelegramApi::fromConfig($this->config, function ($message) {
            $this->log($message);
        });
    }

    /**
     * @return TelegramApi
     */
    public function getApi()
    {
        return $this->api;
    }

    /**
     * Rebuild the client after config changes.
     *
     * @return void
     */
    protected function refreshApi()
    {
        $this->initApi();
    }

    /**
     * Build a canonical module URL.
     *
     * Links are emitted as clean `/panel/telegram.html?…` URLs instead of
     * relying on the legacy `?…` rewrite. This keeps `tab` and `view_mode`
     * state visible in the address bar and in shared links.
     *
     * @param array $params
     * @return string
     */
    public function adminUrl(array $params = array())
    {
        $base = rtrim(ROOTHTML, '/') . '/panel/telegram.html';
        if (empty($params)) {
            return $base;
        }
        return $base . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Convert internal `?tab=…` / `?view_mode=…` links to canonical module URLs.
     *
     * Only URL-shaped keys are touched: message text and code previews may
     * legitimately start with a question mark.
     *
     * @param mixed  $value
     * @param string $key
     * @return void
     */
    protected function prettifyUrls(&$value, $key = '')
    {
        if (is_array($value)) {
            foreach ($value as $itemKey => &$item) {
                $this->prettifyUrls($item, (string)$itemKey);
            }
            unset($item);
            return;
        }
        if (!is_string($value) || $value === '' || $value[0] !== '?') {
            return;
        }
        $isUrlKey = ($key === 'URL' || substr($key, -4) === '_URL' || in_array($key, array('PAGE_PREV', 'PAGE_NEXT'), true));
        if (!$isUrlKey) {
            return;
        }
        $query = array();
        parse_str(substr($value, 1), $query);
        if (isset($query['tab']) || isset($query['view_mode'])) {
            $value = $this->adminUrl($query);
        }
    }

    /**
     * Redirect within the module using canonical URLs.
     *
     * @param string $url
     * @return void
     */
    public function redirect($url)
    {
        $url = (string)$url;
        if ($url !== '' && $url[0] === '?') {
            $query = array();
            parse_str(substr($url, 1), $query);
            $url = $this->adminUrl($query);
        }
        parent::redirect($url);
    }

    /**
     * saveParams
     *
     * @param mixed $data
     * @return string|void
     */
    public function saveParams($data = 0)
    {
        $p = array();
        if (isset($this->id)) {
            $p['id'] = $this->id;
        }
        if (isset($this->view_mode)) {
            $p['view_mode'] = $this->view_mode;
        }
        if (isset($this->edit_mode)) {
            $p['edit_mode'] = $this->edit_mode;
        }
        if (isset($this->tab)) {
            $p['tab'] = $this->tab;
        }
        return parent::saveParams($p);
    }

    /**
     * getParams
     *
     * @return void
     */
    public function getParams()
    {
        global $id;
        global $mode;
        global $view_mode;
        global $edit_mode;
        global $tab;
        if (isset($id)) {
            $this->id = $id;
        }
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
        if (isset($edit_mode)) {
            $this->edit_mode = $edit_mode;
        }
        if (isset($tab)) {
            $this->tab = $tab;
        }
    }

    /**
     * Module entry point.
     *
     * @return void
     */
    public function run()
    {
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (isset($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $out['DATA_SOURCE'] = $this->data_source;
        $out['TAB'] = $this->tab;
        $out['ROOTHTML'] = ROOTHTML;
        if ($this->single_rec) {
            $out['SINGLE_REC'] = 1;
        }
        $this->prettifyUrls($out);
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . '/' . $this->name . '.html', $this->data, $this);
        $this->result = $p->result;
    }

    /**
     * Logging: 0 info, 1 debug, 2 warnings only.
     *
     * @param mixed $message
     * @return void
     */
    public function log($message)
    {
        if (is_array($message) || is_object($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        DebMes((string)$message, 'telegram');
    }

    /**
     * @param mixed $content
     * @return void
     */
    public function info($content)
    {
        if ((int)$this->config['TLG_DEBUG'] !== 2) {
            $this->log($content);
        }
    }

    /**
     * @param mixed $content
     * @return void
     */
    public function debug($content)
    {
        if ((int)$this->config['TLG_DEBUG'] === 1) {
            $this->log($content);
        }
    }

    /**
     * @param mixed $content
     * @return void
     */
    public function warning($content)
    {
        $this->log($content);
    }

    /**
     * Remember the last transport error for the settings page.
     *
     * @param array $error
     * @return void
     */
    protected function rememberError(array $error)
    {
        $description = isset($error['description']) ? (string)$error['description'] : '';
        $code = isset($error['error_code']) ? (int)$error['error_code'] : (isset($error['code']) ? (int)$error['code'] : 0);
        if ($description === '' && $code === 0) {
            return;
        }
        $message = trim($code . ' ' . $description);
        if ($message === (string)$this->config['TLG_LAST_ERROR']) {
            return;
        }
        $this->config['TLG_LAST_ERROR'] = $message;
        $this->config['TLG_LAST_ERROR_TIME'] = date('Y-m-d H:i:s');
        $this->saveConfig();
    }

    /**
     * Admin backend.
     *
     * @param array $out
     * @return void
     */
    public function admin(&$out)
    {
        $this->loadConfigSafe();
        $this->initApi();

        if (gr('ajax') !== '') {
            $this->handleAjax();
        }
        if ($this->handleLegacyRequest()) {
            return;
        }

        $viewMode = $this->normalizeViewMode((string)$this->view_mode);

        if ($viewMode === 'update_settings') {
            $this->saveSettings($out);
            if (empty($out['SAVE_ERRORS'])) {
                $this->redirect('?view_mode=settings&ok_msg=' . urlencode('Настройки сохранены'));
            }
            $viewMode = 'settings';
        }

        if (in_array($viewMode, array('delete_user', 'delete_cmd', 'delete_event', 'delete_history', 'clear_history', 'clear_log', 'toggle_event', 'delete_history_all'), true)) {
            $this->handleAction($viewMode);
            return;
        }
        if (in_array($viewMode, array('export_command', 'export_event', 'export_history', 'import_command', 'import_event', 'sync_commands', 'refresh_bot', 'refresh_users'), true)) {
            $this->handleUtilityAction($viewMode);
            return;
        }

        $this->prepareCommon($out);

        if ($viewMode === 'settings') {
            $this->prepareSettings($out);
            $out['PAGE_MODE'] = 'settings';
            $out['ACTIVE_TAB'] = 'settings';
            return;
        }
        if ($viewMode === 'edit_user') {
            $this->edit_user($out, $this->id);
            $out['PAGE_MODE'] = 'user';
            return;
        }
        if ($viewMode === 'edit_cmd') {
            $this->edit_cmd($out, $this->id);
            $out['PAGE_MODE'] = 'cmd';
            return;
        }
        if ($viewMode === 'edit_event') {
            $this->edit_event($out, $this->id);
            $out['PAGE_MODE'] = 'event';
            return;
        }

        $tab = $this->normalizeTab((string)$this->tab);
        if ($tab !== '' && $tab !== (string)$this->tab) {
            $this->tab = $tab;
        }
        $out['ACTIVE_TAB'] = $tab;
        $out['PAGE_MODE'] = 'list';

        switch ($tab) {
            case 'users':
                $this->prepareUsers($out);
                break;
            case 'commands':
                $this->prepareCommands($out);
                break;
            case 'events':
                $this->prepareEvents($out);
                break;
            case 'history':
                $this->prepareHistory($out);
                break;
            case 'log':
                $this->prepareLog($out);
                break;
            case 'help':
                $this->prepareHelp($out);
                break;
            case 'overview':
            default:
                $out['ACTIVE_TAB'] = 'overview';
                $this->prepareOverview($out);
                break;
        }

        $this->prepareTabs($out);
    }

    /**
     * Accept old view_mode aliases so bookmarks and old links keep working.
     *
     * @param string $viewMode
     * @return string
     */
    protected function normalizeViewMode($viewMode)
    {
        $aliases = array(
            'user_edit' => 'edit_user',
            'cmd_edit' => 'edit_cmd',
            'event_edit' => 'edit_event',
            'user_delete' => 'delete_user',
            'cmd_delete' => 'delete_cmd',
            'event_delete' => 'delete_event',
            'history_delete' => 'delete_history',
            'search_ms' => '',
            'search_users' => '',
            'search_cmd' => '',
            'search_events' => '',
            'search_history' => '',
            'search_log' => '',
            'export_command' => 'export_command',
            'import_command' => 'import_command',
            'export_event' => 'export_event',
            'import_event' => 'import_event',
        );
        if (isset($aliases[$viewMode])) {
            return $aliases[$viewMode];
        }
        $allowed = array(
            '', 'settings', 'update_settings', 'edit_user', 'edit_cmd', 'edit_event',
            'delete_user', 'delete_cmd', 'delete_event', 'delete_history', 'clear_history',
            'clear_log', 'toggle_event', 'delete_history_all', 'export_command', 'export_event',
            'export_history', 'import_command', 'import_event', 'sync_commands', 'refresh_bot',
            'refresh_users',
        );
        return in_array($viewMode, $allowed, true) ? $viewMode : '';
    }

    /**
     * @param string $tab
     * @return string
     */
    protected function normalizeTab($tab)
    {
        $tab = trim($tab);
        $aliases = array(
            'cmd' => 'commands',
            'events' => 'events',
            'users' => 'users',
            'history' => 'history',
            'log' => 'log',
            'help' => 'help',
        );
        if (isset($aliases[$tab])) {
            return $aliases[$tab];
        }
        $allowed = array('overview', 'users', 'commands', 'events', 'history', 'log', 'help');
        return in_array($tab, $allowed, true) ? $tab : 'overview';
    }

    /**
     * Legacy endpoints that old scenarios/hooks may still call.
     *
     * @return bool true when the request was handled and output was sent.
     */
    protected function handleLegacyRequest()
    {
        if (gr('getlog') !== '') {
            header('Content-Type: text/html; charset=utf-8');
            $filter = gr('filter');
            $limit = max(10, min(1000, gr('limit', 'int') ?: 200));
            $log = md_tlg_log_lines($filter, $limit);
            $lines = array();
            foreach ($log['lines'] as $line) {
                $lines[] = ($line['time'] !== '' ? '<span style="color:#8a8a8a">' . md_tlg_h($line['time']) . '</span> ' : '') . md_tlg_h($line['text']);
            }
            echo implode('<br/>', $lines);
            exit;
        }

        if (gr('webhookinfo') !== '') {
            header('Content-Type: text/html; charset=utf-8');
            $info = $this->api->getWebhookInfo();
            echo md_tlg_h($this->webhookSummary($info));
            exit;
        }

        if (gr('setwebhook') !== '') {
            header('Content-Type: text/html; charset=utf-8');
            $url = trim((string)gr('tlg_webhook_url'));
            $cert = trim((string)gr('tlg_webhook_cert'));
            if ($url === '') {
                $url = rtrim(BASE_URL, '/') . '/webhook_telegram.php';
            }
            $this->config['TLG_WEBHOOK_URL'] = $url;
            $this->config['TLG_WEBHOOK_CERT'] = $cert;
            $this->saveConfig();
            $result = $this->api->setWebhook($url, array(
                'certificate' => $cert,
                'secret_token' => (string)$this->config['TLG_WEBHOOK_SECRET'],
                'allowed_updates' => $this->allowedUpdatesArray(),
            ));
            echo md_tlg_h($this->describeResult($result));
            exit;
        }

        if (gr('cleanwebhook') !== '') {
            header('Content-Type: text/html; charset=utf-8');
            $result = $this->api->deleteWebhook(false);
            echo md_tlg_h($this->describeResult($result));
            exit;
        }

        if (gr('sendMessage') !== '') {
            header('Content-Type: text/html; charset=utf-8');
            $user = gr('user');
            $text = gr('text');
            $image = gr('image');
            $silent = gr('silent');
            $silent = $silent === '' ? null : (bool)(int)$silent;
            if ($image !== '' && file_exists($image)) {
                $this->sendImageToUser($user, $image, $text, null, '', $silent);
            } elseif ($text !== '') {
                $this->sendMessageToUser($user, $text, null, '', $silent);
            }
            echo 'Ok';
            exit;
        }

        global $update_user_info;
        if (!empty($update_user_info)) {
            $this->refreshAllUsers();
            $this->redirect('?tab=users');
            return true;
        }

        return false;
    }

    /**
     * @param array $result
     * @return string
     */
    protected function describeResult(array $result)
    {
        if (!empty($result['ok'])) {
            return 'OK';
        }
        $description = isset($result['description']) ? (string)$result['description'] : 'Unknown error';
        if (!empty($result['hint'])) {
            $description .= ' — ' . $result['hint'];
        }
        return $description;
    }

    /**
     * @param array $info
     * @return string
     */
    protected function webhookSummary(array $info)
    {
        if (empty($info['ok']) || !isset($info['result'])) {
            return 'Не удалось получить статус webhook: ' . (isset($info['description']) ? $info['description'] : 'нет ответа');
        }
        $result = $info['result'];
        $parts = array();
        $parts[] = 'URL: ' . (isset($result['url']) && $result['url'] !== '' ? $result['url'] : 'не задан');
        $parts[] = 'Ожидает обновлений: ' . (isset($result['pending_update_count']) ? (int)$result['pending_update_count'] : 0);
        if (!empty($result['last_error_message'])) {
            $parts[] = 'Последняя ошибка: ' . $result['last_error_message'] . (isset($result['last_error_date']) ? ' (' . date('d.m.Y H:i', (int)$result['last_error_date']) . ')' : '');
        }
        if (!empty($result['max_connections'])) {
            $parts[] = 'max_connections: ' . (int)$result['max_connections'];
        }
        return implode("\n", $parts);
    }

    // -------------------------------------------------------------------------
    // AJAX
    // -------------------------------------------------------------------------

    /**
     * Handle `?ajax=1&op=...` requests.
     *
     * @return void
     */
    protected function handleAjax()
    {
        $op = trim((string)gr('op'));

        switch ($op) {
            case 'test':
                $this->ajaxTestConnection();
                break;
            case 'webhook_info':
                $this->ajaxWebhookInfo();
                break;
            case 'webhook_set':
                $this->ajaxWebhookSet();
                break;
            case 'webhook_delete':
                $this->ajaxWebhookDelete();
                break;
            case 'send_test':
                $this->ajaxSendTest();
                break;
            case 'get_chat':
            case 'update_profile':
                $this->ajaxUpdateProfile();
                break;
            case 'history_raw':
                $this->ajaxHistoryRaw();
                break;
            case 'log':
                $this->ajaxLog();
                break;
            case 'log_clear':
                $this->ajaxLogClear();
                break;
            case 'check_proxy':
                $this->ajaxCheckProxy();
                break;
            case 'parse_proxy_link':
                $this->ajaxParseProxyLink();
                break;
            case 'validate_code':
                $this->ajaxValidateCode();
                break;
            case 'example':
                $this->ajaxExample();
                break;
            case 'generate_secret':
                $this->ajaxGenerateSecret();
                break;
            case 'clear_history':
                $this->ajaxClearHistory();
                break;
        }

        md_tlg_json(array('ok' => false, 'error' => 'Неизвестная операция: ' . $op), 400);
    }

    /**
     * @return void
     */
    protected function ajaxTestConnection()
    {
        $started = microtime(true);
        if (trim((string)$this->config['TLG_TOKEN']) === '') {
            md_tlg_json(array('ok' => false, 'error' => 'Сначала укажите токен бота.', 'hint' => 'Получите токен у @BotFather.'));
        }

        $me = $this->api->getMe();
        if (empty($me['ok'])) {
            $this->rememberError($me);
            md_tlg_json(array(
                'ok' => false,
                'error' => isset($me['description']) ? $me['description'] : 'Нет ответа от Telegram',
                'hint' => isset($me['hint']) ? $me['hint'] : '',
                'meta' => $this->api->getLastMeta(),
            ));
        }

        $bot = isset($me['result']) && is_array($me['result']) ? $me['result'] : array();
        $this->storeBotInfo($bot);
        $avatar = '';
        if (!empty($bot['id'])) {
            $avatar = $this->downloadBotAvatar((int)$bot['id']);
        }

        $webhook = $this->api->getWebhookInfo();
        $latency = (int)round((microtime(true) - $started) * 1000);

        $this->config['TLG_LAST_CHECK'] = date('Y-m-d H:i:s');
        $this->config['TLG_BOT_CHECKED'] = date('Y-m-d H:i:s');
        $this->config['TLG_LAST_ERROR'] = '';
        $this->config['TLG_LAST_ERROR_TIME'] = '';
        $this->saveConfig();

        md_tlg_json(array(
            'ok' => true,
            'bot' => $this->botPayload($bot),
            'avatar' => $avatar,
            'webhook' => $this->webhookPayload($webhook),
            'latency_ms' => $latency,
            'transport' => $this->api->describeTransport(),
            'base' => $this->api->getBaseUrl(),
            'meta' => $this->api->getLastMeta(),
            'masked_token' => md_tlg_mask_token((string)$this->config['TLG_TOKEN']),
        ));
    }

    /**
     * @return void
     */
    protected function ajaxWebhookInfo()
    {
        $info = $this->api->getWebhookInfo();
        md_tlg_json(array(
            'ok' => !empty($info['ok']),
            'webhook' => $this->webhookPayload($info),
            'error' => empty($info['ok']) ? (isset($info['description']) ? $info['description'] : 'Ошибка запроса') : '',
            'hint' => isset($info['hint']) ? $info['hint'] : '',
        ));
    }

    /**
     * @return void
     */
    protected function ajaxWebhookSet()
    {
        $url = trim((string)gr('url'));
        if ($url === '') {
            $url = rtrim(BASE_URL, '/') . '/webhook_telegram.php';
        }
        if (!preg_match('#^https://#i', $url)) {
            md_tlg_json(array(
                'ok' => false,
                'error' => 'Telegram принимает только HTTPS webhook.',
                'hint' => 'Настройте домен и сертификат или используйте режим опроса (long polling).',
            ), 400);
        }

        $secret = trim((string)gr('secret'));
        if ($secret === '') {
            $secret = $this->generateSecret();
        }
        $allowed = $this->parseAllowedUpdates(gr('allowed_updates'));
        $maxConn = max(1, min(100, (int)gr('max_connections')));
        $dropPending = (bool)(int)gr('drop_pending');

        $result = $this->api->setWebhook($url, array(
            'secret_token' => $secret,
            'max_connections' => $maxConn,
            'allowed_updates' => $allowed,
            'drop_pending_updates' => $dropPending,
        ));
        if (empty($result['ok'])) {
            $this->rememberError($result);
            md_tlg_json(array(
                'ok' => false,
                'error' => isset($result['description']) ? $result['description'] : 'Не удалось установить webhook',
                'hint' => isset($result['hint']) ? $result['hint'] : '',
                'raw' => $result,
            ));
        }

        $this->config['TLG_WEBHOOK'] = 1;
        $this->config['TLG_WEBHOOK_URL'] = $url;
        $this->config['TLG_WEBHOOK_SECRET'] = $secret;
        $this->config['TLG_WEBHOOK_MAX_CONN'] = $maxConn;
        $this->saveConfig();
        setGlobal('cycle_telegramControl', 'restart');

        md_tlg_json(array(
            'ok' => true,
            'message' => 'Webhook установлен. Опрос остановлен, цикл перезапущен.',
            'url' => $url,
            'secret' => $secret,
        ));
    }

    /**
     * @return void
     */
    protected function ajaxWebhookDelete()
    {
        $dropPending = (bool)(int)gr('drop_pending');
        $result = $this->api->deleteWebhook($dropPending);
        if (empty($result['ok'])) {
            $this->rememberError($result);
            md_tlg_json(array(
                'ok' => false,
                'error' => isset($result['description']) ? $result['description'] : 'Не удалось снять webhook',
                'hint' => isset($result['hint']) ? $result['hint'] : '',
            ));
        }
        $this->config['TLG_WEBHOOK'] = 0;
        $this->config['TLG_WEBHOOK_URL'] = '';
        $this->saveConfig();
        setGlobal('cycle_telegramControl', 'restart');

        md_tlg_json(array('ok' => true, 'message' => 'Webhook снят. Модуль вернулся к опросу getUpdates.'));
    }

    /**
     * @return void
     */
    protected function ajaxSendTest()
    {
        $chatId = trim((string)gr('chat_id'));
        $text = trim((string)gr('text'));
        if ($text === '') {
            $text = 'Проверка связи из MajorDoMo: ' . date('d.m.Y H:i:s');
        }
        if ($chatId === '') {
            md_tlg_json(array('ok' => false, 'error' => 'Укажите chat_id получателя.'), 400);
        }
        $result = $this->api->sendMessage($chatId, $text, array('parse_mode' => 'HTML'));
        if (empty($result['ok'])) {
            $this->saveData(array(
                'ok' => false,
                'error_code' => isset($result['error_code']) ? $result['error_code'] : 0,
                'description' => isset($result['description']) ? $result['description'] : '',
                'curl_error_code' => isset($result['curl_error_code']) ? $result['curl_error_code'] : 0,
                'curl_error' => isset($result['curl_error']) ? $result['curl_error'] : '',
                'endpoint' => 'sendMessage',
                'content' => array('chat_id' => $chatId, 'text' => $text),
            ), 2);
            md_tlg_json(array(
                'ok' => false,
                'error' => isset($result['description']) ? $result['description'] : 'Ошибка отправки',
                'hint' => isset($result['hint']) ? $result['hint'] : '',
            ));
        }
        $this->saveData(array(
            'ok' => true,
            'result' => isset($result['result']) ? $result['result'] : array(),
            'endpoint' => 'sendMessage',
            'content' => array('chat_id' => $chatId, 'text' => $text),
        ), 1);
        md_tlg_json(array(
            'ok' => true,
            'message' => 'Сообщение отправлено.',
            'message_id' => isset($result['result']['message_id']) ? (int)$result['result']['message_id'] : 0,
            'latency_ms' => isset($result['meta']['duration_ms']) ? (int)$result['meta']['duration_ms'] : 0,
        ));
    }

    /**
     * Refresh one user profile through getChat.
     *
     * @return void
     */
    protected function ajaxUpdateProfile()
    {
        $id = (int)gr('id');
        if ($id <= 0) {
            $chatId = trim((string)gr('chat_id'));
            $user = $chatId !== '' ? SQLSelectOne("SELECT * FROM tlg_user WHERE USER_ID='" . DBSafe($chatId) . "'") : null;
        } else {
            $user = SQLSelectOne('SELECT * FROM tlg_user WHERE ID=' . $id);
        }
        if (!$user || empty($user['ID'])) {
            md_tlg_json(array('ok' => false, 'error' => 'Пользователь не найден.'), 404);
        }
        $ok = $this->updateInfo($user);
        $fresh = SQLSelectOne('SELECT * FROM tlg_user WHERE ID=' . (int)$user['ID']);
        md_tlg_json(array(
            'ok' => (bool)$ok,
            'error' => $ok ? '' : 'Не удалось получить данные чата.',
            'user' => array(
                'id' => (int)$fresh['ID'],
                'name' => (string)$fresh['NAME'],
                'username' => (string)$fresh['USERNAME'],
                'chat_id' => (string)$fresh['USER_ID'],
                'avatar' => md_tlg_user_avatar($fresh['USER_ID']),
                'last_seen' => md_tlg_relative_time($fresh['LAST_SEEN']),
            ),
        ));
    }

    /**
     * @return void
     */
    protected function ajaxHistoryRaw()
    {
        $id = (int)gr('id');
        $record = $id > 0 ? SQLSelectOne('SELECT * FROM tlg_history WHERE ID=' . $id) : null;
        if (!$record) {
            md_tlg_json(array('ok' => false, 'error' => 'Запись не найдена.'), 404);
        }
        $raw = (string)$record['RAW'];
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $raw = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        md_tlg_json(array(
            'ok' => true,
            'raw' => $raw,
            'created' => (string)$record['CREATED'],
            'message' => (string)$record['MESSAGE'],
            'direction' => (int)$record['DIRECTION'],
            'type' => (int)$record['TYPE'],
        ));
    }

    /**
     * @return void
     */
    protected function ajaxLog()
    {
        $log = md_tlg_log_lines(gr('filter'), (int)gr('limit') ?: 200);
        md_tlg_json(array(
            'ok' => true,
            'lines' => $log['lines'],
            'exists' => $log['exists'],
            'size' => md_tlg_format_bytes($log['size']),
            'path' => $log['path'],
        ));
    }

    /**
     * @return void
     */
    protected function ajaxLogClear()
    {
        $path = md_tlg_log_path();
        if (is_file($path)) {
            @unlink($path);
        }
        md_tlg_json(array('ok' => true, 'message' => 'Журнал за сегодня очищен.'));
    }

    /**
     * @return void
     */
    protected function ajaxCheckProxy()
    {
        $host = trim((string)gr('host'));
        $port = (int)gr('port');
        if ($host === '' || $port <= 0) {
            md_tlg_json(array('ok' => false, 'error' => 'Укажите хост и порт прокси.'), 400);
        }
        $started = microtime(true);
        $connection = @fsockopen($host, $port, $errno, $errstr, 4);
        $latency = (int)round((microtime(true) - $started) * 1000);
        if (is_resource($connection)) {
            fclose($connection);
            md_tlg_json(array('ok' => true, 'message' => 'Порт доступен.', 'latency_ms' => $latency));
        }
        md_tlg_json(array(
            'ok' => false,
            'error' => 'Нет соединения: ' . ($errstr !== '' ? $errstr : 'timeout') . ' (' . $errno . ')',
            'latency_ms' => $latency,
        ));
    }

    /**
     * @return void
     */
    protected function ajaxParseProxyLink()
    {
        $link = trim((string)gr('link'));
        $result = md_tlg_proxy_link_help($link);
        md_tlg_json($result);
    }

    /**
     * @return void
     */
    protected function ajaxValidateCode()
    {
        $code = (string)gr('code');
        if (trim($code) === '') {
            md_tlg_json(array('ok' => true, 'message' => 'Код пуст.'));
        }
        if (!function_exists('code_syntax_error_details')) {
            require_once(ROOT . 'lib/php_check.class.php');
        }
        $details = function_exists('code_syntax_error_details') ? code_syntax_error_details($code) : false;
        if (!$details) {
            $error = function_exists('php_syntax_error') ? php_syntax_error($code) : false;
            if (!$error) {
                md_tlg_json(array('ok' => true, 'message' => 'Синтаксических ошибок не найдено.'));
            }
            md_tlg_json(array('ok' => false, 'error' => md_tlg_h($error)));
        }
        md_tlg_json(array(
            'ok' => false,
            'error' => md_tlg_h(isset($details['message']) ? $details['message'] : 'Ошибка синтаксиса'),
            'line' => isset($details['line']) ? (int)$details['line'] : 0,
            'full' => md_tlg_h(isset($details['full']) ? $details['full'] : ''),
        ));
    }

    /**
     * @return void
     */
    protected function ajaxExample()
    {
        $kind = trim((string)gr('kind'));
        if ($kind === 'event') {
            $type = (int)gr('type');
            $meta = md_tlg_event_type_meta($type);
            md_tlg_json(array(
                'ok' => true,
                'title' => 'Пример для «' . $meta['label'] . '»',
                'label' => $meta['label'],
                'description' => $meta['description'],
                'vars' => $meta['vars'],
                'icon' => $meta['icon'],
                'tone' => $meta['tone'],
                'code' => md_tlg_event_example($type),
            ));
        }
        $examples = md_tlg_command_examples();
        $key = trim((string)gr('key'));
        if ($key === '' || !isset($examples[$key])) {
            $list = array();
            foreach ($examples as $exampleKey => $example) {
                $list[] = array('key' => $exampleKey, 'title' => $example['title'], 'description' => $example['description']);
            }
            md_tlg_json(array('ok' => true, 'examples' => $list));
        }
        md_tlg_json(array(
            'ok' => true,
            'title' => $examples[$key]['title'],
            'code' => $examples[$key]['code'],
            'description' => $examples[$key]['description'],
        ));
    }

    /**
     * @return void
     */
    protected function ajaxGenerateSecret()
    {
        md_tlg_json(array('ok' => true, 'secret' => $this->generateSecret()));
    }

    /**
     * @return void
     */
    protected function ajaxClearHistory()
    {
        $days = (int)gr('days');
        if ($days < 0) {
            $days = 0;
        }
        if ($days > 0) {
            SQLExec("DELETE FROM tlg_history WHERE CREATED < NOW() - INTERVAL " . $days . ' DAY');
        } else {
            SQLExec('DELETE FROM tlg_history');
        }
        md_tlg_json(array('ok' => true, 'message' => 'История очищена.'));
    }

    /**
     * @return string
     */
    protected function generateSecret()
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $secret;
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * @param array $out
     * @return void
     */
    protected function prepareSettings(&$out)
    {
        $transportOptions = array();
        foreach (md_tlg_transport_options() as $value => $meta) {
            $meta['VALUE'] = $value;
            $meta['SELECTED'] = (string)$this->config['TLG_TRANSPORT'] === $value ? 1 : 0;
            $transportOptions[] = $meta;
        }
        $out['TRANSPORT_OPTIONS'] = $transportOptions;
        $out['TRANSPORT'] = (string)$this->config['TLG_TRANSPORT'];
        $out['TRANSPORT_LABEL'] = $this->api->describeTransport();
        $transportMeta = md_tlg_transport_options();
        $out['TRANSPORT_HINT'] = isset($transportMeta[$out['TRANSPORT']]['hint'])
            ? $transportMeta[$out['TRANSPORT']]['hint'] : '';
        $out['PROXY_MODE'] = in_array($out['TRANSPORT'], array('http', 'https', 'socks5', 'socks5h'), true) ? 1 : 0;

        $out['TOKEN'] = '';
        $out['TOKEN_MASK'] = md_tlg_mask_token((string)$this->config['TLG_TOKEN']);
        $out['TOKEN_SET'] = trim((string)$this->config['TLG_TOKEN']) !== '' ? 1 : 0;
        $out['TOKEN_VALID'] = md_tlg_token_is_valid((string)$this->config['TLG_TOKEN']) ? 1 : 0;

        $out['API_BASE'] = md_tlg_h($this->config['TLG_API_BASE']);
        $out['API_BASE_EFFECTIVE'] = md_tlg_h($this->api->getBaseUrl());
        $out['PROXY_HOST'] = md_tlg_h($this->config['TLG_PROXY_HOST']);
        $out['PROXY_PORT'] = (int)$this->config['TLG_PROXY_PORT'];
        $out['PROXY_LOGIN'] = md_tlg_h($this->config['TLG_PROXY_LOGIN']);
        $out['PROXY_PASSWORD_SET'] = (string)$this->config['TLG_PROXY_PASSWORD'] !== '' ? 1 : 0;
        $out['PROXY_MT_URL'] = md_tlg_h($this->config['TLG_PROXY_MT_URL']);
        $out['MT_API_ID'] = md_tlg_h($this->config['TLG_MT_API_ID']);
        $out['MT_API_HASH_SET'] = (string)$this->config['TLG_MT_API_HASH'] !== '' ? 1 : 0;

        $out['HTTP_TIMEOUT'] = (int)$this->config['TLG_HTTP_TIMEOUT'];
        $out['POLL_TIMEOUT'] = (int)$this->config['TLG_TIMEOUT'];
        $out['IPRESOLV'] = md_tlg_h($this->config['TLG_IPRESOLV']);
        $out['SSL_VERIFY'] = (int)$this->config['TLG_SSL_VERIFY'] ? 1 : 0;
        $out['DEBUG_LEVEL'] = (int)$this->config['TLG_DEBUG'];
        $out['STORAGE'] = md_tlg_h($this->config['TLG_STORAGE']);
        $out['STORAGE_EFFECTIVE'] = md_tlg_h(md_tlg_storage_dir($this->config));
        $storageDir = md_tlg_storage_dir($this->config);
        $out['STORAGE_EXISTS'] = is_dir($storageDir) ? 1 : 0;
        $out['STORAGE_WRITABLE'] = is_dir($storageDir) && is_writable($storageDir) ? 1 : (!is_dir($storageDir) && is_writable(dirname($storageDir)) ? 1 : 0);
        $out['LOG_PATH'] = md_tlg_h(md_tlg_log_path());
        $out['LOG_EXISTS'] = is_file(md_tlg_log_path()) ? 1 : 0;
        $out['COUNT_ROW'] = (int)$this->config['TLG_COUNT_ROW'];
        $out['PLAYER'] = (int)$this->config['TLG_PLAYER'];
        $out['REG_USER'] = (int)$this->config['TLG_REG_USER'];
        $out['HISTORY_ONLY_ERRORS'] = (int)$this->config['TLG_HISTORY_ONLY_ERRORS'];
        $out['HISTORY_DAYS'] = (int)$this->config['TLG_HISTORY_DAYS'];
        $out['SAVE_UNICODE'] = (int)$this->config['TLG_SAVE_UNICODE'];

        $out['WEBHOOK'] = (int)$this->config['TLG_WEBHOOK'];
        $out['WEBHOOK_URL'] = md_tlg_h($this->config['TLG_WEBHOOK_URL'] !== '' ? $this->config['TLG_WEBHOOK_URL'] : rtrim(BASE_URL, '/') . '/webhook_telegram.php');
        $out['WEBHOOK_URL_DEFAULT'] = md_tlg_h(rtrim(BASE_URL, '/') . '/webhook_telegram.php');
        $out['WEBHOOK_SECRET'] = md_tlg_h($this->config['TLG_WEBHOOK_SECRET']);
        $out['WEBHOOK_SECRET_SET'] = (string)$this->config['TLG_WEBHOOK_SECRET'] !== '' ? 1 : 0;
        $out['WEBHOOK_CERT'] = md_tlg_h($this->config['TLG_WEBHOOK_CERT']);
        $out['WEBHOOK_MAX_CONN'] = (int)$this->config['TLG_WEBHOOK_MAX_CONN'];
        $out['WEBHOOK_DROP_PENDING'] = (int)$this->config['TLG_WEBHOOK_DROP_PENDING'];

        $allowed = $this->allowedUpdatesArray();
        $allowedList = array();
        foreach (md_tlg_allowed_updates() as $value => $label) {
            $allowedList[] = array(
                'VALUE' => $value,
                'LABEL' => $label,
                'CHECKED' => in_array($value, $allowed, true) ? 1 : 0,
            );
        }
        $out['ALLOWED_UPDATES'] = $allowedList;
        $out['ALLOWED_UPDATES_COUNT'] = count($allowed);

        $out['BOT_NAME'] = md_tlg_h($this->config['TLG_BOT_NAME']);
        $out['BOT_USERNAME'] = md_tlg_h($this->config['TLG_BOT_USERNAME']);
        $out['BOT_ID'] = md_tlg_h($this->config['TLG_BOT_ID']);
        $out['BOT_AVATAR'] = md_tlg_bot_avatar($this->config['TLG_BOT_ID']);
        $out['BOT_LINK'] = $this->config['TLG_BOT_USERNAME'] !== '' ? 'https://t.me/' . rawurlencode($this->config['TLG_BOT_USERNAME']) : '';
        $out['BOT_CHECKED_HUMAN'] = md_tlg_relative_time($this->config['TLG_BOT_CHECKED']);
        $out['LAST_CHECK_HUMAN'] = md_tlg_relative_time($this->config['TLG_LAST_CHECK']);
        $out['LAST_ERROR'] = md_tlg_h($this->config['TLG_LAST_ERROR']);
        $out['LAST_ERROR_HUMAN'] = md_tlg_relative_time($this->config['TLG_LAST_ERROR_TIME']);

        $out['EXAMPLE_BOT_API'] = md_tlg_h(md_tlg_example_bot_api_command($this->config));
        $out['EXAMPLE_NGINX'] = md_tlg_h(md_tlg_example_nginx_stream());
        $out['SETTINGS_TAB'] = gr('section', 'trim');
        $out['OK_MSG'] = md_tlg_h(gr('ok_msg'));
    }

    /**
     * Persist the settings form. On validation errors $out gets SAVE_ERRORS and
     * the submitted values stay in $this->config so the form is not lost.
     *
     * @param array $out
     * @return bool
     */
    protected function saveSettings(&$out)
    {
        $errors = array();
        $oldToken = (string)$this->config['TLG_TOKEN'];
        $oldTransport = (string)$this->config['TLG_TRANSPORT'];
        $oldWebhook = (int)$this->config['TLG_WEBHOOK'];

        $token = trim((string)gr('tlg_token'));
        if ($token !== '' && !md_tlg_token_is_valid($token)) {
            $errors[] = 'Токен не похож на токен BotFather (ожидается вида 123456789:AA...).';
        }
        if ($token !== '') {
            $this->config['TLG_TOKEN'] = $token;
        }

        $transport = trim((string)gr('tlg_transport'));
        $transportOptions = md_tlg_transport_options();
        if (!isset($transportOptions[$transport])) {
            $transport = 'direct';
            $errors[] = 'Неизвестный способ подключения.';
        }
        $this->config['TLG_TRANSPORT'] = $transport;

        $this->config['TLG_API_BASE'] = trim((string)gr('tlg_api_base'));
        if ($transport === 'local_api' && $this->config['TLG_API_BASE'] === '') {
            $errors[] = 'Для локального Bot API укажите базовый URL, например http://127.0.0.1:8081.';
        }

        $this->config['TLG_PROXY_HOST'] = trim((string)gr('tlg_proxy_host'));
        $port = (int)gr('tlg_proxy_port');
        $this->config['TLG_PROXY_PORT'] = $port > 0 ? min(65535, $port) : 1080;
        if (in_array($transport, array('http', 'https', 'socks5', 'socks5h'), true) && $this->config['TLG_PROXY_HOST'] === '') {
            $errors[] = 'Для выбранного прокси укажите хост.';
        }
        $this->config['TLG_PROXY_LOGIN'] = trim((string)gr('tlg_proxy_login'));
        $password = (string)gr('tlg_proxy_password');
        if ($password !== '') {
            $this->config['TLG_PROXY_PASSWORD'] = $password;
        }
        if ((int)gr('tlg_proxy_password_clear') === 1) {
            $this->config['TLG_PROXY_PASSWORD'] = '';
        }
        $this->config['TLG_PROXY_MT_URL'] = trim((string)gr('tlg_proxy_mt_url'));
        $this->config['TLG_MT_API_ID'] = trim((string)gr('tlg_mt_api_id'));
        $apiHash = trim((string)gr('tlg_mt_api_hash'));
        if ($apiHash !== '') {
            $this->config['TLG_MT_API_HASH'] = $apiHash;
        }

        // Keep the pre-1.0 proxy keys in sync: third-party snippets still read
        // them, and the API client itself accepts them as a fallback.
        $legacyProxyTypes = array('http' => 1, 'socks5' => 2, 'socks5h' => 3, 'https' => 4);
        if (isset($legacyProxyTypes[$this->config['TLG_TRANSPORT']]) && $this->config['TLG_PROXY_HOST'] !== '') {
            $this->config['TLG_USEPROXY'] = 1;
            $this->config['TLG_PROXY_TYPE'] = $legacyProxyTypes[$this->config['TLG_TRANSPORT']];
            $this->config['TLG_PROXY_URL'] = $this->config['TLG_PROXY_HOST'] . ':' . $this->config['TLG_PROXY_PORT'];
        } else {
            $this->config['TLG_USEPROXY'] = 0;
        }

        $httpTimeout = (int)gr('tlg_http_timeout');
        $this->config['TLG_HTTP_TIMEOUT'] = $httpTimeout >= 3 ? min(120, $httpTimeout) : 20;
        $pollTimeout = (int)gr('tlg_timeout');
        $this->config['TLG_TIMEOUT'] = $pollTimeout >= 0 ? min(60, $pollTimeout) : 30;
        $ip = trim((string)gr('tlg_ipresolv'));
        $this->config['TLG_IPRESOLV'] = in_array($ip, array('any', 'ipv4', 'ipv6'), true) ? $ip : 'any';
        $this->config['TLG_SSL_VERIFY'] = (int)gr('tlg_ssl_verify') ? 1 : 0;
        $this->config['TLG_DEBUG'] = max(0, min(2, (int)gr('tlg_debug')));
        $storage = trim((string)gr('tlg_storage'));
        $this->config['TLG_STORAGE'] = $storage !== '' ? $storage : 'cms/cached/telegram';
        $countRow = (int)gr('tlg_count_row');
        $this->config['TLG_COUNT_ROW'] = $countRow > 0 ? min(10, $countRow) : 3;
        $this->config['TLG_PLAYER'] = (int)gr('tlg_player') === 2 ? 2 : 1;
        $this->config['TLG_REG_USER'] = (int)gr('tlg_reg_user') ? 1 : 0;
        $this->config['TLG_HISTORY_ONLY_ERRORS'] = (int)gr('tlg_history_only_errors') ? 1 : 0;
        $days = (int)gr('tlg_history_days');
        $this->config['TLG_HISTORY_DAYS'] = $days > 0 ? min(365, $days) : 7;
        $this->config['TLG_SAVE_UNICODE'] = (int)gr('tlg_save_unicode') ? 1 : 0;
        if ($this->config['TLG_SAVE_UNICODE']) {
            $this->ensureHistoryUnicode();
        }

        $this->config['TLG_WEBHOOK'] = (int)gr('tlg_webhook') ? 1 : 0;
        $this->config['TLG_WEBHOOK_URL'] = trim((string)gr('tlg_webhook_url'));
        if ($this->config['TLG_WEBHOOK'] && $this->config['TLG_WEBHOOK_URL'] === '') {
            $this->config['TLG_WEBHOOK_URL'] = rtrim(BASE_URL, '/') . '/webhook_telegram.php';
        }
        $secret = trim((string)gr('tlg_webhook_secret'));
        if ($secret !== '') {
            $this->config['TLG_WEBHOOK_SECRET'] = $secret;
        } elseif ($this->config['TLG_WEBHOOK']) {
            $this->config['TLG_WEBHOOK_SECRET'] = $this->generateSecret();
        }
        $this->config['TLG_WEBHOOK_CERT'] = trim((string)gr('tlg_webhook_cert'));
        $maxConn = (int)gr('tlg_webhook_max_conn');
        $this->config['TLG_WEBHOOK_MAX_CONN'] = $maxConn > 0 ? min(100, $maxConn) : 40;
        $this->config['TLG_WEBHOOK_DROP_PENDING'] = (int)gr('tlg_webhook_drop_pending') ? 1 : 0;

        $allowed = $this->parseAllowedUpdates(gr('allowed_updates'));
        if (empty($allowed)) {
            $allowed = $this->parseAllowedUpdates((string)$this->config['TLG_ALLOWED_UPDATES']);
        }
        $this->config['TLG_ALLOWED_UPDATES'] = implode(',', $allowed);

        if (!empty($errors)) {
            $out['SAVE_ERRORS'] = array_map('md_tlg_h', $errors);
            $out['ERR_MSG'] = 'Настройки не сохранены: ' . implode(' ', $errors);
            return false;
        }

        if ($oldToken !== (string)$this->config['TLG_TOKEN']) {
            $this->config['TLG_BOT_ID'] = '';
            $this->config['TLG_BOT_NAME'] = '';
            $this->config['TLG_BOT_USERNAME'] = '';
            $this->config['TLG_BOT_CHECKED'] = '';
            $this->config['TLG_LAST_UPDATE_ID'] = 0;
        }

        $this->config['TLG_SETUP_DONE'] = trim((string)$this->config['TLG_TOKEN']) !== '' ? 1 : 0;
        $this->config['TLG_SCHEMA_VERSION'] = self::SCHEMA_VERSION;
        $this->saveConfig();

        if ($oldTransport !== (string)$this->config['TLG_TRANSPORT'] || $oldWebhook !== (int)$this->config['TLG_WEBHOOK']) {
            setGlobal('cycle_telegramControl', 'restart');
        }
        if ($oldTransport !== (string)$this->config['TLG_TRANSPORT']) {
            setGlobal('cycle_telegramControl', 'restart');
        }
        $this->refreshApi();
        return true;
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    protected function parseAllowedUpdates($value)
    {
        $list = array();
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = explode(',', (string)$value);
        }
        $known = md_tlg_allowed_updates();
        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item !== '' && isset($known[$item]) && !in_array($item, $list, true)) {
                $list[] = $item;
            }
        }
        return $list;
    }

    /**
     * @return array<int,string>
     */
    protected function allowedUpdatesArray()
    {
        $list = $this->parseAllowedUpdates(isset($this->config['TLG_ALLOWED_UPDATES']) ? $this->config['TLG_ALLOWED_UPDATES'] : '');
        return $list;
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * @param string $viewMode
     * @return void
     */
    protected function handleAction($viewMode)
    {
        $id = (int)$this->id;
        switch ($viewMode) {
            case 'delete_user':
                $this->delete_user($id);
                $this->redirect('?tab=users&ok_msg=' . urlencode('Пользователь удалён'));
                break;
            case 'delete_cmd':
                $this->delete_cmd($id);
                $this->redirect('?tab=commands&ok_msg=' . urlencode('Команда удалена'));
                break;
            case 'delete_event':
                $this->delete_event($id);
                $this->redirect('?tab=events&ok_msg=' . urlencode('Событие удалено'));
                break;
            case 'delete_history':
                $this->delete_history($id);
                $this->redirect('?tab=history&ok_msg=' . urlencode('Запись удалена'));
                break;
            case 'clear_history':
                SQLExec('DELETE FROM tlg_history');
                $this->redirect('?tab=history&ok_msg=' . urlencode('История очищена'));
                break;
            case 'clear_log':
                $path = md_tlg_log_path();
                if (is_file($path)) {
                    @unlink($path);
                }
                $this->redirect('?tab=log&ok_msg=' . urlencode('Журнал за сегодня очищен'));
                break;
            case 'toggle_event':
                $record = $id > 0 ? SQLSelectOne('SELECT * FROM tlg_event WHERE ID=' . $id) : null;
                if ($record) {
                    $record['ENABLE'] = empty($record['ENABLE']) ? 1 : 0;
                    SQLUpdate('tlg_event', $record);
                    $this->redirect('?tab=events&ok_msg=' . urlencode($record['ENABLE'] ? 'Событие включено' : 'Событие выключено'));
                }
                $this->redirect('?tab=events');
                break;
        }
        $this->redirect('?');
    }

    /**
     * @param string $viewMode
     * @return void
     */
    protected function handleUtilityAction($viewMode)
    {
        switch ($viewMode) {
            case 'export_command':
                $this->exportRecord('tlg_cmd', (int)$this->id, 'telegram_command');
                break;
            case 'export_event':
                $this->exportRecord('tlg_event', (int)$this->id, 'telegram_event');
                break;
            case 'export_history':
                $this->exportHistory();
                break;
            case 'import_command':
                $this->importRecord('tlg_cmd', 'commands');
                break;
            case 'import_event':
                $this->importRecord('tlg_event', 'events');
                break;
            case 'sync_commands':
                $result = $this->syncBotCommands();
                $message = !empty($result['ok']) ? 'Меню команд обновлено: ' . (int)$result['count'] : 'Не удалось обновить меню: ' . (isset($result['description']) ? $result['description'] : 'ошибка');
                $this->redirect('?tab=commands&' . (!empty($result['ok']) ? 'ok_msg=' : 'err_msg=') . urlencode($message));
                break;
            case 'refresh_bot':
                $this->init(true);
                $this->redirect('?tab=overview&ok_msg=' . urlencode('Данные бота обновлены'));
                break;
            case 'refresh_users':
                $count = $this->refreshAllUsers();
                $this->redirect('?tab=users&ok_msg=' . urlencode('Обновлено профилей: ' . $count));
                break;
        }
        $this->redirect('?');
    }

    /**
     * @param string $table
     * @param int    $id
     * @param string $prefix
     * @return void
     */
    protected function exportRecord($table, $id, $prefix)
    {
        $allowed = array('tlg_cmd', 'tlg_event');
        if (!in_array($table, $allowed, true) || $id <= 0) {
            $this->redirect('?');
        }
        $record = SQLSelectOne('SELECT * FROM ' . $table . ' WHERE ID=' . $id);
        if (!$record) {
            $this->redirect('?');
        }
        unset($record['ID']);
        $filename = $prefix . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$record['TITLE']) . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @param string $table
     * @param string $tab
     * @return void
     */
    protected function importRecord($table, $tab)
    {
        $allowed = array('tlg_cmd', 'tlg_event');
        if (!in_array($table, $allowed, true)) {
            $this->redirect('?');
        }
        $overwrite = (bool)(int)gr('overwrite');
        $raw = '';
        if (isset($_FILES['import_file']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
            $raw = (string)file_get_contents($_FILES['import_file']['tmp_name']);
        } else {
            $raw = (string)gr('import_json');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['TITLE'])) {
            $this->redirect('?tab=' . $tab . '&err_msg=' . urlencode('Файл не содержит корректного JSON с полем TITLE'));
        }

        $existing = SQLSelectOne("SELECT * FROM " . $table . " WHERE TITLE='" . DBSafe($data['TITLE']) . "'");
        unset($data['ID']);
        $allowedFields = $table === 'tlg_cmd'
            ? array('TITLE', 'ALIASES', 'DESCRIPTION', 'CODE', 'ACCESS', 'SHOW_MODE', 'LINKED_OBJECT', 'LINKED_PROPERTY', 'CONDITION', 'CONDITION_VALUE', 'PRIORITY', 'PARSE_MODE')
            : array('TITLE', 'DESCRIPTION', 'TYPE_EVENT', 'ENABLE', 'CODE');
        $clean = array();
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $clean[$field] = is_scalar($data[$field]) ? (string)$data[$field] : '';
            }
        }
        if ($existing && !empty($existing['ID'])) {
            if ($overwrite) {
                $clean['ID'] = (int)$existing['ID'];
                SQLUpdate($table, $clean);
                $message = 'Запись обновлена';
            } else {
                $clean['TITLE'] = $clean['TITLE'] . '_copy';
                SQLInsert($table, $clean);
                $message = 'Создана копия записи';
            }
        } else {
            SQLInsert($table, $clean);
            $message = 'Запись импортирована';
        }
        $this->redirect('?tab=' . $tab . '&ok_msg=' . urlencode($message));
    }

    /**
     * @return void
     */
    protected function exportHistory()
    {
        $records = SQLSelect('SELECT * FROM tlg_history ORDER BY CREATED DESC, ID DESC LIMIT 5000');
        $rows = array();
        foreach ((array)$records as $record) {
            $user = SQLSelectOne("SELECT NAME, USERNAME FROM tlg_user WHERE USER_ID='" . DBSafe($record['USER_ID']) . "'");
            $rows[] = array(
                $record['ID'],
                $record['CREATED'],
                md_tlg_direction_meta($record['DIRECTION'])['label'],
                md_tlg_message_type_meta($record['TYPE'])['label'],
                $record['USER_ID'],
                isset($user['NAME']) ? $user['NAME'] : '',
                isset($user['USERNAME']) ? $user['USERNAME'] : '',
                $record['MESSAGE'],
            );
        }
        md_tlg_csv('telegram_history_' . date('Y-m-d_H-i') . '.csv', array('ID', 'Дата', 'Направление', 'Тип', 'Chat ID', 'Пользователь', 'Username', 'Сообщение'), $rows);
    }

    // -------------------------------------------------------------------------
    // Data for list pages
    // -------------------------------------------------------------------------

    /**
     * @param array $out
     * @return void
     */
    protected function prepareCommon(&$out)
    {
        if (!isset($out['OK_MSG'])) {
            $out['OK_MSG'] = md_tlg_h(gr('ok_msg'));
        }
        if (!isset($out['ERR_MSG'])) {
            $out['ERR_MSG'] = md_tlg_h(gr('err_msg'));
        }

        $users = SQLSelectOne('SELECT COUNT(*) AS CNT FROM tlg_user');
        $admins = SQLSelectOne('SELECT COUNT(*) AS CNT FROM tlg_user WHERE ADMIN=1');
        $commands = SQLSelectOne('SELECT COUNT(*) AS CNT FROM tlg_cmd');
        $events = SQLSelectOne('SELECT COUNT(*) AS CNT FROM tlg_event');
        $eventsOn = SQLSelectOne('SELECT COUNT(*) AS CNT FROM tlg_event WHERE ENABLE=1');
        $in24 = SQLSelectOne("SELECT COUNT(*) AS CNT FROM tlg_history WHERE DIRECTION=0 AND CREATED >= NOW() - INTERVAL 1 DAY");
        $out24 = SQLSelectOne("SELECT COUNT(*) AS CNT FROM tlg_history WHERE DIRECTION=1 AND CREATED >= NOW() - INTERVAL 1 DAY");
        $err24 = SQLSelectOne("SELECT COUNT(*) AS CNT FROM tlg_history WHERE DIRECTION=2 AND CREATED >= NOW() - INTERVAL 1 DAY");
        $last = SQLSelectOne('SELECT CREATED FROM tlg_history ORDER BY CREATED DESC, ID DESC LIMIT 1');

        $out['USERS_TOTAL'] = (int)$users['CNT'];
        $out['USERS_ADMINS'] = (int)$admins['CNT'];
        $out['COMMANDS_TOTAL'] = (int)$commands['CNT'];
        $out['EVENTS_TOTAL'] = (int)$events['CNT'];
        $out['EVENTS_ENABLED'] = (int)$eventsOn['CNT'];
        $out['MSG_IN_24H'] = (int)$in24['CNT'];
        $out['MSG_OUT_24H'] = (int)$out24['CNT'];
        $out['MSG_ERR_24H'] = (int)$err24['CNT'];
        $out['LAST_ACTIVITY_HUMAN'] = md_tlg_relative_time(isset($last['CREATED']) ? $last['CREATED'] : '');
        $out['LAST_ACTIVITY_EXACT'] = isset($last['CREATED']) ? (string)$last['CREATED'] : '';

        $out['TOKEN_SET'] = trim((string)$this->config['TLG_TOKEN']) !== '' ? 1 : 0;
        $out['TOKEN_MASK'] = md_tlg_mask_token((string)$this->config['TLG_TOKEN']);
        $out['TOKEN_VALID'] = md_tlg_token_is_valid((string)$this->config['TLG_TOKEN']) ? 1 : 0;
        $out['BOT_NAME'] = md_tlg_h($this->config['TLG_BOT_NAME']);
        $out['BOT_USERNAME'] = md_tlg_h($this->config['TLG_BOT_USERNAME']);
        $out['BOT_ID'] = md_tlg_h($this->config['TLG_BOT_ID']);
        $out['BOT_AVATAR'] = md_tlg_bot_avatar($this->config['TLG_BOT_ID']);
        $out['BOT_LINK'] = $this->config['TLG_BOT_USERNAME'] !== '' ? 'https://t.me/' . rawurlencode($this->config['TLG_BOT_USERNAME']) : '';
        $out['BOT_TITLE'] = $out['BOT_NAME'] !== '' ? $out['BOT_NAME'] : 'Telegram-бот';
        $out['BOT_SUBTITLE'] = $out['BOT_USERNAME'] !== '' ? '@' . $out['BOT_USERNAME'] : 'Бот ещё не проверен';
        $out['TRANSPORT_LABEL'] = md_tlg_h($this->api->describeTransport());
        $out['TRANSPORT'] = md_tlg_h($this->config['TLG_TRANSPORT']);
        $out['WEBHOOK_MODE'] = (int)$this->config['TLG_WEBHOOK'] ? 1 : 0;
        $out['MODE_LABEL'] = (int)$this->config['TLG_WEBHOOK'] ? 'Webhook' : 'Long polling';
        $out['MODE_BADGE'] = (int)$this->config['TLG_WEBHOOK'] ? 'info' : 'neutral';
        $out['BOT_CHECKED_HUMAN'] = md_tlg_relative_time($this->config['TLG_BOT_CHECKED']);
        $out['BOT_CHECKED'] = $this->config['TLG_BOT_CHECKED'] !== '' ? 1 : 0;
        $out['LAST_ERROR'] = md_tlg_h($this->config['TLG_LAST_ERROR']);
        $out['LAST_ERROR_HUMAN'] = md_tlg_relative_time($this->config['TLG_LAST_ERROR_TIME']);

        $lastRun = (int)gg('cycle_telegramRun');
        $timeout = max(30, (int)$this->config['TLG_TIMEOUT']);
        $running = $lastRun > 0 && (time() - $lastRun) < ($timeout * 2 + 60);
        if ((int)$this->config['TLG_WEBHOOK']) {
            $running = true;
        }
        $out['CYCLE_RUNNING'] = $running ? 1 : 0;
        $out['CYCLE_STATE'] = $running ? 'online' : 'offline';
        $out['CYCLE_LABEL'] = (int)$this->config['TLG_WEBHOOK'] ? 'Webhook активен' : ($running ? 'Цикл запущен' : 'Цикл остановлен');
        $out['CYCLE_LAST_RUN_HUMAN'] = $lastRun > 0 ? md_tlg_relative_time(date('Y-m-d H:i:s', $lastRun)) : 'никогда';

        $out['SETUP_TOKEN'] = $out['TOKEN_SET'] ? 1 : 0;
        $out['SETUP_BOT'] = $out['BOT_CHECKED'] ? 1 : 0;
        $out['SETUP_USERS'] = $out['USERS_TOTAL'] > 0 ? 1 : 0;
        $out['SETUP_COMMANDS'] = $out['COMMANDS_TOTAL'] > 0 ? 1 : 0;
        $out['SETUP_CHANNEL'] = ($out['CYCLE_RUNNING'] || $out['WEBHOOK_MODE']) ? 1 : 0;
        $out['SETUP_DONE_COUNT'] = (int)$out['SETUP_TOKEN'] + (int)$out['SETUP_BOT'] + (int)$out['SETUP_USERS'] + (int)$out['SETUP_COMMANDS'] + (int)$out['SETUP_CHANNEL'];
        $out['SETUP_TOTAL'] = 5;
        $out['SETUP_COMPLETE'] = $out['SETUP_DONE_COUNT'] >= 5 ? 1 : 0;
    }

    /**
     * @param array $out
     * @return void
     */
    protected function prepareTabs(&$out)
    {
        $active = isset($out['ACTIVE_TAB']) ? (string)$out['ACTIVE_TAB'] : 'overview';
        $tabs = array(
            array('KEY' => 'overview', 'TITLE' => 'Обзор', 'ICON' => 'dashboard', 'URL' => '?tab=overview', 'COUNT' => ''),
            array('KEY' => 'users', 'TITLE' => 'Пользователи', 'ICON' => 'users', 'URL' => '?tab=users', 'COUNT' => (int)$out['USERS_TOTAL']),
            array('KEY' => 'commands', 'TITLE' => 'Команды', 'ICON' => 'terminal-2', 'URL' => '?tab=commands', 'COUNT' => (int)$out['COMMANDS_TOTAL']),
            array('KEY' => 'events', 'TITLE' => 'События', 'ICON' => 'bolt', 'URL' => '?tab=events', 'COUNT' => (int)$out['EVENTS_ENABLED']),
            array('KEY' => 'history', 'TITLE' => 'История', 'ICON' => 'list-details', 'URL' => '?tab=history', 'COUNT' => ''),
            array('KEY' => 'log', 'TITLE' => 'Журнал', 'ICON' => 'file-text', 'URL' => '?tab=log', 'COUNT' => ''),
            array('KEY' => 'help', 'TITLE' => 'Помощь', 'ICON' => 'help-circle', 'URL' => '?tab=help', 'COUNT' => ''),
        );
        foreach ($tabs as $index => $tab) {
            $tabs[$index]['ACTIVE'] = $tab['KEY'] === $active ? 1 : 0;
        }
        $out['TABS'] = $tabs;
    }

    /**
     * @param array $out
     * @return void
     */
    protected function prepareOverview(&$out)
    {
        $recent = SQLSelect('SELECT * FROM tlg_history ORDER BY CREATED DESC, ID DESC LIMIT 8');
        $out['RECENT'] = $this->decorateHistoryRows((array)$recent);
        $out['HAS_RECENT'] = !empty($out['RECENT']) ? 1 : 0;

        $commands = SQLSelect('SELECT * FROM tlg_cmd ORDER BY RUNS DESC, PRIORITY DESC, TITLE ASC LIMIT 5');
        $out['TOP_COMMANDS'] = array();
        foreach ((array)$commands as $command) {
            $out['TOP_COMMANDS'][] = array(
                'TITLE' => md_tlg_h($command['TITLE']),
                'RUNS' => (int)$command['RUNS'],
                'LAST_RUN_HUMAN' => md_tlg_relative_time($command['LAST_RUN']),
                'URL' => '?view_mode=edit_cmd&id=' . (int)$command['ID'],
            );
        }
        $out['HAS_TOP_COMMANDS'] = !empty($out['TOP_COMMANDS']) ? 1 : 0;

        $events = SQLSelect('SELECT * FROM tlg_event WHERE ENABLE=1 ORDER BY LAST_RUN DESC, ID ASC LIMIT 5');
        $out['ACTIVE_EVENTS'] = array();
        foreach ((array)$events as $event) {
            $meta = md_tlg_event_type_meta($event['TYPE_EVENT']);
            $out['ACTIVE_EVENTS'][] = array(
                'TITLE' => md_tlg_h($event['TITLE']),
                'TYPE_LABEL' => md_tlg_h($meta['label']),
                'TYPE_ICON' => $meta['icon'],
                'TYPE_TONE' => $meta['tone'],
                'RUNS' => (int)$event['RUNS'],
                'LAST_RUN_HUMAN' => md_tlg_relative_time($event['LAST_RUN']),
                'URL' => '?view_mode=edit_event&id=' . (int)$event['ID'],
            );
        }
        $out['HAS_ACTIVE_EVENTS'] = !empty($out['ACTIVE_EVENTS']) ? 1 : 0;

        $errors = SQLSelect("SELECT * FROM tlg_history WHERE DIRECTION=2 ORDER BY CREATED DESC, ID DESC LIMIT 5");
        $out['RECENT_ERRORS'] = array();
        foreach ((array)$errors as $error) {
            $out['RECENT_ERRORS'][] = array(
                'MESSAGE' => md_tlg_h(md_tlg_truncate($error['MESSAGE'], 160)),
                'CREATED_HUMAN' => md_tlg_relative_time($error['CREATED']),
                'CREATED_EXACT' => md_tlg_h($error['CREATED']),
            );
        }
        $out['HAS_RECENT_ERRORS'] = !empty($out['RECENT_ERRORS']) ? 1 : 0;

        $out['WEBHOOK_URL_VALUE'] = md_tlg_h($this->config['TLG_WEBHOOK_URL'] !== '' ? $this->config['TLG_WEBHOOK_URL'] : rtrim(BASE_URL, '/') . '/webhook_telegram.php');
        $out['ALLOWED_UPDATES_COUNT'] = count($this->allowedUpdatesArray());
        $out['STORAGE_EFFECTIVE'] = md_tlg_h(md_tlg_storage_dir($this->config));
        $out['STORAGE_LABEL'] = md_tlg_h($this->config['TLG_STORAGE']);
        $out['LAST_CHECK_HUMAN'] = md_tlg_relative_time($this->config['TLG_LAST_CHECK']);
        $out['SETUP_HINT'] = $out['TOKEN_SET'] ? '' : 'Начните с токена бота: откройте настройки и вставьте токен от @BotFather.';

        $testUser = SQLSelectOne('SELECT USER_ID, NAME FROM tlg_user WHERE ADMIN=1 ORDER BY ID ASC LIMIT 1');
        if (!$testUser) {
            $testUser = SQLSelectOne('SELECT USER_ID, NAME FROM tlg_user ORDER BY ID ASC LIMIT 1');
        }
        $out['TEST_CHAT_ID'] = $testUser ? md_tlg_h($testUser['USER_ID']) : '';
        $out['TEST_CHAT_NAME'] = $testUser && $testUser['NAME'] !== '' ? md_tlg_h($testUser['NAME']) : '';
        $out['HAS_TEST_USER'] = $testUser ? 1 : 0;
    }

    /**
     * @param array $out
     * @return void
     */
    protected function prepareUsers(&$out)
    {
        $q = trim((string)gr('q'));
        $access = trim((string)gr('access'));
        $sort = trim((string)gr('sort'));
        $page = max(1, (int)gr('page'));
        $perPage = 30;

        $where = array('1');
        if ($q !== '') {
            $safe = DBSafe($q);
            $where[] = "(NAME LIKE '%" . $safe . "%' OR USERNAME LIKE '%" . $safe . "%' OR USER_ID LIKE '%" . $safe . "%')";
        }
        switch ($access) {
            case 'admin':
                $where[] = 'ADMIN=1';
                break;
            case 'history':
                $where[] = 'HISTORY=1';
                break;
            case 'cmd':
                $where[] = 'CMD=1';
                break;
            case 'none':
                $where[] = '(ADMIN=0 AND HISTORY=0 AND CMD=0 AND PATTERNS=0 AND DOWNLOAD=0 AND PLAY=0)';
                break;
        }
        $whereSql = implode(' AND ', $where);

        $allowedSort = array('name' => 'NAME', 'chat' => 'USER_ID', 'created' => 'CREATED', 'seen' => 'LAST_SEEN', 'id' => 'ID');
        if (!isset($allowedSort[$sort])) {
            $sort = 'seen';
        }
        $orderSql = $allowedSort[$sort] . ($sort === 'name' ? ' ASC' : ' DESC');

        $totalRecord = SQLSelectOne('SELECT COUNT(*) AS CNT FROM tlg_user WHERE ' . $whereSql);
        $total = isset($totalRecord['CNT']) ? (int)$totalRecord['CNT'] : 0;
        $offset = ($page - 1) * $perPage;
        $records = SQLSelect('SELECT * FROM tlg_user WHERE ' . $whereSql . ' ORDER BY ' . $orderSql . ' LIMIT ' . $offset . ', ' . $perPage);

        $members = array();
        $memberRows = SQLSelect('SELECT ID, NAME FROM users ORDER BY NAME');
        foreach ((array)$memberRows as $member) {
            $members[(int)$member['ID']] = $member['NAME'];
        }

        $out['USERS'] = array();
        foreach ((array)$records as $record) {
            $permissions = array();
            if ((int)$record['ADMIN'] === 1) {
                $permissions[] = array('LABEL' => 'Админ', 'ICON' => 'shield-lock', 'TONE' => 'danger');
            }
            if ((int)$record['CMD'] === 1) {
                $permissions[] = array('LABEL' => 'Команды', 'ICON' => 'terminal-2', 'TONE' => 'primary');
            }
            if ((int)$record['HISTORY'] === 1) {
                $permissions[] = array('LABEL' => 'История', 'ICON' => 'bell', 'TONE' => 'info');
            }
            if ((int)$record['PATTERNS'] === 1) {
                $permissions[] = array('LABEL' => 'Шаблоны', 'ICON' => 'message-dots', 'TONE' => 'neutral');
            }
            if ((int)$record['DOWNLOAD'] === 1) {
                $permissions[] = array('LABEL' => 'Файлы', 'ICON' => 'download', 'TONE' => 'warning');
            }
            if ((int)$record['PLAY'] === 1) {
                $permissions[] = array('LABEL' => 'Голос', 'ICON' => 'player-play', 'TONE' => 'success');
            }
            if (empty($permissions)) {
                $permissions[] = array('LABEL' => 'Нет доступа', 'ICON' => 'lock', 'TONE' => 'neutral');
            }

            $name = trim((string)$record['NAME']);
            if ($name === '') {
                $name = $record['USERNAME'] !== '' ? '@' . $record['USERNAME'] : 'Chat ' . $record['USER_ID'];
            }
            $memberId = (int)$record['MEMBER_ID'];

            $out['USERS'][] = array(
                'ID' => (int)$record['ID'],
                'CHAT_ID' => md_tlg_h($record['USER_ID']),
                'NAME' => md_tlg_h($name),
                'USERNAME' => $record['USERNAME'] !== '' ? md_tlg_h('@' . $record['USERNAME']) : '',
                'HAS_USERNAME' => $record['USERNAME'] !== '' ? 1 : 0,
                'AVATAR' => md_tlg_user_avatar($record['USER_ID']),
                'INITIALS' => md_tlg_h(md_tlg_initials($name)),
                'IS_ADMIN' => (int)$record['ADMIN'],
                'PERMISSIONS' => $permissions,
                'MEMBER_NAME' => isset($members[$memberId]) ? md_tlg_h($members[$memberId]) : '',
                'HAS_MEMBER' => isset($members[$memberId]) ? 1 : 0,
                'LAST_SEEN_HUMAN' => md_tlg_relative_time($record['LAST_SEEN']),
                'LAST_SEEN_EXACT' => md_tlg_h($record['LAST_SEEN']),
                'CREATED_HUMAN' => md_tlg_relative_time($record['CREATED']),
                'IS_GROUP' => substr((string)$record['USER_ID'], 0, 1) === '-' ? 1 : 0,
                'EDIT_URL' => '?view_mode=edit_user&id=' . (int)$record['ID'],
                'DELETE_URL' => '?view_mode=delete_user&id=' . (int)$record['ID'],
            );
        }

        $out['TOTAL'] = $total;
        $out['FILTER_Q'] = md_tlg_h($q);
        $out['FILTER_ACCESS'] = md_tlg_h($access);
        $out['FILTER_SORT'] = md_tlg_h($sort);
        $out['HAS_FILTERS'] = ($q !== '' || $access !== '') ? 1 : 0;
        $out['RESET_URL'] = '?tab=users';
        $out['HAS_ROWS'] = !empty($out['USERS']) ? 1 : 0;
        $out += md_tlg_pagination($total, $page, $perPage, '?tab=users&q=' . urlencode($q) . '&access=' . urlencode($access) . '&sort=' . urlencode($sort));
        $out['PAGE_BASE'] = '?tab=users&q=' . urlencode($q) . '&access=' . urlencode($access) . '&sort=' . urlencode($sort);
        $out['ACCESS_ADMIN_COUNT'] = (int)$this->scalarQuery('SELECT COUNT(*) AS CNT FROM tlg_user WHERE ADMIN=1');
        $out['ACCESS_ALL_COUNT'] = $total;
    }

    /**
     * @param array $out
     * @return void
     */
    protected function prepareCommands(&$out)
    {
        $q = trim((string)gr('q'));
        $access = trim((string)gr('access'));
        $sort = trim((string)gr('sort'));
        $page = max(1, (int)gr('page'));
        $perPage = 30;

        $where = array('1');
        if ($q !== '') {
            $safe = DBSafe($q);
            $where[] = "(TITLE LIKE '%" . $safe . "%' OR DESCRIPTION LIKE '%" . $safe . "%' OR CODE LIKE '%" . $safe . "%' OR ALIASES LIKE '%" . $safe . "%')";
        }
        if ($access !== '') {
            $where[] = 'ACCESS=' . (int)$access;
        }
        $whereSql = implode(' AND ', $where);

        $allowedSort = array('priority' => 'PRIORITY DESC, TITLE ASC', 'title' => 'TITLE ASC', 'runs' => 'RUNS DESC, TITLE ASC', 'access' => 'ACCESS DESC, TITLE ASC');
        if (!isset($allowedSort[$sort])) {
            $sort = 'priority';
        }
        $orderSql = $allowedSort[$sort];

        $totalRecord = SQLSelectOne('SELECT COUNT(*) AS CNT FROM tlg_cmd WHERE ' . $whereSql);
        $total = isset($totalRecord['CNT']) ? (int)$totalRecord['CNT'] : 0;
        $offset = ($page - 1) * $perPage;
        $records = SQLSelect('SELECT * FROM tlg_cmd WHERE ' . $whereSql . ' ORDER BY ' . $orderSql . ' LIMIT ' . $offset . ', ' . $perPage);

        $accessMeta = array(
            0 => array('label' => 'Отключена', 'tone' => 'neutral'),
            1 => array('label' => 'Администраторы', 'tone' => 'danger'),
            2 => array('label' => 'Избранные', 'tone' => 'info'),
            3 => array('label' => 'Все', 'tone' => 'success'),
        );

        $out['COMMANDS'] = array();
        foreach ((array)$records as $record) {
            $usersCount = (int)$this->scalarQuery('SELECT COUNT(*) AS CNT FROM tlg_user_cmd WHERE CMD_ID=' . (int)$record['ID']);
            $accessValue = (int)$record['ACCESS'];
            $meta = isset($accessMeta[$accessValue]) ? $accessMeta[$accessValue] : $accessMeta[0];
            $out['COMMANDS'][] = array(
                'ID' => (int)$record['ID'],
                'TITLE' => md_tlg_h($record['TITLE']),
                'TITLE_RAW' => md_tlg_h($record['TITLE']),
                'ALIASES' => md_tlg_h($record['ALIASES']),
                'HAS_ALIASES' => trim((string)$record['ALIASES']) !== '' ? 1 : 0,
                'DESCRIPTION' => md_tlg_h($record['DESCRIPTION']),
                'CODE_PREVIEW' => md_tlg_h(md_tlg_truncate($record['CODE'], 120)),
                'HAS_CODE' => trim((string)$record['CODE']) !== '' ? 1 : 0,
                'ACCESS' => $accessValue,
                'ACCESS_LABEL' => $meta['label'],
                'ACCESS_TONE' => $meta['tone'],
                'USERS_COUNT' => $usersCount,
                'SHOW_MODE' => (int)$record['SHOW_MODE'],
                'SHOW_LABEL' => (int)$record['SHOW_MODE'] === 1 ? 'В клавиатуре' : ((int)$record['SHOW_MODE'] === 3 ? 'По условию' : 'Скрыта'),
                'SHOW_TONE' => (int)$record['SHOW_MODE'] === 1 ? 'success' : ((int)$record['SHOW_MODE'] === 3 ? 'warning' : 'neutral'),
                'PRIORITY' => (int)$record['PRIORITY'],
                'RUNS' => (int)$record['RUNS'],
                'LAST_RUN_HUMAN' => md_tlg_relative_time($record['LAST_RUN']),
                'EDIT_URL' => '?view_mode=edit_cmd&id=' . (int)$record['ID'],
                'DELETE_URL' => '?view_mode=delete_cmd&id=' . (int)$record['ID'],
                'EXPORT_URL' => '?view_mode=export_command&id=' . (int)$record['ID'],
            );
        }

        $out['TOTAL'] = $total;
        $out['FILTER_Q'] = md_tlg_h($q);
        $out['FILTER_ACCESS'] = md_tlg_h($access);
        $out['FILTER_SORT'] = md_tlg_h($sort);
        $out['HAS_FILTERS'] = ($q !== '' || $access !== '') ? 1 : 0;
        $out['RESET_URL'] = '?tab=commands';
        $out['HAS_ROWS'] = !empty($out['COMMANDS']) ? 1 : 0;
        $out += md_tlg_pagination($total, $page, $perPage, '?tab=commands&q=' . urlencode($q) . '&access=' . urlencode($access) . '&sort=' . urlencode($sort));
        $out['PAGE_BASE'] = '?tab=commands&q=' . urlencode($q) . '&access=' . urlencode($access) . '&sort=' . urlencode($sort);
    }

    /**
     * @param array $out
     * @return void
     */
    protected function prepareEvents(&$out)
    {
        $q = trim((string)gr('q'));
        $type = trim((string)gr('type'));
        $enabled = trim((string)gr('enabled'));
        $sort = trim((string)gr('sort'));
        $page = max(1, (int)gr('page'));
        $perPage = 30;

        $where = array('1');
        if ($q !== '') {
            $safe = DBSafe($q);
            $where[] = "(TITLE LIKE '%" . $safe . "%' OR DESCRIPTION LIKE '%" . $safe . "%' OR CODE LIKE '%" . $safe . "%')";
        }
        if ($type !== '') {
            $where[] = 'TYPE_EVENT=' . (int)$type;
        }
        if ($enabled !== '') {
            $where[] = 'ENABLE=' . (int)$enabled;
        }
        $whereSql = implode(' AND ', $where);

        $allowedSort = array('title' => 'TITLE ASC', 'runs' => 'RUNS DESC, TITLE ASC', 'type' => 'TYPE_EVENT ASC, TITLE ASC', 'last' => 'LAST_RUN DESC, TITLE ASC');
        if (!isset($allowedSort[$sort])) {
            $sort = 'type';
        }
        $orderSql = $allowedSort[$sort];

        $totalRecord = SQLSelectOne('SELECT COUNT(*) AS CNT FROM tlg_event WHERE ' . $whereSql);
        $total = isset($totalRecord['CNT']) ? (int)$totalRecord['CNT'] : 0;
        $offset = ($page - 1) * $perPage;
        $records = SQLSelect('SELECT * FROM tlg_event WHERE ' . $whereSql . ' ORDER BY ' . $orderSql . ' LIMIT ' . $offset . ', ' . $perPage);

        $out['EVENTS'] = array();
        foreach ((array)$records as $record) {
            $meta = md_tlg_event_type_meta($record['TYPE_EVENT']);
            $out['EVENTS'][] = array(
                'ID' => (int)$record['ID'],
                'TITLE' => md_tlg_h($record['TITLE']),
                'DESCRIPTION' => md_tlg_h($record['DESCRIPTION']),
                'TYPE' => (int)$record['TYPE_EVENT'],
                'TYPE_LABEL' => md_tlg_h($meta['label']),
                'TYPE_SHORT' => md_tlg_h($meta['short']),
                'TYPE_ICON' => $meta['icon'],
                'TYPE_TONE' => $meta['tone'],
                'ENABLED' => (int)$record['ENABLE'],
                'STATE' => (int)$record['ENABLE'] ? 'online' : 'offline',
                'STATE_LABEL' => (int)$record['ENABLE'] ? 'Включено' : 'Выключено',
                'CODE_PREVIEW' => md_tlg_h(md_tlg_truncate($record['CODE'], 120)),
                'HAS_CODE' => trim((string)$record['CODE']) !== '' ? 1 : 0,
                'RUNS' => (int)$record['RUNS'],
                'LAST_RUN_HUMAN' => md_tlg_relative_time($record['LAST_RUN']),
                'EDIT_URL' => '?view_mode=edit_event&id=' . (int)$record['ID'],
                'DELETE_URL' => '?view_mode=delete_event&id=' . (int)$record['ID'],
                'TOGGLE_URL' => '?view_mode=toggle_event&id=' . (int)$record['ID'],
                'EXPORT_URL' => '?view_mode=export_event&id=' . (int)$record['ID'],
            );
        }

        $typeOptions = array();
        foreach (md_tlg_event_types() as $value => $meta) {
            $typeOptions[] = array(
                'VALUE' => $value,
                'LABEL' => $meta['label'],
                'SELECTED' => (string)$type === (string)$value ? 1 : 0,
            );
        }
        $out['TYPE_OPTIONS'] = $typeOptions;
        $out['TOTAL'] = $total;
        $out['FILTER_Q'] = md_tlg_h($q);
        $out['FILTER_TYPE'] = md_tlg_h($type);
        $out['FILTER_ENABLED'] = md_tlg_h($enabled);
        $out['FILTER_SORT'] = md_tlg_h($sort);
        $out['HAS_FILTERS'] = ($q !== '' || $type !== '' || $enabled !== '') ? 1 : 0;
        $out['RESET_URL'] = '?tab=events';
        $out['HAS_ROWS'] = !empty($out['EVENTS']) ? 1 : 0;
        $out += md_tlg_pagination($total, $page, $perPage, '?tab=events&q=' . urlencode($q) . '&type=' . urlencode($type) . '&enabled=' . urlencode($enabled) . '&sort=' . urlencode($sort));
        $out['PAGE_BASE'] = '?tab=events&q=' . urlencode($q) . '&type=' . urlencode($type) . '&enabled=' . urlencode($enabled) . '&sort=' . urlencode($sort);
    }

    /**
     * @param array $out
     * @return void
     */
    protected function prepareHistory(&$out)
    {
        $q = trim((string)gr('q'));
        $direction = trim((string)gr('direction'));
        $type = trim((string)gr('type'));
        $chatId = trim((string)gr('chat_id'));
        $page = max(1, (int)gr('page'));
        $perPage = 50;

        $where = array('1');
        if ($q !== '') {
            $safe = DBSafe($q);
            $where[] = "(MESSAGE LIKE '%" . $safe . "%' OR USER_ID LIKE '%" . $safe . "%')";
        }
        if ($direction !== '') {
            $where[] = 'DIRECTION=' . (int)$direction;
        }
        if ($type !== '') {
            $where[] = 'TYPE=' . (int)$type;
        }
        if ($chatId !== '') {
            $where[] = "USER_ID='" . DBSafe($chatId) . "'";
        }
        $whereSql = implode(' AND ', $where);

        $totalRecord = SQLSelectOne('SELECT COUNT(*) AS CNT FROM tlg_history WHERE ' . $whereSql);
        $total = isset($totalRecord['CNT']) ? (int)$totalRecord['CNT'] : 0;
        $offset = ($page - 1) * $perPage;
        $records = SQLSelect('SELECT * FROM tlg_history WHERE ' . $whereSql . ' ORDER BY CREATED DESC, ID DESC LIMIT ' . $offset . ', ' . $perPage);
        $out['RESULT'] = $this->decorateHistoryRows((array)$records);
        $out['HAS_ROWS'] = !empty($out['RESULT']) ? 1 : 0;

        $out['STAT_IN'] = (int)$this->scalarQuery('SELECT COUNT(*) AS CNT FROM tlg_history WHERE DIRECTION=0');
        $out['STAT_OUT'] = (int)$this->scalarQuery('SELECT COUNT(*) AS CNT FROM tlg_history WHERE DIRECTION=1');
        $out['STAT_ERR'] = (int)$this->scalarQuery('SELECT COUNT(*) AS CNT FROM tlg_history WHERE DIRECTION=2');
        $out['STAT_TOTAL'] = (int)$this->scalarQuery('SELECT COUNT(*) AS CNT FROM tlg_history');
        $out['STAT_RETRY'] = (int)$this->scalarQuery('SELECT COUNT(*) AS CNT FROM tlg_history WHERE DIRECTION IN (3,4)');
        $out['STORAGE_DAYS'] = (int)$this->config['TLG_HISTORY_DAYS'];

        $out['FILTER_Q'] = md_tlg_h($q);
        $out['FILTER_DIRECTION'] = md_tlg_h($direction);
        $out['FILTER_TYPE'] = md_tlg_h($type);
        $out['FILTER_CHAT_ID'] = md_tlg_h($chatId);
        $out['HAS_FILTERS'] = ($q !== '' || $direction !== '' || $type !== '' || $chatId !== '') ? 1 : 0;
        $out['RESET_URL'] = '?tab=history';

        $typeOptions = array();
        foreach (array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22) as $typeValue) {
            $meta = md_tlg_message_type_meta($typeValue);
            $typeOptions[] = array(
                'VALUE' => $typeValue,
                'LABEL' => $meta['label'],
                'SELECTED' => (string)$type === (string)$typeValue ? 1 : 0,
            );
        }
        $out['TYPE_OPTIONS'] = $typeOptions;

        $chats = SQLSelect('SELECT USER_ID, MAX(CREATED) AS LAST_DATE FROM tlg_history WHERE USER_ID<>0 GROUP BY USER_ID ORDER BY LAST_DATE DESC LIMIT 50');
        $out['CHAT_OPTIONS'] = array();
        foreach ((array)$chats as $chat) {
            $user = SQLSelectOne("SELECT NAME, USERNAME FROM tlg_user WHERE USER_ID='" . DBSafe($chat['USER_ID']) . "'");
            $label = isset($user['NAME']) && $user['NAME'] !== '' ? $user['NAME'] : $chat['USER_ID'];
            if (isset($user['USERNAME']) && $user['USERNAME'] !== '') {
                $label .= ' (@' . $user['USERNAME'] . ')';
            }
            $out['CHAT_OPTIONS'][] = array(
                'VALUE' => md_tlg_h($chat['USER_ID']),
                'LABEL' => md_tlg_h($label),
                'SELECTED' => (string)$chatId === (string)$chat['USER_ID'] ? 1 : 0,
            );
        }

        $out['TOTAL'] = $total;
        $out += md_tlg_pagination($total, $page, $perPage, '?tab=history&q=' . urlencode($q) . '&direction=' . urlencode($direction) . '&type=' . urlencode($type) . '&chat_id=' . urlencode($chatId));
        $out['PAGE_BASE'] = '?tab=history&q=' . urlencode($q) . '&direction=' . urlencode($direction) . '&type=' . urlencode($type) . '&chat_id=' . urlencode($chatId);
        $out['EXPORT_URL'] = '?view_mode=export_history';
        $out['HISTORY_ONLY_ERRORS'] = (int)$this->config['TLG_HISTORY_ONLY_ERRORS'];
    }

    /**
     * @param array $rows
     * @return array
     */
    protected function decorateHistoryRows(array $rows)
    {
        $cache = array();
        $result = array();
        foreach ($rows as $row) {
            $chatId = (string)$row['USER_ID'];
            if (!isset($cache[$chatId])) {
                $cache[$chatId] = SQLSelectOne("SELECT NAME, USERNAME FROM tlg_user WHERE USER_ID='" . DBSafe($chatId) . "'");
            }
            $user = $cache[$chatId];
            $direction = md_tlg_direction_meta($row['DIRECTION']);
            $type = md_tlg_message_type_meta($row['TYPE']);
            $name = isset($user['NAME']) ? trim((string)$user['NAME']) : '';
            if ($name === '') {
                $name = isset($user['USERNAME']) && $user['USERNAME'] !== '' ? '@' . $user['USERNAME'] : ($chatId !== '0' ? $chatId : 'система');
            }
            $result[] = array(
                'ID' => (int)$row['ID'],
                'USER_ID' => md_tlg_h($chatId),
                'USER_NAME' => md_tlg_h($name),
                'USER_LINK' => isset($user['USERNAME']) && $user['USERNAME'] !== '' ? 'https://t.me/' . rawurlencode($user['USERNAME']) : '',
                'CREATED_HUMAN' => md_tlg_relative_time($row['CREATED']),
                'CREATED_EXACT' => md_tlg_h($row['CREATED']),
                'DIRECTION_LABEL' => $direction['label'],
                'DIRECTION_TONE' => $direction['tone'],
                'DIRECTION_ICON' => $direction['icon'],
                'TYPE_LABEL' => $type['label'],
                'TYPE_ICON' => $type['icon'],
                'MESSAGE' => md_tlg_h(md_tlg_truncate($row['MESSAGE'], 220)),
                'MESSAGE_FULL' => md_tlg_h(md_tlg_truncate($row['MESSAGE'], 2000)),
                'IS_ERROR' => (int)$row['DIRECTION'] === 2 ? 1 : 0,
                'DELETE_URL' => '?view_mode=delete_history&id=' . (int)$row['ID'],
            );
        }
        return $result;
    }

    /**
     * @param array $out
     * @return void
     */
    protected function prepareLog(&$out)
    {
        $filter = trim((string)gr('filter'));
        $limit = (int)gr('limit');
        if ($limit <= 0) {
            $limit = 200;
        }
        $log = md_tlg_log_lines($filter, $limit);
        $out['LOG_LINES'] = array();
        foreach ($log['lines'] as $line) {
            $out['LOG_LINES'][] = array(
                'TIME' => md_tlg_h($line['time']),
                'TEXT' => md_tlg_h($line['text']),
            );
        }
        $out['LOG_PATH'] = md_tlg_h($log['path']);
        $out['LOG_EXISTS'] = $log['exists'] ? 1 : 0;
        $out['LOG_SIZE'] = md_tlg_format_bytes($log['size']);
        $out['FILTER_Q'] = md_tlg_h($filter);
        $out['LIMIT'] = $limit;
        $out['HAS_LINES'] = !empty($out['LOG_LINES']) ? 1 : 0;
        $out['DEBUG_LEVEL'] = (int)$this->config['TLG_DEBUG'];
        $out['DEBUG_LABEL'] = (int)$this->config['TLG_DEBUG'] === 1 ? 'Debug' : ((int)$this->config['TLG_DEBUG'] === 2 ? 'Только ошибки' : 'Info');
    }

    /**
     * @param array $out
     * @return void
     */
    protected function prepareHelp(&$out)
    {
        $out['EXAMPLE_BOT_API'] = md_tlg_h(md_tlg_example_bot_api_command($this->config));
        $out['EXAMPLE_NGINX'] = md_tlg_h(md_tlg_example_nginx_stream());
        $out['BOT_USERNAME'] = md_tlg_h($this->config['TLG_BOT_USERNAME']);
        $out['BOT_LINK'] = $this->config['TLG_BOT_USERNAME'] !== '' ? 'https://t.me/' . rawurlencode($this->config['TLG_BOT_USERNAME']) : '';
        $out['WEBHOOK_URL_VALUE'] = md_tlg_h($this->config['TLG_WEBHOOK_URL'] !== '' ? $this->config['TLG_WEBHOOK_URL'] : rtrim(BASE_URL, '/') . '/webhook_telegram.php');
        $out['EVENT_TYPES'] = array();
        foreach (md_tlg_event_types() as $value => $meta) {
            $meta['VALUE'] = $value;
            $meta['EXAMPLE'] = md_tlg_h(md_tlg_event_example($value));
            $out['EVENT_TYPES'][] = $meta;
        }
        $out['COMMAND_EXAMPLES'] = array();
        foreach (md_tlg_command_examples() as $key => $example) {
            $out['COMMAND_EXAMPLES'][] = array(
                'KEY' => $key,
                'TITLE' => md_tlg_h($example['title']),
                'DESCRIPTION' => md_tlg_h($example['description']),
                'CODE' => md_tlg_h($example['code']),
            );
        }
        $out['HAS_BOT'] = $this->config['TLG_BOT_USERNAME'] !== '' ? 1 : 0;
    }

    /**
     * @param string $sql
     * @return int
     */
    protected function scalarQuery($sql)
    {
        $record = SQLSelectOne($sql);
        if (!is_array($record)) {
            return 0;
        }
        $value = reset($record);
        return (int)$value;
    }

    // -------------------------------------------------------------------------
    // Edit pages
    // -------------------------------------------------------------------------

    /**
     * @param array $out
     * @param int   $id
     * @return void
     */
    public function edit_user(&$out, $id)
    {
        require(DIR_MODULES . $this->name . '/telegram_user_edit.inc.php');
    }

    /**
     * @param array $out
     * @param int   $id
     * @return void
     */
    public function edit_cmd(&$out, $id)
    {
        require(DIR_MODULES . $this->name . '/telegram_cmd_edit.inc.php');
    }

    /**
     * @param array $out
     * @param int   $id
     * @return void
     */
    public function edit_event(&$out, $id)
    {
        require(DIR_MODULES . $this->name . '/telegram_event_edit.inc.php');
    }

    // -------------------------------------------------------------------------
    // Public legacy API
    // -------------------------------------------------------------------------

    /**
     * @param string $where
     * @return array
     */
    public function getUsers($where)
    {
        $query = 'SELECT * FROM tlg_user';
        if (trim((string)$where) !== '') {
            $query .= ' WHERE ' . $where;
        }
        $users = SQLSelect($query);
        return is_array($users) ? $users : array();
    }

    /**
     * @param string $token
     * @return void
     */
    public function setBotToken($token)
    {
        $this->config['TLG_TOKEN'] = trim((string)$token);
        $this->refreshApi();
    }

    /**
     * Build a reply keyboard from a list of button rows.
     *
     * @param array $options
     * @param bool  $onetime
     * @param bool  $resize
     * @param bool  $selective
     * @return array
     */
    public function buildKeyboard(array $options, $onetime = false, $resize = false, $selective = true)
    {
        $keyboard = array();
        foreach ($options as $row) {
            if (!is_array($row)) {
                $row = array($row);
            }
            $keyboardRow = array();
            foreach ($row as $button) {
                if (is_array($button)) {
                    $keyboardRow[] = $button;
                } else {
                    $keyboardRow[] = array('text' => (string)$button);
                }
            }
            if (!empty($keyboardRow)) {
                $keyboard[] = $keyboardRow;
            }
        }
        $result = array('keyboard' => $keyboard, 'resize_keyboard' => (bool)$resize);
        if ($onetime) {
            $result['one_time_keyboard'] = true;
        }
        if ($selective) {
            $result['selective'] = true;
        }
        return $result;
    }

    /**
     * @param array $option
     * @return array
     */
    public function buildInlineKeyBoard(array $option)
    {
        $rows = array();
        foreach ($option as $row) {
            if (!is_array($row)) {
                continue;
            }
            $inlineRow = array();
            foreach ($row as $button) {
                if (is_array($button)) {
                    $inlineRow[] = $button;
                } elseif (is_string($button)) {
                    $inlineRow[] = array('text' => $button, 'callback_data' => $button);
                }
            }
            if (!empty($inlineRow)) {
                $rows[] = $inlineRow;
            }
        }
        return array('inline_keyboard' => $rows);
    }

    /**
     * @param string      $text
     * @param string      $url
     * @param string      $callback_data
     * @param string|null $switch_inline_query
     * @param string|null $switch_inline_query_current_chat
     * @param string      $callback_game
     * @param string      $pay
     * @return array
     */
    public function buildInlineKeyboardButton($text, $url = '', $callback_data = '', $switch_inline_query = null, $switch_inline_query_current_chat = null, $callback_game = '', $pay = '')
    {
        $button = array('text' => (string)$text);
        if ($url !== '') {
            $button['url'] = (string)$url;
        } elseif ($callback_data !== '') {
            $button['callback_data'] = (string)$callback_data;
        } elseif ($switch_inline_query !== null) {
            $button['switch_inline_query'] = (string)$switch_inline_query;
        } elseif ($switch_inline_query_current_chat !== null) {
            $button['switch_inline_query_current_chat'] = (string)$switch_inline_query_current_chat;
        } elseif ($callback_game !== '') {
            $button['callback_game'] = (string)$callback_game;
        } elseif ($pay !== '') {
            $button['pay'] = (bool)$pay;
        }
        return $button;
    }

    /**
     * @param bool $selective
     * @return array
     */
    public function buildKeyBoardHide($selective = true)
    {
        return array('remove_keyboard' => true, 'selective' => (bool)$selective);
    }

    /**
     * @param bool $selective
     * @return array
     */
    public function buildForceReply($selective = true)
    {
        return array('force_reply' => true, 'selective' => (bool)$selective);
    }

    /**
     * Send a raw Bot API request and save the result in history.
     *
     * @param array  $content
     * @param string $endpoint
     * @param array  $files
     * @return array
     */
    public function sendContent($content, $endpoint = 'sendMessage', array $files = array())
    {
        $content = is_array($content) ? $content : array();
        if (isset($content['reply_markup']) && is_string($content['reply_markup']) && $content['reply_markup'] !== '') {
            $decoded = json_decode($content['reply_markup'], true);
            if (is_array($decoded)) {
                $content['reply_markup'] = $decoded;
            }
        }
        foreach ($content as $key => $value) {
            if (is_string($value) && $value === '' && !in_array($key, array('caption', 'text'), true)) {
                unset($content[$key]);
            }
        }

        $this->debug(array('endpoint' => $endpoint, 'content' => $this->sanitizeForLog($content), 'files' => array_keys($files)));
        $result = $this->api->request($endpoint, $content, $files);
        $result['endpoint'] = $endpoint;
        $result['content'] = $content;

        if (!empty($result['ok'])) {
            if (isset($result['result']) && is_array($result['result']) && $this->isMessagingEndpoint($endpoint)) {
                $this->saveData($result, 1);
            }
        } else {
            $this->saveData($result, 2);
            $this->rememberError($result);
        }
        return $result;
    }

    /**
     * @param string $endpoint
     * @return bool
     */
    protected function isMessagingEndpoint($endpoint)
    {
        return in_array($endpoint, array(
            'sendMessage', 'sendPhoto', 'sendAudio', 'sendDocument', 'sendVideo',
            'sendAnimation', 'sendVoice', 'sendVideoNote', 'sendSticker',
            'sendMediaGroup', 'sendLocation', 'sendVenue', 'sendContact',
            'sendPoll', 'sendDice', 'forwardMessage', 'copyMessage',
            'editMessageText', 'editMessageCaption',
        ), true);
    }

    /**
     * Hide large binary payloads in debug logs.
     *
     * @param array $content
     * @return array
     */
    protected function sanitizeForLog(array $content)
    {
        foreach ($content as $key => $value) {
            if (is_string($value) && strlen($value) > 500) {
                $content[$key] = substr($value, 0, 500) . '…';
            }
        }
        return $content;
    }

    /**
     * @param string $user_id
     * @param string $message
     * @param string $keyboard
     * @param string $parse_mode
     * @param string $inline
     * @param bool   $silent
     * @param array  $flags
     * @return array|null
     */
    public function sendMessage($user_id, $message, $keyboard = '', $parse_mode = 'HTML', $inline = '', $silent = false, $flags = array())
    {
        $message = (string)$message;
        if ($message === '') {
            return null;
        }
        $result = null;
        foreach ($this->splitText($message, 4096) as $chunk) {
            $content = array(
                'chat_id' => $user_id,
                'text' => $chunk,
                'parse_mode' => $parse_mode,
                'disable_notification' => (bool)$silent,
            );
            if (is_array($flags) && !empty($flags)) {
                foreach ($flags as $flagName => $flagValue) {
                    $content[$flagName] = $flagValue;
                }
            }
            if ($inline !== '') {
                $content['reply_markup'] = $inline;
            } elseif ($keyboard !== '') {
                $content['reply_markup'] = $keyboard;
            }
            $result = $this->sendContent($content, 'sendMessage');
        }
        return $result;
    }

    /**
     * @param string $text
     * @param int    $limit
     * @return array<int,string>
     */
    protected function splitText($text, $limit)
    {
        $text = (string)$text;
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return array($text);
        }
        $chunks = array();
        $offset = 0;
        $length = mb_strlen($text, 'UTF-8');
        while ($offset < $length) {
            $chunks[] = mb_substr($text, $offset, $limit, 'UTF-8');
            $offset += $limit;
        }
        return $chunks;
    }

    /**
     * @param string $where
     * @param string $method
     * @param array  $params
     * @param string $caption
     * @param mixed  $key
     * @param string $inline
     * @param mixed  $silent
     * @param array  $flags
     * @param string $fileField
     * @param string $filePath
     * @return array|null
     */
    protected function dispatchToUsers($where, $method, array $params, $caption = '', $key = null, $inline = '', $silent = null, array $flags = array(), $fileField = '', $filePath = '')
    {
        $users = $this->getUsers($where);
        $last = null;
        foreach ($users as $user) {
            $chatId = (string)$user['USER_ID'];
            if ($chatId === '0' || $chatId === '') {
                $chatId = (string)$user['NAME'];
            }
            if ($chatId === '') {
                continue;
            }
            $payload = $params;
            $payload['chat_id'] = $chatId;
            if ($caption !== '') {
                $payload['caption'] = $caption;
            }
            $markup = $inline !== '' ? $inline : $this->buildKeyboard($user, $key);
            if (!empty($markup)) {
                $payload['reply_markup'] = $markup;
            }
            $silentValue = $silent === null ? (int)$user['SILENT'] : (int)$silent;
            $payload['disable_notification'] = (bool)$silentValue;
            foreach ($flags as $flagName => $flagValue) {
                $payload[$flagName] = $flagValue;
            }
            $files = array();
            if ($filePath !== '') {
                if (is_file($filePath)) {
                    $files[$fileField] = $filePath;
                } else {
                    // Telegram file_id or public URL.
                    $payload[$fileField] = $filePath;
                }
            }
            $last = $this->sendContent($payload, $method, $files);
            $this->debug($last);
        }
        return $last;
    }

    /**
     * @param string $where
     * @param string $message
     * @param array  $key
     * @param string $inline
     * @param mixed  $silent
     * @param array  $flags
     * @return array|null
     */
    public function sendMessageTo($where, $message, ?array $key = null, $inline = '', $silent = null, $flags = array())
    {
        $users = $this->getUsers($where);
        $last = null;
        foreach ($users as $user) {
            $chatId = (string)$user['USER_ID'];
            if ($chatId === '0' || $chatId === '') {
                $chatId = (string)$user['NAME'];
            }
            if ($chatId === '') {
                continue;
            }
            $keyboard = $key !== null
                ? $this->buildKeyboard($key)
                : $this->getKeyb($user);
            $silentValue = $silent === null ? (int)$user['SILENT'] : (int)$silent;
            $last = $this->sendMessage($chatId, $message, $keyboard, 'HTML', $inline, (bool)$silentValue, $flags);
        }
        return $last;
    }

    /**
     * @param string $user_id
     * @param string $message
     * @param mixed  $key
     * @param string $inline
     * @param mixed  $silent
     * @param array  $flags
     * @return array|null
     */
    public function sendMessageToUser($user_id, $message, $key = null, $inline = '', $silent = null, $flags = array())
    {
        return $this->sendMessageTo('(USER_ID="' . DBSafe($user_id) . '" OR NAME LIKE "' . DBSafe($user_id) . '")', $message, $key, $inline, $silent, $flags);
    }

    /**
     * @param string $message
     * @param mixed  $key
     * @param string $inline
     * @param mixed  $silent
     * @param array  $flags
     * @return array|null
     */
    public function sendMessageToAdmin($message, $key = null, $inline = '', $silent = null, $flags = array())
    {
        return $this->sendMessageTo('ADMIN=1', $message, $key, $inline, $silent, $flags);
    }

    /**
     * @param string $message
     * @param mixed  $key
     * @param string $inline
     * @param mixed  $silent
     * @param array  $flags
     * @return array|null
     */
    public function sendMessageToAll($message, $key = null, $inline = '', $silent = null, $flags = array())
    {
        return $this->sendMessageTo('', $message, $key, $inline, $silent, $flags);
    }

    /**
     * @param string $user_id
     * @param mixed  $action
     * @return array
     */
    public function sendAction($user_id, $action = 'typing')
    {
        return $this->api->request('sendChatAction', array('chat_id' => $user_id, 'action' => $action));
    }

    /**
     * Legacy image send.
     *
     * @param string $user_id
     * @param string $image_path
     * @param string $message
     * @param mixed  $keyboard
     * @param string $inline
     * @param bool   $silent
     * @param array  $flags
     * @return array|null
     */
    public function sendImage($user_id, $image_path, $message = '', $keyboard = '', $inline = '', $silent = false, $flags = array())
    {
        return $this->dispatchToUsers('(USER_ID="' . DBSafe($user_id) . '" OR NAME LIKE "' . DBSafe($user_id) . '")', 'sendPhoto', array(), $message, $keyboard, $inline, $silent, $flags, 'photo', (string)$image_path);
    }

    /**
     * @param string $where
     * @param string $image_path
     * @param string $message
     * @param array  $key
     * @param string $inline
     * @param mixed  $silent
     * @param array  $flags
     * @return array|null
     */
    public function sendImageTo($where, $image_path, $message = '', ?array $key = null, $inline = '', $silent = null, $flags = array())
    {
        return $this->dispatchToUsers($where, 'sendPhoto', array(), $message, $key, $inline, $silent, $flags, 'photo', (string)$image_path);
    }

    /**
     * @param string $user_id
     * @param string $image_path
     * @param string $message
     * @param mixed  $key
     * @param string $inline
     * @param mixed  $silent
     * @param array  $flags
     * @return array|null
     */
    public function sendImageToUser($user_id, $image_path, $message = '', $key = null, $inline = '', $silent = null, $flags = array())
    {
        return $this->sendImageTo('(USER_ID="' . DBSafe($user_id) . '" OR NAME LIKE "' . DBSafe($user_id) . '")', $image_path, $message, $key, $inline, $silent, $flags);
    }

    /**
     * @param string $image_path
     * @param string $message
     * @param mixed  $key
     * @param string $inline
     * @param mixed  $silent
     * @param array  $flags
     * @return array|null
     */
    public function sendImageToAdmin($image_path, $message = '', $key = null, $inline = '', $silent = null, $flags = array())
    {
        return $this->sendImageTo('ADMIN=1', $image_path, $message, $key, $inline, $silent, $flags);
    }

    /**
     * @param string $image_path
     * @param string $message
     * @param mixed  $key
     * @param string $inline
     * @param mixed  $silent
     * @param array  $flags
     * @return array|null
     */
    public function sendImageToAll($image_path, $message = '', $key = null, $inline = '', $silent = null, $flags = array())
    {
        return $this->sendImageTo('', $image_path, $message, $key, $inline, $silent, $flags);
    }

    /**
     * @param string $user_id
     * @param string $video_path
     * @param string $message
     * @param mixed  $keyboard
     * @param string $inline
     * @return array|null
     */
    public function sendVideo($user_id, $video_path, $message = '', $keyboard = '', $inline = '')
    {
        return $this->dispatchToUsers('(USER_ID="' . DBSafe($user_id) . '" OR NAME LIKE "' . DBSafe($user_id) . '")', 'sendVideo', array(), $message, $keyboard, $inline, null, array(), 'video', (string)$video_path);
    }

    /**
     * @param string $where
     * @param string $video_path
     * @param string $message
     * @param array  $key
     * @param string $inline
     * @return array|null
     */
    public function sendVideoTo($where, $video_path, $message = '', ?array $key = null, $inline = '')
    {
        return $this->dispatchToUsers($where, 'sendVideo', array(), $message, $key, $inline, null, array(), 'video', (string)$video_path);
    }

    /**
     * @param string $user_id
     * @param string $video_path
     * @param string $message
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendVideoToUser($user_id, $video_path, $message = '', $key = null, $inline = '')
    {
        return $this->sendVideoTo('(USER_ID="' . DBSafe($user_id) . '" OR NAME LIKE "' . DBSafe($user_id) . '")', $video_path, $message, $key, $inline);
    }

    /**
     * @param string $video_path
     * @param string $message
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendVideoToAdmin($video_path, $message = '', $key = null, $inline = '')
    {
        return $this->sendVideoTo('ADMIN=1', $video_path, $message, $key, $inline);
    }

    /**
     * @param string $video_path
     * @param string $message
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendVideoToAll($video_path, $message = '', $key = null, $inline = '')
    {
        return $this->sendVideoTo('', $video_path, $message, $key, $inline);
    }

    /**
     * @param string $user_id
     * @param array  $image_paths
     * @param string $message
     * @param mixed  $keyboard
     * @return array|null
     */
    public function sendAlbum($user_id, $image_paths, $message = '', $keyboard = '')
    {
        $image_paths = array_values((array)$image_paths);
        if (count($image_paths) === 1) {
            return $this->sendImage($user_id, $image_paths[0], $message, $keyboard);
        }
        $media = array();
        $files = array();
        foreach (array_slice($image_paths, 0, 10) as $index => $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }
            $field = 'file' . $index;
            $files[$field] = $path;
            $item = array('type' => 'photo', 'media' => 'attach://' . $field);
            if ($index === 0 && $message !== '') {
                $item['caption'] = $message;
            }
            $media[] = $item;
        }
        if (count($media) < 2) {
            return null;
        }
        return $this->sendContent(array('chat_id' => $user_id, 'media' => $media), 'sendMediaGroup', $files);
    }

    /**
     * @param string $where
     * @param array  $image_paths
     * @param string $message
     * @param array  $key
     * @return void
     */
    public function sendAlbumTo($where, $image_paths, $message = '', ?array $key = null)
    {
        $users = $this->getUsers($where);
        foreach ($users as $user) {
            $chatId = (string)$user['USER_ID'];
            if ($chatId === '0' || $chatId === '') {
                $chatId = (string)$user['NAME'];
            }
            $this->sendAlbum($chatId, $image_paths, $message, $this->buildKeyboard($user, $key));
        }
    }

    /**
     * @param string $user_id
     * @param array  $image_paths
     * @param string $message
     * @param mixed  $key
     * @return void
     */
    public function sendAlbumToUser($user_id, $image_paths, $message = '', $key = null)
    {
        $this->sendAlbumTo('(USER_ID="' . DBSafe($user_id) . '" OR NAME LIKE "' . DBSafe($user_id) . '")', $image_paths, $message, $key);
    }

    /**
     * @param array  $image_paths
     * @param string $message
     * @param mixed  $key
     * @return void
     */
    public function sendAlbumToAdmin($image_paths, $message = '', $key = null)
    {
        $this->sendAlbumTo('ADMIN=1', $image_paths, $message, $key);
    }

    /**
     * @param array  $image_paths
     * @param string $message
     * @param mixed  $key
     * @return void
     */
    public function sendAlbumToAll($image_paths, $message = '', $key = null)
    {
        $this->sendAlbumTo('', $image_paths, $message, $key);
    }

    /**
     * @param string $user_id
     * @param string $file_path
     * @param string $message
     * @param mixed  $keyboard
     * @param string $inline
     * @return array|null
     */
    public function sendFile($user_id, $file_path, $message = '', $keyboard = '', $inline = '')
    {
        return $this->dispatchToUsers('(USER_ID="' . DBSafe($user_id) . '" OR NAME LIKE "' . DBSafe($user_id) . '")', 'sendDocument', array(), $message, $keyboard, $inline, null, array(), 'document', (string)$file_path);
    }

    /**
     * @param string $where
     * @param string $file_path
     * @param string $message
     * @param array  $key
     * @param string $inline
     * @return array|null
     */
    public function sendFileTo($where, $file_path, $message = '', ?array $key = null, $inline = '')
    {
        return $this->dispatchToUsers($where, 'sendDocument', array(), $message, $key, $inline, null, array(), 'document', (string)$file_path);
    }

    /**
     * @param string $user_id
     * @param string $file_path
     * @param string $message
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendFileToUser($user_id, $file_path, $message = '', $key = null, $inline = '')
    {
        return $this->sendFileTo('(USER_ID="' . DBSafe($user_id) . '" OR NAME LIKE "' . DBSafe($user_id) . '")', $file_path, $message, $key, $inline);
    }

    /**
     * @param string $file_path
     * @param string $message
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendFileToAdmin($file_path, $message = '', $key = null, $inline = '')
    {
        return $this->sendFileTo('ADMIN=1', $file_path, $message, $key, $inline);
    }

    /**
     * @param string $file_path
     * @param string $message
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendFileToAll($file_path, $message = '', $key = null, $inline = '')
    {
        return $this->sendFileTo('', $file_path, $message, $key, $inline);
    }

    /**
     * @param string $user_id
     * @param string $sticker
     * @param mixed  $keyboard
     * @param string $inline
     * @return array|null
     */
    public function sendSticker($user_id, $sticker, $keyboard = '', $inline = '')
    {
        return $this->dispatchToUsers('(USER_ID="' . DBSafe($user_id) . '" OR NAME LIKE "' . DBSafe($user_id) . '")', 'sendSticker', array(), '', $keyboard, $inline, null, array(), 'sticker', (string)$sticker);
    }

    /**
     * @param string $where
     * @param string $sticker
     * @param array  $key
     * @param string $inline
     * @return array|null
     */
    public function sendStickerTo($where, $sticker, ?array $key = null, $inline = '')
    {
        return $this->dispatchToUsers($where, 'sendSticker', array(), '', $key, $inline, null, array(), 'sticker', (string)$sticker);
    }

    /**
     * @param string $user_id
     * @param string $sticker
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendStickerToUser($user_id, $sticker, $key = null, $inline = '')
    {
        return $this->sendStickerTo('(USER_ID="' . DBSafe($user_id) . '" OR NAME LIKE "' . DBSafe($user_id) . '")', $sticker, $key, $inline);
    }

    /**
     * @param string $sticker
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendStickerToAdmin($sticker, $key = null, $inline = '')
    {
        return $this->sendStickerTo('ADMIN=1', $sticker, $key, $inline);
    }

    /**
     * @param string $sticker
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendStickerToAll($sticker, $key = null, $inline = '')
    {
        return $this->sendStickerTo('', $sticker, $key, $inline);
    }

    /**
     * @param string $user_id
     * @param float  $lat
     * @param float  $lon
     * @param mixed  $keyboard
     * @param string $inline
     * @return array|null
     */
    public function sendLocation($user_id, $lat, $lon, $keyboard = '', $inline = '')
    {
        return $this->dispatchToUsers('(USER_ID="' . DBSafe($user_id) . '" OR NAME LIKE "' . DBSafe($user_id) . '")', 'sendLocation', array('latitude' => (float)$lat, 'longitude' => (float)$lon), '', $keyboard, $inline);
    }

    /**
     * @param string $where
     * @param float  $lat
     * @param float  $lon
     * @param array  $key
     * @param string $inline
     * @return array|null
     */
    public function sendLocationTo($where, $lat, $lon, ?array $key = null, $inline = '')
    {
        return $this->dispatchToUsers($where, 'sendLocation', array('latitude' => (float)$lat, 'longitude' => (float)$lon), '', $key, $inline);
    }

    /**
     * @param string $user_id
     * @param float  $lat
     * @param float  $lon
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendLocationToUser($user_id, $lat, $lon, $key = null, $inline = '')
    {
        return $this->sendLocationTo('(USER_ID="' . DBSafe($user_id) . '" OR NAME LIKE "' . DBSafe($user_id) . '")', $lat, $lon, $key, $inline);
    }

    /**
     * @param float  $lat
     * @param float  $lon
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendLocationToAdmin($lat, $lon, $key = null, $inline = '')
    {
        return $this->sendLocationTo('ADMIN=1', $lat, $lon, $key, $inline);
    }

    /**
     * @param float  $lat
     * @param float  $lon
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendLocationToAll($lat, $lon, $key = null, $inline = '')
    {
        return $this->sendLocationTo('', $lat, $lon, $key, $inline);
    }

    /**
     * @param string $user_id
     * @param float  $lat
     * @param float  $lon
     * @param string $title
     * @param string $address
     * @param mixed  $keyboard
     * @param string $inline
     * @return array|null
     */
    public function sendVenue($user_id, $lat, $lon, $title, $address, $keyboard = '', $inline = '')
    {
        return $this->dispatchToUsers('(USER_ID="' . DBSafe($user_id) . '" OR NAME LIKE "' . DBSafe($user_id) . '")', 'sendVenue', array(
            'latitude' => (float)$lat,
            'longitude' => (float)$lon,
            'title' => (string)$title,
            'address' => (string)$address,
        ), '', $keyboard, $inline);
    }

    /**
     * @param string $where
     * @param float  $lat
     * @param float  $lon
     * @param string $title
     * @param string $address
     * @param array  $key
     * @param string $inline
     * @return array|null
     */
    public function sendVenueTo($where, $lat, $lon, $title, $address, ?array $key = null, $inline = '')
    {
        return $this->dispatchToUsers($where, 'sendVenue', array(
            'latitude' => (float)$lat,
            'longitude' => (float)$lon,
            'title' => (string)$title,
            'address' => (string)$address,
        ), '', $key, $inline);
    }

    /**
     * @param string $user_id
     * @param float  $lat
     * @param float  $lon
     * @param string $title
     * @param string $address
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendVenueToUser($user_id, $lat, $lon, $title, $address, $key = null, $inline = '')
    {
        return $this->sendVenueTo('(USER_ID="' . DBSafe($user_id) . '" OR NAME LIKE "' . DBSafe($user_id) . '")', $lat, $lon, $title, $address, $key, $inline);
    }

    /**
     * @param float  $lat
     * @param float  $lon
     * @param string $title
     * @param string $address
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendVenueToAdmin($lat, $lon, $title, $address, $key = null, $inline = '')
    {
        return $this->sendVenueTo('ADMIN=1', $lat, $lon, $title, $address, $key, $inline);
    }

    /**
     * @param float  $lat
     * @param float  $lon
     * @param string $title
     * @param string $address
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendVenueToAll($lat, $lon, $title, $address, $key = null, $inline = '')
    {
        return $this->sendVenueTo('', $lat, $lon, $title, $address, $key, $inline);
    }

    /**
     * @param string $user_id
     * @param string $file_path
     * @param string $caption
     * @param mixed  $keyboard
     * @param string $inline
     * @return array|null
     */
    public function sendVoice($user_id, $file_path, $caption = '', $keyboard = '', $inline = '')
    {
        return $this->dispatchToUsers('(USER_ID="' . DBSafe($user_id) . '" OR NAME LIKE "' . DBSafe($user_id) . '")', 'sendVoice', array(), $caption, $keyboard, $inline, null, array(), 'voice', (string)$file_path);
    }

    /**
     * @param string $where
     * @param string $file_path
     * @param string $caption
     * @param array  $key
     * @param string $inline
     * @return array|null
     */
    public function sendVoiceTo($where, $file_path, $caption = '', ?array $key = null, $inline = '')
    {
        return $this->dispatchToUsers($where, 'sendVoice', array(), $caption, $key, $inline, null, array(), 'voice', (string)$file_path);
    }

    /**
     * @param string $user_id
     * @param string $file_path
     * @param string $caption
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendVoiceToUser($user_id, $file_path, $caption = '', $key = null, $inline = '')
    {
        return $this->sendVoiceTo('(USER_ID="' . DBSafe($user_id) . '" OR NAME LIKE "' . DBSafe($user_id) . '")', $file_path, $caption, $key, $inline);
    }

    /**
     * @param string $file_path
     * @param string $caption
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendVoiceToAdmin($file_path, $caption = '', $key = null, $inline = '')
    {
        return $this->sendVoiceTo('ADMIN=1', $file_path, $caption, $key, $inline);
    }

    /**
     * @param string $file_path
     * @param string $caption
     * @param mixed  $key
     * @param string $inline
     * @return array|null
     */
    public function sendVoiceToAll($file_path, $caption = '', $key = null, $inline = '')
    {
        return $this->sendVoiceTo('', $file_path, $caption, $key, $inline);
    }

    /**
     * @param string $user_id
     * @param string $emoji
     * @return array
     */
    public function sendDice($user_id, $emoji = null)
    {
        return $this->api->sendDice($user_id, $emoji === null ? '' : (string)$emoji);
    }

    /**
     * @param string $user_id
     * @param string $question
     * @param array  $options
     * @param bool   $is_anonymous
     * @param string $type
     * @param bool   $allows_multiple_answers
     * @param int    $correct_option_id
     * @param string $explanation
     * @param int    $open_period
     * @param int    $close_date
     * @return array
     */
    public function sendPoll($user_id, $question, $options, $is_anonymous = false, $type = 'regular', $allows_multiple_answers = false, $correct_option_id = 0, $explanation = '', $open_period = 0, $close_date = 0)
    {
        $pollOptions = array();
        foreach ((array)$options as $option) {
            $pollOptions[] = array('text' => is_array($option) ? (string)reset($option) : (string)$option);
        }
        $params = array(
            'is_anonymous' => (bool)$is_anonymous,
            'type' => $type === 'quiz' ? 'quiz' : 'regular',
            'allows_multiple_answers' => (bool)$allows_multiple_answers,
        );
        if ($correct_option_id > 0) {
            $params['correct_option_id'] = (int)$correct_option_id;
        }
        if ($explanation !== '') {
            $params['explanation'] = (string)$explanation;
        }
        if ($open_period > 0) {
            $params['open_period'] = (int)$open_period;
        }
        if ($close_date > 0) {
            $params['close_date'] = (int)$close_date;
        }
        return $this->api->sendPoll($user_id, $question, $pollOptions, $params);
    }

    /**
     * @param string $user_id
     * @param string $phone
     * @param string $first_name
     * @param string $last_name
     * @return array
     */
    public function sendContact($user_id, $phone, $first_name, $last_name = '')
    {
        $options = array();
        if ($last_name !== '') {
            $options['last_name'] = (string)$last_name;
        }
        return $this->api->sendContact($user_id, $phone, $first_name, $options);
    }

    /**
     * @param string   $callback_id
     * @param string   $text
     * @param bool     $show_alert
     * @return array
     */
    public function sendAnswerCallbackQuery($callback_id, $text, $show_alert = false)
    {
        return $this->api->answerCallbackQuery($callback_id, $text, array('show_alert' => (bool)$show_alert));
    }

    /**
     * @param string $callback_id
     * @param string $text
     * @param bool   $show_alert
     * @return array
     */
    public function answerCallbackQuery($callback_id, $text = '', $show_alert = false)
    {
        return $this->sendAnswerCallbackQuery($callback_id, $text, $show_alert);
    }

    /**
     * @param string $user_id
     * @param int    $message_id
     * @param string $message
     * @param string $keyboard
     * @param string $parse_mode
     * @return array
     */
    public function editMessage($user_id, $message_id, $message, $keyboard = '', $parse_mode = 'HTML')
    {
        $options = array('parse_mode' => $parse_mode);
        if ($keyboard !== '') {
            $options['reply_markup'] = $keyboard;
        }
        return $this->api->editMessageText($user_id, $message_id, $message, $options);
    }

    /**
     * @param string $user_id
     * @param int    $message_id
     * @param string $caption
     * @param string $keyboard
     * @param string $parse_mode
     * @return array
     */
    public function editMessageCaption($user_id, $message_id, $caption, $keyboard = '', $parse_mode = 'HTML')
    {
        $options = array('parse_mode' => $parse_mode);
        if ($keyboard !== '') {
            $options['reply_markup'] = $keyboard;
        }
        return $this->api->editMessageCaption($user_id, $message_id, $caption, $options);
    }

    /**
     * @param string $user_id
     * @param int    $message_id
     * @return array
     */
    public function deleteMessage($user_id, $message_id)
    {
        return $this->api->deleteMessage($user_id, $message_id);
    }

    /**
     * @return array
     */
    public function getMe()
    {
        return $this->api->getMe();
    }

    // -------------------------------------------------------------------------
    // Bot lifecycle
    // -------------------------------------------------------------------------

    /**
     * @param bool $force
     * @return void
     */
    public function init($force = false)
    {
        $this->loadConfigSafe();
        $this->refreshApi();
        $this->info('Token bot - ' . md_tlg_mask_token((string)$this->config['TLG_TOKEN']));

        $me = $this->api->getMe();
        if (empty($me['ok']) || empty($me['result']['id'])) {
            $this->warning('Error connect or invalid token: ' . (isset($me['description']) ? $me['description'] : 'unknown'));
            $this->rememberError($me);
            $this->sendnotification('Error connect or invalid token', 'danger');
            return;
        }

        $bot = $me['result'];
        $this->storeBotInfo($bot);
        $this->config['TLG_BOT_CHECKED'] = date('Y-m-d H:i:s');
        $this->config['TLG_LAST_CHECK'] = date('Y-m-d H:i:s');
        $this->config['TLG_LAST_ERROR'] = '';
        $this->config['TLG_LAST_ERROR_TIME'] = '';
        $this->saveConfig();
        $this->downloadBotAvatar((int)$bot['id']);

        $this->info('Me: @' . (isset($bot['username']) ? $bot['username'] : '') . ' (' . $bot['id'] . ')');
        if ($force || $this->config['TLG_BOT_CHECKED'] === '') {
            $this->refreshAllUsers();
        }
    }

    /**
     * @param array $bot
     * @return void
     */
    protected function storeBotInfo(array $bot)
    {
        $this->config['TLG_BOT_ID'] = isset($bot['id']) ? (string)$bot['id'] : '';
        $this->config['TLG_BOT_NAME'] = isset($bot['first_name']) ? (string)$bot['first_name'] : '';
        $this->config['TLG_BOT_USERNAME'] = isset($bot['username']) ? (string)$bot['username'] : '';
        $this->config['TLG_BOT_JOIN_GROUP'] = !empty($bot['can_join_groups']) ? 1 : 0;
        $this->config['TLG_BOT_READ_GROUP'] = !empty($bot['can_read_all_group_messages']) ? 1 : 0;
        $this->config['TLG_BOT_SUPPORT_INLINE'] = !empty($bot['supports_inline_queries']) ? 1 : 0;
        $this->saveConfig();
    }

    /**
     * @param array $bot
     * @return array
     */
    protected function botPayload(array $bot)
    {
        return array(
            'id' => isset($bot['id']) ? (string)$bot['id'] : '',
            'first_name' => isset($bot['first_name']) ? (string)$bot['first_name'] : '',
            'username' => isset($bot['username']) ? (string)$bot['username'] : '',
            'can_join_groups' => !empty($bot['can_join_groups']),
            'can_read_all_group_messages' => !empty($bot['can_read_all_group_messages']),
            'supports_inline_queries' => !empty($bot['supports_inline_queries']),
            'avatar' => md_tlg_bot_avatar(isset($bot['id']) ? $bot['id'] : ''),
        );
    }

    /**
     * @param array $info
     * @return array
     */
    protected function webhookPayload(array $info)
    {
        if (empty($info['ok']) || !isset($info['result'])) {
            return array(
                'ok' => false,
                'error' => isset($info['description']) ? (string)$info['description'] : 'Нет ответа',
                'url' => '',
            );
        }
        $result = $info['result'];
        $lastErrorDate = !empty($result['last_error_date']) ? (int)$result['last_error_date'] : 0;
        return array(
            'ok' => true,
            'url' => isset($result['url']) ? (string)$result['url'] : '',
            'has_custom_certificate' => !empty($result['has_custom_certificate']),
            'pending_update_count' => isset($result['pending_update_count']) ? (int)$result['pending_update_count'] : 0,
            'last_error_date' => $lastErrorDate,
            'last_error_date_human' => $lastErrorDate ? date('d.m.Y H:i:s', $lastErrorDate) : '',
            'last_error_message' => isset($result['last_error_message']) ? (string)$result['last_error_message'] : '',
            'max_connections' => isset($result['max_connections']) ? (int)$result['max_connections'] : 0,
            'allowed_updates' => isset($result['allowed_updates']) ? array_values((array)$result['allowed_updates']) : array(),
        );
    }

    /**
     * @param int $botId
     * @return string
     */
    protected function downloadBotAvatar($botId)
    {
        $botId = (int)$botId;
        if ($botId <= 0) {
            return '';
        }
        $destination = rtrim(ROOT, '/\\') . '/cms/cached/telegram/' . $botId . '.jpg';
        $chat = $this->api->getChat($botId);
        if (empty($chat['ok']) || empty($chat['result']['photo']['big_file_id'])) {
            return md_tlg_bot_avatar($botId);
        }
        $file = $this->api->getFile($chat['result']['photo']['big_file_id']);
        if (empty($file['ok']) || empty($file['result']['file_path'])) {
            return md_tlg_bot_avatar($botId);
        }
        $result = $this->api->downloadFile($file['result']['file_path'], $destination);
        if (empty($result['ok'])) {
            $this->debug('Avatar download failed: ' . $result['error']);
        }
        return md_tlg_bot_avatar($botId);
    }

    /**
     * @param array $user
     * @return bool
     */
    public function updateInfo($user)
    {
        if (empty($user['USER_ID'])) {
            return false;
        }
        $chat = $this->api->getChat($user['USER_ID']);
        if (empty($chat['ok']) || empty($chat['result'])) {
            return false;
        }
        $result = $chat['result'];
        if (isset($result['type']) && $result['type'] === 'private') {
            $name = trim((isset($result['first_name']) ? $result['first_name'] : '') . ' ' . (isset($result['last_name']) ? $result['last_name'] : ''));
        } else {
            $name = isset($result['title']) ? (string)$result['title'] : '';
        }
        if ($name === '' && isset($user['NAME'])) {
            $name = (string)$user['NAME'];
        }
        $user['NAME'] = $name;
        $user['USERNAME'] = isset($result['username']) ? (string)$result['username'] : '';
        SQLUpdate('tlg_user', $user);

        if (!empty($result['photo']['big_file_id'])) {
            $file = $this->api->getFile($result['photo']['big_file_id']);
            if (!empty($file['ok']) && !empty($file['result']['file_path'])) {
                $destination = rtrim(ROOT, '/\\') . '/cms/cached/telegram/' . preg_replace('/[^0-9-]/', '', (string)$user['USER_ID']) . '.jpg';
                $this->api->downloadFile($file['result']['file_path'], $destination);
            }
        }
        return true;
    }

    /**
     * @return int
     */
    public function refreshAllUsers()
    {
        $users = SQLSelect('SELECT * FROM tlg_user ORDER BY ID ASC');
        $count = 0;
        foreach ((array)$users as $user) {
            if ($this->updateInfo($user)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Polling cycle.
     *
     * @return int
     */
    public function processCycle()
    {
        $this->loadConfigSafe();
        $this->refreshApi();
        $this->resendData();
        $this->pruneHistory();

        if ((int)$this->config['TLG_WEBHOOK']) {
            return 0;
        }
        if (trim((string)$this->config['TLG_TOKEN']) === '') {
            return 0;
        }

        $offset = (int)$this->config['TLG_LAST_UPDATE_ID'];
        if ($offset > 0) {
            $offset++;
        }
        $timeout = max(0, min(50, (int)$this->config['TLG_TIMEOUT']));
        $response = $this->api->getUpdates($offset, 100, $timeout, $this->allowedUpdatesArray());

        if (empty($response['ok'])) {
            $errorCode = isset($response['error_code']) ? (int)$response['error_code'] : 0;
            $description = isset($response['description']) ? (string)$response['description'] : 'unknown error';
            $this->saveData($response, 5);
            $this->rememberError($response);
            if ($errorCode === 401) {
                $this->sendnotification('Токен Telegram недействителен. Цикл остановлен.', 'danger');
                return -1;
            }
            if ($errorCode === 409) {
                $this->warning('getUpdates conflict: ' . $description);
                return 0;
            }
            $this->warning('getUpdates error ' . $errorCode . ': ' . $description);
            return 0;
        }

        $updates = isset($response['result']) && is_array($response['result']) ? $response['result'] : array();
        $maxId = (int)$this->config['TLG_LAST_UPDATE_ID'];
        foreach ($updates as $update) {
            $updateId = isset($update['update_id']) ? (int)$update['update_id'] : 0;
            if ($maxId > 0 && $updateId <= $maxId) {
                continue;
            }
            try {
                $this->processUpdate($update);
            } catch (Throwable $e) {
                $this->log('Update ' . $updateId . ' failed: ' . get_class($e) . ': ' . $e->getMessage());
                registerError('telegram', 'Update ' . $updateId . ': ' . $e->getMessage());
            }
            if ($updateId > $maxId) {
                $maxId = $updateId;
            }
        }

        if ($maxId !== (int)$this->config['TLG_LAST_UPDATE_ID']) {
            $this->config['TLG_LAST_UPDATE_ID'] = $maxId;
            $this->config['TLG_LAST_CHECK'] = date('Y-m-d H:i:s');
            $this->saveConfig();
        }

        return 0;
    }

    /**
     * Process a single Update object.
     *
     * @param array $data
     * @return void
     */
    public function processUpdate(array $data)
    {
        $update = TelegramUpdate::fromArray($data);
        $this->current_update = $update;
        $this->saveData($data, 0);
        $this->debug($data);

        $type = $update->type();
        $chatId = $update->chatId();
        $username = $update->username();
        $fullname = $update->fullName();
        $text = $update->content();
        $message = $update->message();
        $botUsername = (string)$this->config['TLG_BOT_USERNAME'];

        // Group message addressed to the bot: strip @bot and map the chat to the user.
        $user = $chatId !== 0 ? SQLSelectOne("SELECT * FROM tlg_user WHERE USER_ID='" . DBSafe($chatId) . "'") : null;
        if ($chatId < 0 && $botUsername !== '' && $text !== '' && stripos($text, '@' . $botUsername) === 0) {
            $text = trim(str_ireplace('@' . $botUsername, '', $text));
            $sourceUser = SQLSelectOne("SELECT * FROM tlg_user WHERE TRIM(NAME) LIKE '" . DBSafe(trim($username)) . "'");
            if ($sourceUser) {
                $user = $sourceUser;
            }
        }

        $startCommands = array('/start', '/start@' . mb_strtolower($botUsername, 'UTF-8'));
        if (!empty($this->config['TLG_REG_USER']) && in_array(mb_strtolower(trim($text), 'UTF-8'), $startCommands, true)) {
            if (empty($user['ID'])) {
                $record = array(
                    'USER_ID' => $chatId,
                    'NAME' => $fullname !== '' ? $fullname : $username,
                    'USERNAME' => $username,
                    'CREATED' => date('Y-m-d H:i:s'),
                    'LAST_SEEN' => date('Y-m-d H:i:s'),
                    'MEMBER_ID' => 0,
                );
                $record['ID'] = SQLInsert('tlg_user', $record);
                $user = $record;
                $this->info('New user registered: ' . $username . ' (' . $chatId . ')');
                $this->sendnotification('Новый пользователь Telegram: @' . $username . ' (' . $chatId . ')', 'info');
            }
            $this->sendContent(array('chat_id' => $chatId, 'text' => 'Вы зарегистрированы. Обратитесь к администратору для получения доступа к функциям.'), 'sendMessage');
            $this->updateInfo($user);
            return;
        }

        if (empty($user['ID'])) {
            $this->warning('Unknown user ' . $chatId . ' (' . $username . '): ' . md_tlg_truncate($text, 200));
            if (method_exists($this, 'sendnotification')) {
                $this->sendnotification('Незарегистрированный пользователь: @' . $username . ' (' . $chatId . ')', 'danger');
            }
            return;
        }

        $user['LAST_SEEN'] = date('Y-m-d H:i:s');
        if ($username !== '' && $username !== (string)$user['USERNAME']) {
            $user['USERNAME'] = $username;
        }
        SQLUpdate('tlg_user', $user);

        $hasAnyAccess = (int)$user['ADMIN'] || (int)$user['HISTORY'] || (int)$user['CMD'] || (int)$user['PATTERNS'] || (int)$user['DOWNLOAD'] || (int)$user['PLAY'];
        if (!$hasAnyAccess) {
            $this->sendContent(array('chat_id' => $chatId, 'text' => 'Обратитесь к администратору для получения доступа к функциям.'), 'sendMessage');
            return;
        }

        $commonVars = array(
            'chat_id' => $chatId,
            'username' => $username,
            'fullname' => $fullname,
            'user' => $user,
            'data' => $data,
            'update' => $update,
            'message' => $message,
            'text' => $text,
            'caption' => $update->caption(),
            'bot_username' => $botUsername,
            'telegram_module' => $this,
        );

        // Callback buttons.
        if ($type === 'callback_query') {
            $callbackVars = $commonVars + array(
                'callback_id' => $update->callbackId(),
                'callback_data' => $update->callbackData(),
                'message_id' => $update->messageId(),
            );
            $this->api->answerCallbackQuery($update->callbackId(), '');
            if (!empty($user['CMD'])) {
                $this->runEventHandlers(9, $callbackVars);
            }
            $callbackData = $update->callbackData();
            if (strpos($callbackData, 'cmd:') === 0) {
                $this->executeCommandByTitle($user, substr($callbackData, 4), $update, $callbackData);
            }
            return;
        }

        // Poll answers.
        if ($type === 'poll_answer') {
            if (!empty($user['CMD'])) {
                $poll = isset($data['poll_answer']) ? $data['poll_answer'] : array();
                $this->runEventHandlers(10, $commonVars + array(
                    'poll_id' => isset($poll['poll_id']) ? $poll['poll_id'] : '',
                    'option_ids' => isset($poll['option_ids']) ? $poll['option_ids'] : array(),
                ));
            }
            return;
        }

        // Reactions.
        if ($type === 'message_reaction' || $type === 'message_reaction_count') {
            if (!empty($user['CMD'])) {
                $reaction = isset($data[$type]) ? $data[$type] : array();
                $this->runEventHandlers(19, $commonVars + array(
                    'message_reaction' => $reaction,
                    'message_id' => isset($reaction['message_id']) ? (int)$reaction['message_id'] : 0,
                ));
            }
            return;
        }

        // Inline queries: answer with an empty result so the client does not spin.
        if ($type === 'inline_query') {
            $this->api->answerInlineQuery(isset($data['inline_query']['id']) ? $data['inline_query']['id'] : '', array(), array('cache_time' => 5));
            if (!empty($user['CMD'])) {
                $this->runEventHandlers(20, $commonVars + array(
                    'inline_query_id' => isset($data['inline_query']['id']) ? $data['inline_query']['id'] : '',
                    'query' => isset($data['inline_query']['query']) ? $data['inline_query']['query'] : '',
                    'user_id' => $update->fromId(),
                ));
            }
            return;
        }

        // Chat member / join request / my_chat_member.
        if (in_array($type, array('chat_member', 'my_chat_member', 'chat_join_request'), true)) {
            if (!empty($user['CMD'])) {
                $this->runEventHandlers(18, $commonVars + array(
                    'chat_member' => isset($data['chat_member']) ? $data['chat_member'] : (isset($data['my_chat_member']) ? $data['my_chat_member'] : array()),
                    'chat_join_request' => isset($data['chat_join_request']) ? $data['chat_join_request'] : array(),
                ));
            }
            return;
        }

        // Business messages.
        if ($type === 'business_message' || $type === 'edited_business_message') {
            $vars = $commonVars + array(
                'business_connection_id' => isset($message['business_connection_id']) ? $message['business_connection_id'] : '',
            );
            if (!empty($user['CMD'])) {
                $this->runEventHandlers($type === 'edited_business_message' ? 16 : 21, $vars);
            }
            return;
        }

        // Edited messages and channel posts have their own event types.
        if (in_array($type, array('edited_message', 'edited_channel_post'), true)) {
            if (!empty($user['CMD'])) {
                $this->runEventHandlers(16, $commonVars);
            }
            return;
        }
        if ($type === 'channel_post') {
            if (!empty($user['CMD'])) {
                $this->runEventHandlers(17, $commonVars);
            }
            return;
        }
        if (in_array($type, array('pre_checkout_query', 'shipping_query', 'successful_payment'), true)) {
            if (!empty($user['CMD'])) {
                $this->runEventHandlers(22, $commonVars + array(
                    'pre_checkout_query' => isset($data['pre_checkout_query']) ? $data['pre_checkout_query'] : array(),
                    'successful_payment' => isset($data['successful_payment']) ? $data['successful_payment'] : array(),
                ));
            }
            return;
        }

        // Location.
        $location = isset($message['location']) && is_array($message['location']) ? $message['location'] : array();
        if (!empty($location)) {
            $vars = $commonVars + array(
                'latitude' => isset($location['latitude']) ? $location['latitude'] : null,
                'longitude' => isset($location['longitude']) ? $location['longitude'] : null,
                'accuracy' => isset($location['horizontal_accuracy']) ? $location['horizontal_accuracy'] : null,
            );
            $this->updateUserLocation($user, $location);
            if (!empty($user['CMD'])) {
                $this->runEventHandlers(8, $vars);
            }
            return;
        }

        // Service messages.
        if ($update->isServiceMessage()) {
            if (!empty($user['CMD'])) {
                $this->runEventHandlers(18, $commonVars);
            }
            return;
        }

        // Media and documents.
        $media = $update->media();
        $mediaVars = array();
        $filePath = '';
        if ($media['kind'] !== '' && $media['kind'] !== 'location' && $media['kind'] !== 'venue' && $media['kind'] !== 'contact' && $media['kind'] !== 'dice') {
            $mediaVars = array(
                'file_id' => $media['file_id'],
                'file_name' => $media['file_name'],
            );
            if (!empty($user['DOWNLOAD'])) {
                $filePath = $this->downloadIncomingMedia($user, $media);
                if ($filePath !== '') {
                    $mediaVars['file_path'] = $filePath;
                }
            }
        }
        $mediaVars += array(
            'photo' => isset($message['photo']) ? $message['photo'] : array(),
            'document' => isset($message['document']) ? $message['document'] : array(),
            'audio' => isset($message['audio']) ? $message['audio'] : array(),
            'video' => isset($message['video']) ? $message['video'] : array(),
            'voice' => isset($message['voice']) ? $message['voice'] : array(),
            'video_note' => isset($message['video_note']) ? $message['video_note'] : array(),
            'animation' => isset($message['animation']) ? $message['animation'] : array(),
            'sticker' => isset($message['sticker']) ? $message['sticker'] : array(),
            'contact' => isset($message['contact']) ? $message['contact'] : array(),
            'venue' => isset($message['venue']) ? $message['venue'] : array(),
            'dice' => isset($message['dice']) ? $message['dice'] : array(),
        );

        if ($media['kind'] !== '' && $media['kind'] !== 'location') {
            if (!empty($user['PLAY']) && in_array($media['kind'], array('voice', 'audio'), true) && $filePath !== '') {
                $this->playIncomingMedia($filePath);
            }
            if (!empty($user['CMD'])) {
                $this->runEventHandlers($media['event_type'], $commonVars + $mediaVars);
            }
            return;
        }

        // Plain text and commands.
        if ($text === '') {
            return;
        }

        if (!empty($user['CMD'])) {
            $skip = $this->runEventHandlers(1, $commonVars);
            if ($skip) {
                $this->info('Skip next processing message');
                return;
            }
        }

        list($commandName, $args) = $update->commandParts();
        if ($commandName === '') {
            $parts = preg_split('/\s+/u', trim($text), 2);
            $commandName = mb_strtolower(isset($parts[0]) ? $parts[0] : '', 'UTF-8');
        }
        if ($commandName !== '' && !empty($user['CMD'])) {
            if ($this->executeCommandByTitle($user, trim($text, "/ \t\n\r\0\x0B"), $update, $text, $args)) {
                return;
            }
        }

        // Built-in helper: every user can discover their chat_id.
        if ($commandName === 'id') {
            $reply = 'Ваш chat_id: <code>' . md_tlg_h($chatId) . '</code>';
            if ($username !== '') {
                $reply .= "\nUsername: @" . md_tlg_h($username);
            }
            if ($update->isGroup()) {
                $reply .= "\nЭто групповой чат: " . md_tlg_h($update->chatTitle());
            }
            $this->sendMessage($chatId, $reply, '', 'HTML');
            return;
        }

        if (!empty($user['PATTERNS'])) {
            say(md_tlg_h($text), 0, $user['MEMBER_ID'], 'telegram' . $user['ID']);
        }
    }

    /**
     * Run all enabled handlers for an event type.
     *
     * @param int   $eventType
     * @param array $vars
     * @return bool $skip flag from the handler
     */
    protected function runEventHandlers($eventType, array $vars)
    {
        $events = SQLSelect('SELECT * FROM tlg_event WHERE TYPE_EVENT=' . (int)$eventType . ' AND ENABLE=1 ORDER BY ID ASC');
        $skip = false;
        foreach ((array)$events as $event) {
            if (trim((string)$event['CODE']) === '') {
                continue;
            }
            $this->info('Execute event #' . $event['ID'] . ' "' . $event['TITLE'] . '"');
            $executed = $this->evaluateCode($event['CODE'], $vars);
            if (!empty($executed['skip'])) {
                $skip = true;
            }
            $event['RUNS'] = (int)$event['RUNS'] + 1;
            $event['LAST_RUN'] = date('Y-m-d H:i:s');
            SQLUpdate('tlg_event', $event);
            if ($skip) {
                break;
            }
        }
        return $skip;
    }

    /**
     * Evaluate user code with a controlled variable set.
     *
     * @param string $code
     * @param array  $vars
     * @return array{result:mixed,skip:bool,error:string}
     */
    protected function evaluateCode($code, array $vars)
    {
        $skip = false;
        $result = null;
        $error = '';
        $vars = array_merge($vars, array('skip' => false));
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            $result = eval($code);
        } catch (Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
            registerError('telegram', 'Code error: ' . $error);
            $this->log('Code error: ' . $error);
        }
        $output = ob_get_clean();
        if ($output !== '' && $output !== false) {
            $this->debug('Code output: ' . $output);
        }
        return array(
            'result' => $result,
            'skip' => !empty($skip),
            'error' => $error,
        );
    }

    /**
     * Execute a command by its title/alias.
     *
     * @param array         $user
     * @param string        $commandText
     * @param TelegramUpdate $update
     * @param string        $rawText
     * @param string        $args
     * @return bool
     */
    protected function executeCommandByTitle(array $user, $commandText, TelegramUpdate $update, $rawText = '', $args = '')
    {
        $commandText = trim((string)$commandText);
        if ($commandText === '') {
            return false;
        }
        $commandText = ltrim($commandText, '/');
        $parts = preg_split('/\s+/u', $commandText, 2);
        $firstWord = isset($parts[0]) ? $parts[0] : '';
        if ($args === '' && isset($parts[1])) {
            $args = trim($parts[1]);
        }

        $command = $this->findCommand($user, $commandText, $firstWord);
        if (!$command) {
            return false;
        }

        $this->info('Find command - ' . $command['TITLE']);
        if (trim((string)$command['CODE']) === '') {
            return false;
        }

        $vars = array(
            'chat_id' => $update->chatId(),
            'username' => $update->username(),
            'fullname' => $update->fullName(),
            'user' => $user,
            'data' => $update->raw(),
            'update' => $update,
            'message' => $update->message(),
            'text' => $rawText !== '' ? $rawText : $commandText,
            'command' => $command['TITLE'],
            'args' => $args,
            'bot_username' => (string)$this->config['TLG_BOT_USERNAME'],
            'telegram_module' => $this,
        );

        $executed = $this->evaluateCode($command['CODE'], $vars);
        $command['RUNS'] = (int)$command['RUNS'] + 1;
        $command['LAST_RUN'] = date('Y-m-d H:i:s');
        SQLUpdate('tlg_cmd', $command);

        if (!empty($executed['error'])) {
            $this->sendMessage($update->chatId(), 'Ошибка выполнения команды: ' . md_tlg_h($command['TITLE']));
            return true;
        }

        $result = $executed['result'];
        if (is_string($result) && $result !== '') {
            $keyboard = $this->getKeyb($user);
            $this->sendMessage($update->chatId(), $result, $keyboard, 'HTML');
            $this->info('Sent command result to ' . $update->chatId());
        }
        return true;
    }

    /**
     * @param array  $user
     * @param string $fullText
     * @param string $firstWord
     * @return array|null
     */
    protected function findCommand(array $user, $fullText, $firstWord)
    {
        $userId = (int)$user['ID'];
        $isAdmin = (int)$user['ADMIN'] === 1 ? 1 : 0;
        $accessSql = '(ACCESS=3';
        if ($isAdmin) {
            $accessSql .= ' OR ACCESS IN (1,2)';
        } else {
            $accessSql .= ' OR (ACCESS IN (1,2) AND EXISTS(SELECT 1 FROM tlg_user_cmd WHERE CMD_ID=tlg_cmd.ID AND USER_ID=' . $userId . '))';
        }
        $accessSql .= ')';

        $commands = SQLSelect('SELECT * FROM tlg_cmd WHERE ' . $accessSql . ' ORDER BY PRIORITY DESC, TITLE ASC');
        $candidates = array();
        foreach (array($fullText, $firstWord) as $candidate) {
            $candidate = mb_strtolower(trim((string)$candidate), 'UTF-8');
            $candidate = trim($candidate, "/ \t\n\r\0\x0B");
            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
        }
        if (empty($candidates)) {
            return null;
        }

        // Exact title/alias match has priority over a prefix match.
        foreach ($candidates as $candidate) {
            foreach ((array)$commands as $command) {
                $title = mb_strtolower(trim((string)$command['TITLE']), 'UTF-8');
                $title = trim($title, '/');
                if ($title === $candidate || $this->commandHasAlias($command, $candidate)) {
                    return $command;
                }
            }
        }
        return null;
    }

    /**
     * @param array  $command
     * @param string $candidate Lowercase command without slash.
     * @return bool
     */
    protected function commandHasAlias(array $command, $candidate)
    {
        $aliases = (string)(isset($command['ALIASES']) ? $command['ALIASES'] : '');
        if (trim($aliases) === '') {
            return false;
        }
        foreach (preg_split('/[,;]+/u', $aliases) as $alias) {
            $alias = mb_strtolower(trim($alias), 'UTF-8');
            $alias = trim($alias, '/');
            if ($alias !== '' && $alias === $candidate) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize a comma/space separated alias list.
     *
     * @param string $aliases
     * @return string
     */
    public function normalizeAliases($aliases)
    {
        $result = array();
        foreach (preg_split('/[,;]+/u', (string)$aliases) as $alias) {
            $alias = trim($alias);
            $alias = ltrim($alias, '/');
            if ($alias === '') {
                continue;
            }
            $key = mb_strtolower($alias, 'UTF-8');
            if (!isset($result[$key])) {
                $result[$key] = $alias;
            }
        }
        return implode(', ', array_values($result));
    }

    /**
     * @param mixed $value
     * @return array<int,int>
     */
    public function submittedUserIds($value)
    {
        $ids = array();
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = explode(',', (string)$value);
        }
        foreach ($items as $item) {
            $item = (int)$item;
            if ($item > 0 && !in_array($item, $ids, true)) {
                $ids[] = $item;
            }
        }
        return $ids;
    }

    /**
     * Replace the per-user access list of a command.
     *
     * @param int   $commandId
     * @param mixed $value
     * @return void
     */
    public function saveCommandAccess($commandId, $value)
    {
        $commandId = (int)$commandId;
        if ($commandId <= 0) {
            return;
        }
        SQLExec('DELETE FROM tlg_user_cmd WHERE CMD_ID=' . $commandId);
        foreach ($this->submittedUserIds($value) as $userId) {
            $link = array('CMD_ID' => $commandId, 'USER_ID' => $userId);
            SQLInsert('tlg_user_cmd', $link);
        }
    }

    /**
     * Build the reply keyboard for a user.
     *
     * @param array $user
     * @return array
     */
    public function getKeyb($user)
    {
        if (empty($user['CMD'])) {
            return $this->buildKeyBoardHide();
        }

        $userId = (int)$user['ID'];
        $isAdmin = (int)$user['ADMIN'] === 1 ? 1 : 0;
        $accessSql = '(ACCESS=3';
        if ($isAdmin) {
            $accessSql .= ' OR ACCESS IN (1,2)';
        } else {
            $accessSql .= ' OR (ACCESS IN (1,2) AND EXISTS(SELECT 1 FROM tlg_user_cmd WHERE CMD_ID=tlg_cmd.ID AND USER_ID=' . $userId . '))';
        }
        $accessSql .= ')';

        $commands = SQLSelect("SELECT * FROM tlg_cmd WHERE " . $accessSql . " ORDER BY PRIORITY DESC, TITLE ASC");
        $buttons = array();
        foreach ((array)$commands as $command) {
            $show = false;
            if ((int)$command['SHOW_MODE'] === 1) {
                $show = true;
            } elseif ((int)$command['SHOW_MODE'] === 3) {
                $linkedObject = trim((string)$command['LINKED_OBJECT']);
                $linkedProperty = trim((string)$command['LINKED_PROPERTY']);
                if ($linkedObject !== '' && $linkedProperty !== '') {
                    $value = gg($linkedObject . '.' . $linkedProperty);
                    $conditionValue = (string)$command['CONDITION_VALUE'];
                    switch ((int)$command['CONDITION']) {
                        case 1:
                            $show = (string)$value === $conditionValue;
                            break;
                        case 2:
                            $show = $value > $conditionValue;
                            break;
                        case 3:
                            $show = $value < $conditionValue;
                            break;
                        case 4:
                            $show = (string)$value !== $conditionValue;
                            break;
                    }
                }
            }
            if ($show) {
                $buttons[] = (string)$command['TITLE'];
            }
        }

        if (empty($buttons)) {
            return $this->buildKeyBoardHide();
        }

        $rows = max(1, (int)$this->config['TLG_COUNT_ROW']);
        $keyboardRows = array_chunk($buttons, $rows);
        return $this->buildKeyboard($keyboardRows, false, true, true);
    }

    /**
     * @param array $user
     * @param array $location
     * @return void
     */
    protected function updateUserLocation(array $user, array $location)
    {
        $latitude = isset($location['latitude']) ? $location['latitude'] : null;
        $longitude = isset($location['longitude']) ? $location['longitude'] : null;
        if ($latitude === null || $longitude === null) {
            return;
        }
        $this->info('Location from ' . $user['USER_ID'] . ': ' . $latitude . ',' . $longitude);
        if (empty($user['MEMBER_ID'])) {
            return;
        }
        $member = SQLSelectOne('SELECT * FROM users WHERE ID=' . (int)$user['MEMBER_ID']);
        if (!empty($member['LINKED_OBJECT'])) {
            setGlobal($member['LINKED_OBJECT'] . '.Coordinates', $latitude . ',' . $longitude);
            setGlobal($member['LINKED_OBJECT'] . '.CoordinatesUpdated', date('H:i'));
            setGlobal($member['LINKED_OBJECT'] . '.CoordinatesUpdatedTimestamp', time());
        }
        if (function_exists('isModuleInstalled') && isModuleInstalled('app_gpstrack')) {
            getURLBackground(rtrim(BASE_URL, '/') . '/gps.php?latitude=' . urlencode($latitude) . '&longitude=' . urlencode($longitude) . '&accuracy=' . urlencode(isset($location['horizontal_accuracy']) ? $location['horizontal_accuracy'] : '') . '&deviceid=' . urlencode('telegram' . $user['USER_ID']), 0);
        }
    }

    /**
     * Download incoming media into the configured storage.
     *
     * @param array $user
     * @param array $media
     * @return string Local path or empty string.
     */
    protected function downloadIncomingMedia(array $user, array $media)
    {
        if (empty($media['file_id'])) {
            return '';
        }
        $file = $this->api->getFile($media['file_id']);
        if (empty($file['ok']) || empty($file['result']['file_path'])) {
            $this->warning('getFile failed for ' . $media['file_id'] . ': ' . (isset($file['description']) ? $file['description'] : ''));
            return '';
        }
        $remotePath = (string)$file['result']['file_path'];
        $directory = md_tlg_storage_dir($this->config) . '/' . $media['kind'] . '/' . preg_replace('/[^0-9-]/', '', (string)$user['USER_ID']);
        $basename = basename($remotePath);
        if (!empty($media['file_name'])) {
            $basename = $this->sanitizeFileName($media['file_name']);
        }
        $destination = $directory . '/' . $basename;
        if (is_file($destination)) {
            $info = pathinfo($destination);
            $destination = $directory . '/' . $info['filename'] . '_' . date('YmdHis') . (isset($info['extension']) ? '.' . $info['extension'] : '');
        }
        $result = $this->api->downloadFile($remotePath, $destination, 50 * 1024 * 1024);
        if (empty($result['ok'])) {
            $this->warning('File download failed: ' . $result['error']);
            return '';
        }
        $this->info('Saved ' . $media['kind'] . ' to ' . $destination);
        return $destination;
    }

    /**
     * @param string $name
     * @return string
     */
    protected function sanitizeFileName($name)
    {
        $name = basename(str_replace(array('\\', '/'), '/', (string)$name));
        $name = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $name);
        $name = trim($name, '._');
        if ($name === '') {
            $name = 'file_' . date('YmdHis');
        }
        return mb_substr($name, 0, 120, 'UTF-8');
    }

    /**
     * @param string $path
     * @return void
     */
    protected function playIncomingMedia($path)
    {
        $this->info('Play voice: ' . $path);
        if ((int)$this->config['TLG_PLAYER'] === 2 && function_exists('playMedia')) {
            playMedia($path, 'localhost', true);
        } elseif (function_exists('playSound')) {
            playSound($path, 1, 0);
        }
    }

    /**
     * Retry failed outgoing messages once.
     *
     * @return void
     */
    public function resendData()
    {
        $records = SQLSelect("SELECT * FROM tlg_history WHERE DIRECTION=2 AND CREATED >= NOW() - INTERVAL 1 DAY ORDER BY ID ASC LIMIT 50");
        foreach ((array)$records as $record) {
            $raw = json_decode((string)$record['RAW'], true);
            $endpoint = isset($raw['endpoint']) ? (string)$raw['endpoint'] : '';
            $content = isset($raw['content']) && is_array($raw['content']) ? $raw['content'] : array();
            if ($endpoint === '' || empty($content) || !$this->isMessagingEndpoint($endpoint)) {
                $record['DIRECTION'] = 4;
                SQLUpdate('tlg_history', $record);
                continue;
            }
            foreach (array('photo', 'video', 'audio', 'document', 'voice', 'animation', 'sticker') as $fileField) {
                if (isset($content[$fileField]) && is_string($content[$fileField]) && !is_file($content[$fileField]) && !preg_match('/^[A-Za-z0-9_-]{20,}$/', $content[$fileField])) {
                    unset($content[$fileField]);
                }
            }
            if (isset($content['text']) && is_string($content['text'])) {
                $content['text'] .= ' (повторная отправка от ' . $record['CREATED'] . ')';
            }
            $result = $this->api->request($endpoint, $content);
            if (!empty($result['ok'])) {
                $record['DIRECTION'] = 3;
                SQLUpdate('tlg_history', $record);
            } else {
                $record['DIRECTION'] = 4;
                SQLUpdate('tlg_history', $record);
            }
        }
    }

    /**
     * Save an update or API result in the history table.
     *
     * @param array $data
     * @param int   $direction
     * @return void
     */
    public function saveData($data, $direction)
    {
        if (!empty($this->config['TLG_HISTORY_ONLY_ERRORS']) && (int)$direction < 2) {
            return;
        }

        $direction = (int)$direction;
        $record = array(
            'DIRECTION' => $direction,
            'USER_ID' => 0,
            'CREATED' => date('Y-m-d H:i:s'),
            'TYPE' => 0,
            'MESSAGE' => '',
            'MESSAGE_ID' => 0,
            'CHAT_TITLE' => '',
            'ENDPOINT' => '',
        );

        if ($direction === 0) {
            $update = new TelegramUpdate(is_array($data) ? $data : array());
            $record['USER_ID'] = (string)$update->chatId();
            $record['TYPE'] = (int)$update->eventType();
            $record['MESSAGE'] = $update->summary();
            $record['MESSAGE_ID'] = $update->messageId();
            $record['CHAT_TITLE'] = $update->chatTitle();
        } elseif (isset($data['endpoint'])) {
            $record['ENDPOINT'] = (string)$data['endpoint'];
            $content = isset($data['content']) && is_array($data['content']) ? $data['content'] : array();
            $record['USER_ID'] = isset($content['chat_id']) ? (string)$content['chat_id'] : '0';
            $record['MESSAGE_ID'] = isset($data['result']['message_id']) ? (int)$data['result']['message_id'] : 0;
            $meta = $this->messageTypeFromEndpoint($record['ENDPOINT']);
            $record['TYPE'] = $meta;
            if (isset($content['text'])) {
                $record['MESSAGE'] = (string)$content['text'];
            } elseif (isset($content['caption'])) {
                $record['MESSAGE'] = (string)$content['caption'];
            } else {
                $record['MESSAGE'] = '[' . $record['ENDPOINT'] . ']';
            }
            if (empty($data['ok'])) {
                $errorCode = isset($data['error_code']) ? (int)$data['error_code'] : 0;
                $errorText = isset($data['description']) ? (string)$data['description'] : '';
                if ($errorText === '' && isset($data['curl_error'])) {
                    $errorText = (string)$data['curl_error'];
                }
                $prefix = $errorCode > 0 ? 'Ошибка ' . $errorCode . ': ' : 'Ошибка: ';
                $record['MESSAGE'] = $prefix . $errorText . ' | ' . $record['MESSAGE'];
            }
        } elseif (isset($data['error_code']) || isset($data['curl_error_code'])) {
            $record['MESSAGE'] = 'Ошибка: ' . (isset($data['error_code']) ? $data['error_code'] : '') . ' ' . (isset($data['description']) ? $data['description'] : '') . ' ' . (isset($data['curl_error']) ? $data['curl_error'] : '');
        }

        $unicode = !empty($this->config['TLG_SAVE_UNICODE']);
        $raw = json_encode($data, $unicode ? (JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 0);
        if (strlen($raw) > 60000) {
            $raw = substr($raw, 0, 60000) . '…';
        }
        $record['RAW'] = $raw;
        $record['MESSAGE'] = md_tlg_truncate($record['MESSAGE'], 4000);

        try {
            SQLInsert('tlg_history', $record);
        } catch (Throwable $e) {
            $this->log('Failed to save history: ' . $e->getMessage());
        }
    }

    /**
     * @param string $endpoint
     * @return int
     */
    protected function messageTypeFromEndpoint($endpoint)
    {
        switch ($endpoint) {
            case 'sendPhoto':
                return 2;
            case 'sendVoice':
                return 3;
            case 'sendAudio':
                return 4;
            case 'sendVideo':
                return 5;
            case 'sendDocument':
                return 6;
            case 'sendSticker':
                return 7;
            case 'sendLocation':
                return 8;
            case 'sendVideoNote':
                return 11;
            case 'sendContact':
                return 12;
            case 'sendVenue':
                return 13;
            case 'sendDice':
                return 14;
            case 'sendAnimation':
                return 15;
            default:
                return 1;
        }
    }

    /**
     * Remove old history according to TLG_HISTORY_DAYS.
     *
     * @return void
     */
    protected function pruneHistory()
    {
        $days = (int)$this->config['TLG_HISTORY_DAYS'];
        if ($days <= 0) {
            return;
        }
        SQLExec('DELETE FROM tlg_history WHERE CREATED < NOW() - INTERVAL ' . $days . ' DAY');
    }

    /**
     * Frontend entry point (also handles legacy /ajax/telegram.html calls).
     *
     * @param array $out
     * @return void
     */
    public function usual(&$out)
    {
        if ($this->ajax) {
            $op = gr('op');
            if ($op === 'get_raw') {
                header('Content-Type: application/json; charset=utf-8');
                $record = SQLSelectOne('SELECT * FROM tlg_history WHERE ID=' . (int)gr('id'));
                echo isset($record['RAW']) ? $record['RAW'] : '';
                exit;
            }
        }
        $this->admin($out);
    }

    /**
     * Update the commands menu of the bot.
     *
     * @return array
     */
    public function syncBotCommands()
    {
        $commands = SQLSelect('SELECT * FROM tlg_cmd WHERE SHOW_MODE=1 ORDER BY PRIORITY DESC, TITLE ASC');
        $payload = array();
        foreach ((array)$commands as $command) {
            $name = mb_strtolower(trim((string)$command['TITLE']), 'UTF-8');
            $name = preg_replace('/[^a-z0-9_]/', '_', $name);
            $name = trim($name, '_');
            if ($name === '' || mb_strlen($name, 'UTF-8') > 32) {
                continue;
            }
            $description = trim((string)$command['DESCRIPTION']);
            if ($description === '') {
                $description = (string)$command['TITLE'];
            }
            $payload[] = array(
                'command' => $name,
                'description' => mb_substr($description, 0, 256, 'UTF-8'),
            );
        }
        if (empty($payload)) {
            return array('ok' => false, 'description' => 'Нет команд с отображением в клавиатуре.');
        }
        $result = $this->api->setMyCommands($payload);
        if (!empty($result['ok'])) {
            $this->config['TLG_MENU_SYNCED'] = date('Y-m-d H:i:s');
            $this->saveConfig();
            $result['count'] = count($payload);
        }
        return $result;
    }

    /**
     * Search integration used by the old panel search.
     *
     * @param string $data
     * @return array
     */
    public function findData($data)
    {
        $result = array();
        $safe = DBSafe($data);
        $commands = SQLSelect("SELECT ID, TITLE, DESCRIPTION FROM tlg_cmd WHERE TITLE LIKE '%" . $safe . "%' OR DESCRIPTION LIKE '%" . $safe . "%' OR CODE LIKE '%" . $safe . "%' ORDER BY TITLE");
        foreach ((array)$commands as $command) {
            $result[] = '<span class="md-badge md-badge--primary">Команда</span> <a href="' . $this->adminUrl(array('view_mode' => 'edit_cmd', 'id' => (int)$command['ID'])) . '">' . md_tlg_h($command['TITLE']) . '</a>';
        }
        $events = SQLSelect("SELECT ID, TITLE, DESCRIPTION FROM tlg_event WHERE TITLE LIKE '%" . $safe . "%' OR DESCRIPTION LIKE '%" . $safe . "%' OR CODE LIKE '%" . $safe . "%' ORDER BY TITLE");
        foreach ((array)$events as $event) {
            $result[] = '<span class="md-badge md-badge--info">Событие</span> <a href="' . $this->adminUrl(array('view_mode' => 'edit_event', 'id' => (int)$event['ID'])) . '">' . md_tlg_h($event['TITLE']) . '</a>';
        }
        return $result;
    }

    /**
     * SAY/SAYTO subscription.
     *
     * @param string $event
     * @param array  $details
     * @return void
     */
    public function processSubscription($event, &$details)
    {
        $this->loadConfigSafe();
        $this->refreshApi();
        $this->debug('event=' . $event . ' details=' . json_encode($details));
        if (!in_array($event, array('SAY', 'SAYTO', 'SAYREPLY'), true)) {
            return;
        }

        $level = isset($details['level']) ? (int)$details['level'] : 0;
        $message = isset($details['message']) ? (string)$details['message'] : '';
        $image = isset($details['image']) ? (string)$details['image'] : '';
        $destination = '';
        if (isset($details['destination'])) {
            $destination = (string)$details['destination'];
        } elseif (isset($details['source'])) {
            $destination = (string)$details['source'];
        }

        $users = SQLSelect('SELECT * FROM tlg_user WHERE HISTORY=1');
        foreach ((array)$users as $user) {
            $chatId = (string)$user['USER_ID'];
            if ($chatId === '0' || $chatId === '') {
                $chatId = (string)$user['NAME'];
            }
            if ($chatId === '') {
                continue;
            }
            $matchesDestination = $destination === '' || $destination === 'telegram' . $user['ID'];
            if (!$matchesDestination) {
                continue;
            }
            if ($destination === '' && $level < (int)$user['HISTORY_LEVEL']) {
                continue;
            }
            $silent = $level < (int)$user['HISTORY_SILENT'];
            if ($image !== '' && file_exists($image)) {
                $this->sendImage($chatId, $image, $message, '', '', $silent);
            } else {
                $this->sendMessage($chatId, $message, '', 'HTML', '', $silent);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Webhook entry point
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming HTTPS webhook request from Telegram.
     *
     * @return void
     */
    public function handleWebhook()
    {
        $this->loadConfigSafe();
        $this->refreshApi();

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            echo 'Method Not Allowed';
            return;
        }

        if (empty($this->config['TLG_WEBHOOK'])) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo '{"ok":true,"skipped":"webhook disabled"}';
            return;
        }

        $secret = (string)$this->config['TLG_WEBHOOK_SECRET'];
        if ($secret !== '') {
            $header = isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) ? (string)$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] : '';
            if (!hash_equals($secret, $header)) {
                $this->warning('Webhook request with invalid secret token');
                http_response_code(403);
                echo 'Forbidden';
                return;
            }
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || strlen($raw) > 2 * 1024 * 1024) {
            http_response_code(413);
            echo 'Payload too large';
            return;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['update_id'])) {
            http_response_code(400);
            echo 'Bad Request';
            return;
        }

        $updateId = (int)$data['update_id'];
        if ($updateId === (int)$this->config['TLG_WEBHOOK_LAST_UPDATE_ID']) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo '{"ok":true,"duplicate":true}';
            return;
        }

        try {
            $this->processUpdate($data);
        } catch (Throwable $e) {
            $this->log('Webhook update ' . $updateId . ' failed: ' . $e->getMessage());
            registerError('telegram', 'Webhook update: ' . $e->getMessage());
        }

        $this->config['TLG_WEBHOOK_LAST_UPDATE_ID'] = $updateId;
        $this->config['TLG_LAST_CHECK'] = date('Y-m-d H:i:s');
        $this->saveConfig();

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo '{"ok":true}';
    }

    // -------------------------------------------------------------------------
    // Install / schema
    // -------------------------------------------------------------------------

    /**
     * @param string $data
     * @return void
     */
    public function install($data = '')
    {
        subscribeToEvent($this->name, 'SAY', '', 10);
        subscribeToEvent($this->name, 'SAYTO', '', 10);
        subscribeToEvent($this->name, 'SAYREPLY', '', 10);
        parent::install();

        $this->loadConfigSafe();
        $this->config['TLG_SCHEMA_VERSION'] = self::SCHEMA_VERSION;
        $this->saveConfig();
        $this->upgradeSchema();
        if (!empty($this->config['TLG_SAVE_UNICODE'])) {
            $this->ensureUtf8mb4();
            $this->ensureHistoryUnicode();
        }
        $this->seedDefaults();
    }

    /**
     * @return void
     */
    public function uninstall()
    {
        SQLExec('DROP TABLE IF EXISTS tlg_user_cmd');
        SQLExec('DROP TABLE IF EXISTS tlg_user');
        SQLExec('DROP TABLE IF EXISTS tlg_cmd');
        SQLExec('DROP TABLE IF EXISTS tlg_event');
        SQLExec('DROP TABLE IF EXISTS tlg_history');
        unsubscribeFromEvent($this->name, 'SAY');
        unsubscribeFromEvent($this->name, 'SAYTO');
        unsubscribeFromEvent($this->name, 'SAYREPLY');
        parent::uninstall();
    }

    /**
     * @return void
     */
    protected function maybeUpgradeSchema()
    {
        if ((int)$this->config['TLG_SCHEMA_VERSION'] >= self::SCHEMA_VERSION) {
            return;
        }
        if (!function_exists('SQLTableExists') || !SQLTableExists('tlg_user')) {
            return;
        }
        $this->upgradeSchema();
        $this->config['TLG_SCHEMA_VERSION'] = self::SCHEMA_VERSION;
        $this->saveConfig();
    }

    /**
     * Add columns and indexes introduced after the first release.
     *
     * @return void
     */
    protected function upgradeSchema()
    {
        $columns = array(
            'tlg_user' => array(
                'USERNAME' => "varchar(255) NOT NULL DEFAULT ''",
                'LAST_SEEN' => 'datetime NULL DEFAULT NULL',
                'LANGUAGE' => "varchar(10) NOT NULL DEFAULT ''",
                'PHOTO' => "varchar(255) NOT NULL DEFAULT ''",
            ),
            'tlg_cmd' => array(
                'ALIASES' => "varchar(255) NOT NULL DEFAULT ''",
                'PARSE_MODE' => "varchar(20) NOT NULL DEFAULT 'HTML'",
                'RUNS' => "int(10) NOT NULL DEFAULT '0'",
                'LAST_RUN' => 'datetime NULL DEFAULT NULL',
            ),
            'tlg_event' => array(
                'RUNS' => "int(10) NOT NULL DEFAULT '0'",
                'LAST_RUN' => 'datetime NULL DEFAULT NULL',
            ),
            'tlg_history' => array(
                'MESSAGE_ID' => "int(10) NOT NULL DEFAULT '0'",
                'CHAT_TITLE' => "varchar(255) NOT NULL DEFAULT ''",
                'ENDPOINT' => "varchar(64) NOT NULL DEFAULT ''",
            ),
        );

        foreach ($columns as $table => $fields) {
            if (!SQLTableExists($table)) {
                continue;
            }
            foreach ($fields as $field => $definition) {
                if (!$this->columnExists($table, $field)) {
                    try {
                        SQLExec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $field . '` ' . $definition);
                    } catch (Throwable $e) {
                        $this->log('Schema upgrade failed for ' . $table . '.' . $field . ': ' . $e->getMessage());
                    }
                }
            }
        }

        $indexes = array(
            'tlg_history' => array(
                'idx_tlg_history_created' => '(`CREATED`)',
                'idx_tlg_history_direction' => '(`DIRECTION`)',
                'idx_tlg_history_user' => '(`USER_ID`)',
            ),
            'tlg_user_cmd' => array(
                'idx_tlg_user_cmd_user' => '(`USER_ID`)',
                'idx_tlg_user_cmd_cmd' => '(`CMD_ID`)',
            ),
        );
        foreach ($indexes as $table => $list) {
            if (!SQLTableExists($table)) {
                continue;
            }
            foreach ($list as $index => $definition) {
                try {
                    $exists = SQLSelectOne("SHOW INDEX FROM `" . $table . "` WHERE Key_name='" . DBSafe($index) . "'");
                    if (!$exists) {
                        SQLExec('ALTER TABLE `' . $table . '` ADD INDEX `' . $index . '` ' . $definition);
                    }
                } catch (Throwable $e) {
                    $this->log('Index creation failed for ' . $table . '.' . $index . ': ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * @param string $table
     * @param string $column
     * @return bool
     */
    protected function columnExists($table, $column)
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->schema_cache)) {
            return $this->schema_cache[$key];
        }
        $row = SQLSelectOne("SHOW COLUMNS FROM `" . $table . "` LIKE '" . DBSafe($column) . "'");
        $this->schema_cache[$key] = !empty($row);
        return $this->schema_cache[$key];
    }

    /**
     * Convert module tables to utf8mb4 (fresh installations).
     *
     * @return void
     */
    protected function ensureUtf8mb4()
    {
        try {
            $collation = SQLSelectOne("SHOW COLLATION WHERE Collation='utf8mb4_unicode_ci'");
            if (empty($collation)) {
                return;
            }
            foreach (array('tlg_user', 'tlg_cmd', 'tlg_user_cmd', 'tlg_event', 'tlg_history') as $table) {
                if (!SQLTableExists($table)) {
                    continue;
                }
                $status = SQLSelectOne("SHOW TABLE STATUS LIKE '" . DBSafe($table) . "'");
                if (!empty($status['Collation']) && stripos((string)$status['Collation'], 'utf8mb4') === 0) {
                    continue;
                }
                SQLExec("ALTER TABLE `" . $table . "` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        } catch (Throwable $e) {
            $this->log('utf8mb4 migration failed: ' . $e->getMessage());
        }
    }

    /**
     * Ensure emoji-safe text columns for the history table.
     *
     * @return void
     */
    protected function ensureHistoryUnicode()
    {
        try {
            $collation = SQLSelectOne("SHOW COLLATION WHERE Collation='utf8mb4_unicode_ci'");
            if (empty($collation)) {
                return;
            }
            $column = SQLSelectOne("SHOW FULL COLUMNS FROM tlg_history LIKE 'MESSAGE'");
            if (!empty($column['Collation']) && stripos((string)$column['Collation'], 'utf8mb4') === 0) {
                return;
            }
            SQLExec("ALTER TABLE tlg_history CHANGE COLUMN `MESSAGE` `MESSAGE` TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci'");
            SQLExec("ALTER TABLE tlg_history CHANGE COLUMN `RAW` `RAW` TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci'");
        } catch (Throwable $e) {
            $this->log('Unicode migration failed: ' . $e->getMessage());
        }
    }

    /**
     * Example command/event for a fresh installation.
     *
     * @return void
     */
    protected function seedDefaults()
    {
        $command = SQLSelectOne('SELECT * FROM tlg_cmd LIMIT 1');
        if (!$command) {
            $seedCommand = array(
                'TITLE' => 'Ping',
                'ALIASES' => 'пинг,ping',
                'DESCRIPTION' => 'Проверка связи: бот отвечает Pong!',
                'CODE' => "return 'Pong!';",
                'ACCESS' => 2,
                'SHOW_MODE' => 1,
                'PRIORITY' => 1,
                'RUNS' => 0,
            );
            SQLInsert('tlg_cmd', $seedCommand);
        }
        $event = SQLSelectOne('SELECT * FROM tlg_event LIMIT 1');
        if (!$event) {
            $seedEvent = array(
                'TITLE' => 'Приветствие',
                'DESCRIPTION' => 'Пример обработчика текстовых сообщений.',
                'TYPE_EVENT' => 1,
                'ENABLE' => 0,
                'CODE' => md_tlg_event_example(1),
                'RUNS' => 0,
            );
            SQLInsert('tlg_event', $seedEvent);
        }
    }

    /**
     * @param string $data
     * @return void
     */
    public function dbInstall($data)
    {
        $data = <<<EOD
 tlg_user: ID int(10) unsigned NOT NULL auto_increment
 tlg_user: NAME varchar(255) NOT NULL DEFAULT ''
 tlg_user: USERNAME varchar(255) NOT NULL DEFAULT ''
 tlg_user: USER_ID varchar(25) NOT NULL DEFAULT '0'
 tlg_user: MEMBER_ID int(10) NOT NULL DEFAULT '1'
 tlg_user: CREATED datetime
 tlg_user: LAST_SEEN datetime
 tlg_user: LANGUAGE varchar(10) NOT NULL DEFAULT ''
 tlg_user: PHOTO varchar(255) NOT NULL DEFAULT ''
 tlg_user: ADMIN int(3) unsigned NOT NULL DEFAULT '0'
 tlg_user: SILENT int(3) unsigned NOT NULL DEFAULT '0'
 tlg_user: HISTORY int(3) unsigned NOT NULL DEFAULT '0'
 tlg_user: HISTORY_LEVEL int(3) unsigned NOT NULL DEFAULT '0'
 tlg_user: HISTORY_SILENT int(3) unsigned NOT NULL DEFAULT '0'
 tlg_user: CMD int(3) unsigned NOT NULL DEFAULT '0'
 tlg_user: PATTERNS int(3) unsigned NOT NULL DEFAULT '0'
 tlg_user: DOWNLOAD int(3) unsigned NOT NULL DEFAULT '0'
 tlg_user: PLAY int(3) unsigned NOT NULL DEFAULT '0'

 tlg_cmd: ID int(10) unsigned NOT NULL auto_increment
 tlg_cmd: TITLE varchar(255) NOT NULL DEFAULT ''
 tlg_cmd: ALIASES varchar(255) NOT NULL DEFAULT ''
 tlg_cmd: DESCRIPTION text
 tlg_cmd: CODE text
 tlg_cmd: PARSE_MODE varchar(20) NOT NULL DEFAULT 'HTML'
 tlg_cmd: ACCESS int(10) NOT NULL DEFAULT '0'
 tlg_cmd: SHOW_MODE int(10) NOT NULL DEFAULT '1'
 tlg_cmd: LINKED_OBJECT varchar(255) NOT NULL DEFAULT ''
 tlg_cmd: LINKED_PROPERTY varchar(255) NOT NULL DEFAULT ''
 tlg_cmd: CONDITION int(10) NOT NULL DEFAULT '1'
 tlg_cmd: CONDITION_VALUE varchar(255) NOT NULL DEFAULT ''
 tlg_cmd: PRIORITY int(10) NOT NULL DEFAULT '1'
 tlg_cmd: RUNS int(10) NOT NULL DEFAULT '0'
 tlg_cmd: LAST_RUN datetime

 tlg_user_cmd: ID int(10) unsigned NOT NULL auto_increment
 tlg_user_cmd: USER_ID int(10) NOT NULL
 tlg_user_cmd: CMD_ID int(10) NOT NULL

 tlg_event: ID int(10) unsigned NOT NULL auto_increment
 tlg_event: TITLE varchar(255) NOT NULL DEFAULT ''
 tlg_event: DESCRIPTION text
 tlg_event: TYPE_EVENT int(3) unsigned NOT NULL DEFAULT '1'
 tlg_event: ENABLE int(3) unsigned NOT NULL DEFAULT '0'
 tlg_event: CODE text
 tlg_event: RUNS int(10) NOT NULL DEFAULT '0'
 tlg_event: LAST_RUN datetime

 tlg_history: ID int(10) unsigned NOT NULL auto_increment
 tlg_history: USER_ID varchar(25) NOT NULL DEFAULT '0'
 tlg_history: CREATED datetime
 tlg_history: DIRECTION int(3) unsigned NOT NULL DEFAULT '1'
 tlg_history: TYPE int(3) unsigned NOT NULL DEFAULT '1'
 tlg_history: MESSAGE_ID int(10) NOT NULL DEFAULT '0'
 tlg_history: CHAT_TITLE varchar(255) NOT NULL DEFAULT ''
 tlg_history: ENDPOINT varchar(64) NOT NULL DEFAULT ''
 tlg_history: MESSAGE text
 tlg_history: RAW text

EOD;
        parent::dbInstall($data);
    }

    /**
     * Delete a user and its command bindings.
     *
     * @param int $id
     * @return void
     */
    public function delete_user($id)
    {
        $record = SQLSelectOne('SELECT * FROM tlg_user WHERE ID=' . (int)$id);
        if (!empty($record['ID'])) {
            SQLExec('DELETE FROM tlg_user WHERE ID=' . (int)$record['ID']);
            SQLExec('DELETE FROM tlg_user_cmd WHERE USER_ID=' . (int)$record['ID']);
        }
    }

    /**
     * @param int $id
     * @return void
     */
    public function delete_cmd($id)
    {
        $record = SQLSelectOne('SELECT * FROM tlg_cmd WHERE ID=' . (int)$id);
        if (!empty($record['ID'])) {
            SQLExec('DELETE FROM tlg_cmd WHERE ID=' . (int)$record['ID']);
            SQLExec('DELETE FROM tlg_user_cmd WHERE CMD_ID=' . (int)$record['ID']);
        }
    }

    /**
     * @param int $id
     * @return void
     */
    public function delete_event($id)
    {
        $record = SQLSelectOne('SELECT * FROM tlg_event WHERE ID=' . (int)$id);
        if (!empty($record['ID'])) {
            SQLExec('DELETE FROM tlg_event WHERE ID=' . (int)$record['ID']);
        }
    }

    /**
     * @param int $id
     * @return void
     */
    public function delete_history($id)
    {
        SQLExec('DELETE FROM tlg_history WHERE ID=' . (int)$id);
    }

    /**
     * Old entry point kept for compatibility.
     *
     * @param int $chat_id
     * @param string $command
     * @return void
     */
    public function execCommand($chat_id, $command)
    {
        $user = SQLSelectOne("SELECT * FROM tlg_user WHERE USER_ID='" . DBSafe($chat_id) . "'");
        if (!$user) {
            return;
        }
        $update = new TelegramUpdate(array(
            'message' => array(
                'message_id' => 0,
                'chat' => array('id' => $chat_id, 'type' => 'private'),
                'from' => array(
                    'id' => $chat_id,
                    'username' => isset($user['USERNAME']) ? $user['USERNAME'] : '',
                    'first_name' => isset($user['NAME']) ? $user['NAME'] : '',
                ),
                'text' => '/' . ltrim((string)$command, '/'),
            ),
        ));
        $this->executeCommandByTitle($user, $command, $update, $command);
    }
}

