<?php
/**
 * Telegram Bot API client for the MajorDoMo telegram module.
 *
 * The client is intentionally dependency-free: it only needs ext-curl, which
 * MajorDoMo already requires. Compared to the historical `Telegram.php` helper
 * bundled with the module it:
 *
 * - keeps TLS verification on by default (and lets the admin turn it off);
 * - supports HTTP, HTTPS, SOCKS5 and SOCKS5-with-remote-DNS proxies;
 * - supports a self-hosted Bot API server (`telegram-bot-api`, MTProto
 *   upstream) via a custom base URL;
 * - sends JSON bodies for regular calls and multipart bodies for uploads;
 * - returns structured errors instead of echoing them into the response.
 *
 * @package MajorDoMo
 */

class TelegramApi
{
    const DEFAULT_BASE = 'https://api.telegram.org';

    /** @var string */
    private $token = '';
    /** @var string */
    private $base = self::DEFAULT_BASE;
    /** @var string direct|http|https|socks5|socks5h|transparent|local_api */
    private $transport = 'direct';
    /** @var string */
    private $proxyHost = '';
    /** @var int */
    private $proxyPort = 0;
    /** @var string */
    private $proxyLogin = '';
    /** @var string */
    private $proxyPassword = '';
    /** @var int */
    private $timeout = 20;
    /** @var string any|ipv4|ipv6 */
    private $ipVersion = 'any';
    /** @var bool */
    private $sslVerify = true;
    /** @var callable|null */
    private $logger = null;
    /** @var array */
    private $lastError = array();
    /** @var array */
    private $lastMeta = array();

    /**
     * @param array $options
     */
    public function __construct(array $options = array())
    {
        $this->configure($options);
    }

    /**
     * Build a client from the module configuration array.
     *
     * Legacy keys (TLG_USEPROXY/TLG_PROXY_TYPE/TLG_PROXY_URL) are accepted so an
     * installation upgraded from an old module version keeps working before the
     * settings page is saved again.
     *
     * @param array         $config
     * @param callable|null $logger
     * @return TelegramApi
     */
    public static function fromConfig(array $config, $logger = null)
    {
        $transport = isset($config['TLG_TRANSPORT']) ? (string)$config['TLG_TRANSPORT'] : '';
        $proxyHost = isset($config['TLG_PROXY_HOST']) ? (string)$config['TLG_PROXY_HOST'] : '';
        $proxyPort = isset($config['TLG_PROXY_PORT']) ? (int)$config['TLG_PROXY_PORT'] : 0;

        if ($transport === '' || ($transport === 'direct' && $proxyHost === '' && !empty($config['TLG_USEPROXY']))) {
            $transport = self::transportFromLegacy($config);
        }
        if ($proxyHost === '' && !empty($config['TLG_PROXY_URL'])) {
            $parsed = self::parseProxyAddress((string)$config['TLG_PROXY_URL']);
            $proxyHost = $parsed['host'];
            $proxyPort = $parsed['port'];
        }
        if ($proxyPort <= 0) {
            $proxyPort = 1080;
        }

        return new self(array(
            'token' => isset($config['TLG_TOKEN']) ? (string)$config['TLG_TOKEN'] : '',
            'base' => isset($config['TLG_API_BASE']) ? (string)$config['TLG_API_BASE'] : '',
            'transport' => $transport,
            'proxy_host' => $proxyHost,
            'proxy_port' => $proxyPort,
            'proxy_login' => isset($config['TLG_PROXY_LOGIN']) ? (string)$config['TLG_PROXY_LOGIN'] : '',
            'proxy_password' => isset($config['TLG_PROXY_PASSWORD']) ? (string)$config['TLG_PROXY_PASSWORD'] : '',
            'timeout' => isset($config['TLG_HTTP_TIMEOUT']) ? (int)$config['TLG_HTTP_TIMEOUT'] : 20,
            'ip_version' => isset($config['TLG_IPRESOLV']) ? (string)$config['TLG_IPRESOLV'] : 'any',
            'ssl_verify' => !isset($config['TLG_SSL_VERIFY']) || (string)$config['TLG_SSL_VERIFY'] === '1',
            'logger' => $logger,
        ));
    }

    /**
     * @param array $options
     * @return void
     */
    public function configure(array $options = array())
    {
        if (isset($options['token'])) {
            $this->token = trim((string)$options['token']);
        }
        if (isset($options['base'])) {
            $this->base = self::normalizeBase((string)$options['base']);
        }
        if (isset($options['transport'])) {
            $transport = (string)$options['transport'];
            $allowed = array('direct', 'http', 'https', 'socks5', 'socks5h', 'transparent', 'local_api');
            $this->transport = in_array($transport, $allowed, true) ? $transport : 'direct';
        }
        if (isset($options['proxy_host'])) {
            $this->proxyHost = trim((string)$options['proxy_host']);
        }
        if (isset($options['proxy_port'])) {
            $this->proxyPort = (int)$options['proxy_port'];
        }
        if (isset($options['proxy_login'])) {
            $this->proxyLogin = (string)$options['proxy_login'];
        }
        if (isset($options['proxy_password'])) {
            $this->proxyPassword = (string)$options['proxy_password'];
        }
        if (isset($options['timeout'])) {
            $this->timeout = max(3, min(120, (int)$options['timeout']));
        }
        if (isset($options['ip_version'])) {
            $ip = (string)$options['ip_version'];
            $this->ipVersion = in_array($ip, array('any', 'ipv4', 'ipv6'), true) ? $ip : 'any';
        }
        if (isset($options['ssl_verify'])) {
            $this->sslVerify = (bool)$options['ssl_verify'];
        }
        if (array_key_exists('logger', $options) && is_callable($options['logger'])) {
            $this->logger = $options['logger'];
        } else {
            $this->logger = null;
        }
    }

    /**
     * @param string $token
     * @return void
     */
    public function setToken($token)
    {
        $this->token = trim((string)$token);
    }

    /**
     * @return string
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->base;
    }

    /**
     * @return string
     */
    public function getTransport()
    {
        return $this->transport;
    }

    /**
     * @return array
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * @return array
     */
    public function getLastMeta()
    {
        return $this->lastMeta;
    }

    /**
     * Normalize a user supplied API base URL.
     *
     * Accepts "", "https://api.telegram.org", "https://api.telegram.org/",
     * "https://api.telegram.org/bot", "http://127.0.0.1:8081/bot123:abc".
     *
     * @param string $base
     * @return string
     */
    public static function normalizeBase($base)
    {
        $base = trim((string)$base);
        if ($base === '') {
            return self::DEFAULT_BASE;
        }
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'http://' . $base;
        }
        $base = rtrim($base, '/');
        $base = preg_replace('#/bot[^/]*$#', '', $base);
        return rtrim($base, '/');
    }

    /**
     * Convert the pre-1.0 proxy settings to the new transport names.
     *
     * @param array $config
     * @return string
     */
    public static function transportFromLegacy(array $config)
    {
        if (empty($config['TLG_USEPROXY'])) {
            return 'direct';
        }
        $type = isset($config['TLG_PROXY_TYPE']) ? (int)$config['TLG_PROXY_TYPE'] : 2;
        switch ($type) {
            case 1:
                return 'http';
            case 3:
                return 'socks5h';
            case 4:
                return 'https';
            case 2:
            default:
                return 'socks5';
        }
    }

    /**
     * Parse "host:port", "http://host:port", "socks5://user:pass@host:port".
     *
     * @param string $address
     * @return array{host:string,port:int,login:string,password:string,scheme:string}
     */
    public static function parseProxyAddress($address)
    {
        $address = trim((string)$address);
        $result = array('host' => '', 'port' => 0, 'login' => '', 'password' => '', 'scheme' => '');
        if ($address === '') {
            return $result;
        }

        $scheme = '';
        if (preg_match('#^([a-z0-9]+)://#i', $address, $m)) {
            $scheme = strtolower($m[1]);
            $address = substr($address, strlen($m[0]));
        }
        $address = rtrim($address, '/');

        $login = '';
        $password = '';
        if (strpos($address, '@') !== false) {
            list($auth, $address) = explode('@', $address, 2);
            if (strpos($auth, ':') !== false) {
                list($login, $password) = explode(':', $auth, 2);
            } else {
                $login = $auth;
            }
            $login = rawurldecode($login);
            $password = rawurldecode($password);
        }

        $port = 0;
        if (preg_match('/^\[(.+)\]:(\d+)$/', $address, $m)) {
            $address = $m[1];
            $port = (int)$m[2];
        } elseif (substr_count($address, ':') === 1) {
            list($address, $port) = explode(':', $address, 2);
            $port = (int)$port;
        }

        $result['host'] = $address;
        $result['port'] = $port;
        $result['login'] = $login;
        $result['password'] = $password;
        $result['scheme'] = $scheme;
        return $result;
    }

    /**
     * Parse a `tg://socks?...` or `tg://proxy?...` deep link.
     *
     * @param string $link
     * @return array{type:string,server:string,port:int,user:string,pass:string,secret:string}
     */
    public static function parseTelegramProxyLink($link)
    {
        $result = array('type' => '', 'server' => '', 'port' => 0, 'user' => '', 'pass' => '', 'secret' => '');
        $link = trim((string)$link);
        if (!preg_match('#^tg://(socks|proxy)\?(.+)$#i', $link, $m)) {
            return $result;
        }
        $result['type'] = strtolower($m[1]);
        parse_str($m[2], $query);
        $result['server'] = isset($query['server']) ? (string)$query['server'] : '';
        $result['port'] = isset($query['port']) ? (int)$query['port'] : 0;
        $result['user'] = isset($query['user']) ? (string)$query['user'] : '';
        $result['pass'] = isset($query['pass']) ? (string)$query['pass'] : '';
        $result['secret'] = isset($query['secret']) ? (string)$query['secret'] : '';
        return $result;
    }

    /**
     * @return array{code:int,message:string,description:string,retry:bool,hint:string}
     */
    private function failure($code, $description, $retry = false)
    {
        $this->lastError = array(
            'code' => (int)$code,
            'message' => (string)$description,
            'description' => (string)$description,
            'retry' => (bool)$retry,
            'hint' => self::errorHint((int)$code, (string)$description),
        );
        return $this->lastError;
    }

    /**
     * Human readable advice for common Bot API / transport errors.
     *
     * @param int    $code
     * @param string $description
     * @return string
     */
    public static function errorHint($code, $description)
    {
        $description = (string)$description;
        if ($code === 401 || stripos($description, 'unauthorized') !== false) {
            return 'Токен бота неверен или отозван. Получите новый у @BotFather и сохраните настройки.';
        }
        if ($code === 409 || stripos($description, 'webhook is active') !== false) {
            return 'Активен webhook: getUpdates недоступен. Снимите webhook или отключите webhook-режим.';
        }
        if ($code === 429 || stripos($description, 'too many requests') !== false) {
            return 'Слишком много запросов. Подождите и повторите, проверьте частоту отправки из сценариев.';
        }
        if ($code === 400 && stripos($description, 'chat not found') !== false) {
            return 'Чат не найден: пользователь не начинал диалог с ботом или chat_id указан неверно.';
        }
        if ($code === 403 || stripos($description, 'blocked') !== false) {
            return 'Бот заблокирован пользователем или не имеет прав в этом чате.';
        }
        if (in_array($code, array(5, 6, 7, 28, 35, 52, 56), true)) {
            return 'Не удалось установить соединение. Проверьте прокси, VPN или локальный Bot API сервер.';
        }
        if ($code === 60) {
            return 'Ошибка проверки TLS-сертификата. Установите актуальные CA или включите «Не проверять сертификат» только для отладки.';
        }
        if (stripos($description, 'timed out') !== false) {
            return 'Превышено время ожидания. Увеличьте таймаут запроса или проверьте канал связи.';
        }
        return '';
    }

    /**
     * @param string $message
     * @return void
     */
    private function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }

    /**
     * Perform a Bot API call.
     *
     * @param string $method
     * @param array  $params
     * @param array  $files Map of parameter name => absolute file path.
     * @return array Decoded API response; on transport failure an `ok=false`
     *               array with `curl_error_code`/`curl_error` is returned.
     */
    public function request($method, array $params = array(), array $files = array())
    {
        $method = trim((string)$method);
        $this->lastError = array();
        $this->lastMeta = array();

        if ($this->token === '') {
            return $this->failure(401, 'Bot token is empty', false);
        }
        if ($method === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $method)) {
            return $this->failure(400, 'Invalid API method: ' . $method, false);
        }

        $url = $this->endpointUrl($method);
        $multipart = !empty($files);
        $payload = $this->prepareParams($params, $multipart);

        $ch = curl_init();
        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(15, max(3, $this->timeout)),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => $this->sslVerify,
            CURLOPT_SSL_VERIFYHOST => $this->sslVerify ? 2 : 0,
            CURLOPT_HTTPHEADER => array('Expect:'),
        );

        if (defined('CURL_IPRESOLVE_V4')) {
            if ($this->ipVersion === 'ipv4') {
                $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            } elseif ($this->ipVersion === 'ipv6') {
                $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V6;
            }
        }

        if ($multipart) {
            foreach ($files as $field => $path) {
                if ($path !== '' && is_file($path)) {
                    $payload[$field] = new CURLFile($path);
                }
            }
            $options[CURLOPT_POSTFIELDS] = $payload;
        } else {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        $proxy = $this->proxyCurlOptions();
        if (!empty($proxy)) {
            $options = $options + $proxy;
        }

        curl_setopt_array($ch, $options);

        $started = microtime(true);
        $raw = curl_exec($ch);
        $duration = round((microtime(true) - $started) * 1000);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->lastMeta = array(
            'method' => $method,
            'url' => preg_replace('#/bot[^/]+/#', '/bot***/', $url),
            'http_code' => $httpCode,
            'duration_ms' => $duration,
            'transport' => $this->transport,
            'multipart' => $multipart,
        );

        if ($curlErrno !== 0 || $raw === false) {
            $this->log('Telegram API curl error ' . $curlErrno . ': ' . $curlError . ' (' . $method . ')');
            $this->failure($curlErrno, $curlError, true);
            return array(
                'ok' => false,
                'curl_error_code' => $curlErrno,
                'curl_error' => $curlError,
                'error_code' => 0,
                'description' => $curlError,
                'hint' => $this->lastError['hint'],
                'retry' => true,
                'meta' => $this->lastMeta,
            );
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->log('Telegram API invalid JSON: ' . substr((string)$raw, 0, 500));
            $this->failure($httpCode > 0 ? $httpCode : 502, 'Invalid response from Telegram API');
            return array(
                'ok' => false,
                'error_code' => $httpCode,
                'description' => 'Invalid response from Telegram API',
                'raw' => substr((string)$raw, 0, 2000),
                'hint' => 'Ответ не похож на JSON. Проверьте, что базовый URL указывает на Bot API, а не на прокси-заглушку.',
                'meta' => $this->lastMeta,
            );
        }

        if (empty($decoded['ok'])) {
            $errorCode = isset($decoded['error_code']) ? (int)$decoded['error_code'] : (int)$httpCode;
            $description = isset($decoded['description']) ? (string)$decoded['description'] : 'Unknown Telegram API error';
            $this->failure($errorCode, $description, $errorCode >= 500);
            $decoded['hint'] = $this->lastError['hint'];
            $decoded['retry'] = $this->lastError['retry'];
            $decoded['meta'] = $this->lastMeta;
            $this->log('Telegram API error ' . $errorCode . ': ' . $description . ' (' . $method . ')');
            return $decoded;
        }

        $decoded['meta'] = $this->lastMeta;
        return $decoded;
    }

    /**
     * Download a file from the Bot API file endpoint.
     *
     * @param string $filePath   Value of `file_path` from getFile.
     * @param string $destination Absolute local path.
     * @param int    $maxBytes   Safety limit, 0 = unlimited.
     * @return array{ok:bool,path:string,size:int,error:string}
     */
    public function downloadFile($filePath, $destination, $maxBytes = 0)
    {
        $filePath = ltrim((string)$filePath, '/');
        if ($this->token === '' || $filePath === '') {
            return array('ok' => false, 'path' => '', 'size' => 0, 'error' => 'Empty file path or token');
        }
        $dir = dirname($destination);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return array('ok' => false, 'path' => '', 'size' => 0, 'error' => 'Cannot create directory ' . $dir);
        }
        $tmp = $destination . '.part';
        $fp = @fopen($tmp, 'wb');
        if (!$fp) {
            return array('ok' => false, 'path' => '', 'size' => 0, 'error' => 'Cannot open ' . $tmp);
        }

        $url = $this->base . '/file/bot' . $this->token . '/' . $filePath;
        $ch = curl_init();
        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(30, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => $this->sslVerify,
            CURLOPT_SSL_VERIFYHOST => $this->sslVerify ? 2 : 0,
            CURLOPT_NOSIGNAL => true,
        );
        if ($maxBytes > 0) {
            $options[CURLOPT_MAXFILESIZE] = (int)$maxBytes;
        }
        if ($this->ipVersion === 'ipv4' && defined('CURL_IPRESOLVE_V4')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        } elseif ($this->ipVersion === 'ipv6' && defined('CURL_IPRESOLVE_V6')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V6;
        }
        $proxy = $this->proxyCurlOptions();
        if (!empty($proxy)) {
            $options = $options + $proxy;
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $curlErrno !== 0 || $httpCode >= 400) {
            @unlink($tmp);
            $error = $curlErrno !== 0 ? $curlError : ('HTTP ' . $httpCode);
            $this->log('Telegram file download failed: ' . $error . ' (' . $filePath . ')');
            return array('ok' => false, 'path' => '', 'size' => 0, 'error' => $error);
        }

        if (!@rename($tmp, $destination)) {
            @unlink($tmp);
            return array('ok' => false, 'path' => '', 'size' => 0, 'error' => 'Cannot move downloaded file');
        }

        return array('ok' => true, 'path' => $destination, 'size' => (int)@filesize($destination), 'error' => '');
    }

    /**
     * @param string $method
     * @return string
     */
    private function endpointUrl($method)
    {
        return $this->base . '/bot' . $this->token . '/' . $method;
    }

    /**
     * Serialize request parameters for JSON or multipart bodies.
     *
     * @param array $params
     * @param bool  $multipart
     * @return array
     */
    private function prepareParams(array $params, $multipart)
    {
        $result = array();
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $result[$key] = $multipart ? ($value ? 'true' : 'false') : $value;
                continue;
            }
            if (is_array($value)) {
                if (empty($value)) {
                    continue;
                }
                // reply_markup, entities, allowed_updates, media groups and other
                // structured values are JSON objects/arrays. A JSON body can carry
                // them as-is; a multipart body needs them serialized.
                $result[$key] = $multipart
                    ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : $value;
                continue;
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * @return array
     */
    private function proxyCurlOptions()
    {
        if (!in_array($this->transport, array('http', 'https', 'socks5', 'socks5h'), true)) {
            return array();
        }
        if ($this->proxyHost === '') {
            return array();
        }

        $options = array();
        switch ($this->transport) {
            case 'http':
                $options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
                $options[CURLOPT_PROXY] = $this->proxyHost . ':' . ($this->proxyPort > 0 ? $this->proxyPort : 8080);
                break;
            case 'https':
                if (defined('CURLPROXY_HTTPS')) {
                    $options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTPS;
                } else {
                    $options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
                }
                $options[CURLOPT_PROXY] = 'https://' . $this->proxyHost . ':' . ($this->proxyPort > 0 ? $this->proxyPort : 443);
                break;
            case 'socks5':
                $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
                $options[CURLOPT_PROXY] = $this->proxyHost . ':' . ($this->proxyPort > 0 ? $this->proxyPort : 1080);
                break;
            case 'socks5h':
                if (defined('CURLPROXY_SOCKS5_HOSTNAME')) {
                    $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
                } else {
                    $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
                }
                $options[CURLOPT_PROXY] = $this->proxyHost . ':' . ($this->proxyPort > 0 ? $this->proxyPort : 1080);
                break;
        }

        if ($this->proxyLogin !== '' || $this->proxyPassword !== '') {
            $options[CURLOPT_PROXYUSERPWD] = $this->proxyLogin . ':' . $this->proxyPassword;
        }
        return $options;
    }

    /**
     * Human readable transport label (used by the settings page).
     *
     * @return string
     */
    public function describeTransport()
    {
        $names = array(
            'direct' => 'Напрямую',
            'http' => 'HTTP-прокси',
            'https' => 'HTTPS-прокси',
            'socks5' => 'SOCKS5',
            'socks5h' => 'SOCKS5 (DNS через прокси)',
            'transparent' => 'Прозрачный прокси / VPN',
            'local_api' => 'Локальный Bot API (MTProto)',
        );
        $label = isset($names[$this->transport]) ? $names[$this->transport] : $this->transport;
        if (in_array($this->transport, array('http', 'https', 'socks5', 'socks5h'), true) && $this->proxyHost !== '') {
            $label .= ' · ' . $this->proxyHost . ':' . $this->proxyPort;
        }
        if ($this->transport === 'local_api' && $this->base !== self::DEFAULT_BASE) {
            $label .= ' · ' . $this->base;
        }
        return $label;
    }

    // -------------------------------------------------------------------------
    // Thin, typed wrappers for the Bot API methods used by the module.
    // -------------------------------------------------------------------------

    /** @return array */
    public function getMe()
    {
        return $this->request('getMe');
    }

    /** @return array */
    public function logOut()
    {
        return $this->request('logOut');
    }

    /** @return array */
    public function getUpdates($offset = 0, $limit = 100, $timeout = 0, array $allowedUpdates = array())
    {
        $params = array(
            'offset' => (int)$offset,
            'limit' => max(1, min(100, (int)$limit)),
            'timeout' => max(0, (int)$timeout),
        );
        if (!empty($allowedUpdates)) {
            $params['allowed_updates'] = array_values($allowedUpdates);
        }
        // Long polling keeps the connection open for `timeout` seconds; the curl
        // timeout must be larger than that, otherwise every cycle logs a timeout.
        $previousTimeout = $this->timeout;
        $this->timeout = max($previousTimeout, (int)$timeout + 15);
        try {
            return $this->request('getUpdates', $params);
        } finally {
            $this->timeout = $previousTimeout;
        }
    }

    /** @return array */
    public function setWebhook($url, array $options = array())
    {
        $params = array('url' => (string)$url);
        $files = array();
        foreach (array('secret_token', 'max_connections', 'allowed_updates', 'drop_pending_updates', 'ip_address') as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== '' && $options[$key] !== null) {
                $params[$key] = $options[$key];
            }
        }
        if (!empty($options['certificate']) && is_file((string)$options['certificate'])) {
            $files['certificate'] = (string)$options['certificate'];
        }
        return $this->request('setWebhook', $params, $files);
    }

    /** @return array */
    public function deleteWebhook($dropPendingUpdates = false)
    {
        return $this->request('deleteWebhook', array('drop_pending_updates' => (bool)$dropPendingUpdates));
    }

    /** @return array */
    public function getWebhookInfo()
    {
        return $this->request('getWebhookInfo');
    }

    /** @return array */
    public function getFile($fileId)
    {
        return $this->request('getFile', array('file_id' => (string)$fileId));
    }

    /** @return array */
    public function getChat($chatId)
    {
        return $this->request('getChat', array('chat_id' => $chatId));
    }

    /** @return array */
    public function getChatMember($chatId, $userId)
    {
        return $this->request('getChatMember', array('chat_id' => $chatId, 'user_id' => $userId));
    }

    /** @return array */
    public function getChatMemberCount($chatId)
    {
        return $this->request('getChatMemberCount', array('chat_id' => $chatId));
    }

    /** @return array */
    public function getChatAdministrators($chatId)
    {
        return $this->request('getChatAdministrators', array('chat_id' => $chatId));
    }

    /** @return array */
    public function getUserProfilePhotos($userId, $offset = 0, $limit = 1)
    {
        return $this->request('getUserProfilePhotos', array(
            'user_id' => $userId,
            'offset' => (int)$offset,
            'limit' => (int)$limit,
        ));
    }

    /** @return array */
    public function sendMessage($chatId, $text, array $options = array())
    {
        $options = array_merge(array('chat_id' => $chatId, 'text' => $text), $options);
        return $this->request('sendMessage', $options);
    }

    /** @return array */
    public function sendPhoto($chatId, $photo, array $options = array())
    {
        return $this->sendUpload('sendPhoto', 'photo', $chatId, $photo, $options);
    }

    /** @return array */
    public function sendAudio($chatId, $audio, array $options = array())
    {
        return $this->sendUpload('sendAudio', 'audio', $chatId, $audio, $options);
    }

    /** @return array */
    public function sendDocument($chatId, $document, array $options = array())
    {
        return $this->sendUpload('sendDocument', 'document', $chatId, $document, $options);
    }

    /** @return array */
    public function sendVideo($chatId, $video, array $options = array())
    {
        return $this->sendUpload('sendVideo', 'video', $chatId, $video, $options);
    }

    /** @return array */
    public function sendAnimation($chatId, $animation, array $options = array())
    {
        return $this->sendUpload('sendAnimation', 'animation', $chatId, $animation, $options);
    }

    /** @return array */
    public function sendVoice($chatId, $voice, array $options = array())
    {
        return $this->sendUpload('sendVoice', 'voice', $chatId, $voice, $options);
    }

    /** @return array */
    public function sendVideoNote($chatId, $videoNote, array $options = array())
    {
        return $this->sendUpload('sendVideoNote', 'video_note', $chatId, $videoNote, $options);
    }

    /** @return array */
    public function sendSticker($chatId, $sticker, array $options = array())
    {
        return $this->sendUpload('sendSticker', 'sticker', $chatId, $sticker, $options);
    }

    /** @return array */
    public function sendMediaGroup($chatId, array $media, array $options = array())
    {
        $params = array_merge(array('chat_id' => $chatId, 'media' => $media), $options);
        return $this->request('sendMediaGroup', $params);
    }

    /** @return array */
    public function sendLocation($chatId, $latitude, $longitude, array $options = array())
    {
        $params = array_merge(array(
            'chat_id' => $chatId,
            'latitude' => (float)$latitude,
            'longitude' => (float)$longitude,
        ), $options);
        return $this->request('sendLocation', $params);
    }

    /** @return array */
    public function sendVenue($chatId, $latitude, $longitude, $title, $address, array $options = array())
    {
        $params = array_merge(array(
            'chat_id' => $chatId,
            'latitude' => (float)$latitude,
            'longitude' => (float)$longitude,
            'title' => (string)$title,
            'address' => (string)$address,
        ), $options);
        return $this->request('sendVenue', $params);
    }

    /** @return array */
    public function sendContact($chatId, $phoneNumber, $firstName, array $options = array())
    {
        $params = array_merge(array(
            'chat_id' => $chatId,
            'phone_number' => (string)$phoneNumber,
            'first_name' => (string)$firstName,
        ), $options);
        return $this->request('sendContact', $params);
    }

    /** @return array */
    public function sendPoll($chatId, $question, array $optionsList, array $options = array())
    {
        $params = array_merge(array(
            'chat_id' => $chatId,
            'question' => (string)$question,
            'options' => array_values($optionsList),
        ), $options);
        return $this->request('sendPoll', $params);
    }

    /** @return array */
    public function sendDice($chatId, $emoji = '', array $options = array())
    {
        $params = array_merge(array('chat_id' => $chatId), $options);
        if ($emoji !== '') {
            $params['emoji'] = (string)$emoji;
        }
        return $this->request('sendDice', $params);
    }

    /** @return array */
    public function sendChatAction($chatId, $action = 'typing', $messageThreadId = 0)
    {
        $params = array('chat_id' => $chatId, 'action' => (string)$action);
        if ($messageThreadId > 0) {
            $params['message_thread_id'] = (int)$messageThreadId;
        }
        return $this->request('sendChatAction', $params);
    }

    /** @return array */
    public function editMessageText($chatId, $messageId, $text, array $options = array())
    {
        $params = array_merge(array(
            'chat_id' => $chatId,
            'message_id' => (int)$messageId,
            'text' => (string)$text,
        ), $options);
        return $this->request('editMessageText', $params);
    }

    /** @return array */
    public function editMessageCaption($chatId, $messageId, $caption, array $options = array())
    {
        $params = array_merge(array(
            'chat_id' => $chatId,
            'message_id' => (int)$messageId,
            'caption' => (string)$caption,
        ), $options);
        return $this->request('editMessageCaption', $params);
    }

    /** @return array */
    public function editMessageReplyMarkup($chatId, $messageId, $replyMarkup = null)
    {
        return $this->request('editMessageReplyMarkup', array(
            'chat_id' => $chatId,
            'message_id' => (int)$messageId,
            'reply_markup' => $replyMarkup,
        ));
    }

    /** @return array */
    public function deleteMessage($chatId, $messageId)
    {
        return $this->request('deleteMessage', array(
            'chat_id' => $chatId,
            'message_id' => (int)$messageId,
        ));
    }

    /** @return array */
    public function deleteMessages(array $messageIds)
    {
        return $this->request('deleteMessages', array('message_ids' => array_values($messageIds)));
    }

    /** @return array */
    public function forwardMessage($chatId, $fromChatId, $messageId, array $options = array())
    {
        $params = array_merge(array(
            'chat_id' => $chatId,
            'from_chat_id' => $fromChatId,
            'message_id' => (int)$messageId,
        ), $options);
        return $this->request('forwardMessage', $params);
    }

    /** @return array */
    public function copyMessage($chatId, $fromChatId, $messageId, array $options = array())
    {
        $params = array_merge(array(
            'chat_id' => $chatId,
            'from_chat_id' => $fromChatId,
            'message_id' => (int)$messageId,
        ), $options);
        return $this->request('copyMessage', $params);
    }

    /** @return array */
    public function answerCallbackQuery($callbackQueryId, $text = '', array $options = array())
    {
        $params = array_merge(array('callback_query_id' => (string)$callbackQueryId), $options);
        if ($text !== '') {
            $params['text'] = (string)$text;
        }
        return $this->request('answerCallbackQuery', $params);
    }

    /** @return array */
    public function answerInlineQuery($inlineQueryId, array $results = array(), array $options = array())
    {
        $params = array_merge(array(
            'inline_query_id' => (string)$inlineQueryId,
            'results' => array_values($results),
        ), $options);
        return $this->request('answerInlineQuery', $params);
    }

    /** @return array */
    public function setMyCommands(array $commands, array $options = array())
    {
        $params = array_merge(array('commands' => array_values($commands)), $options);
        return $this->request('setMyCommands', $params);
    }

    /** @return array */
    public function getMyCommands(array $options = array())
    {
        return $this->request('getMyCommands', $options);
    }

    /** @return array */
    public function deleteMyCommands(array $options = array())
    {
        return $this->request('deleteMyCommands', $options);
    }

    /** @return array */
    public function setChatMenuButton(array $options = array())
    {
        return $this->request('setChatMenuButton', $options);
    }

    /** @return array */
    public function getChatMenuButton(array $options = array())
    {
        return $this->request('getChatMenuButton', $options);
    }

    /** @return array */
    public function setMessageReaction($chatId, $messageId, array $reaction = array())
    {
        return $this->request('setMessageReaction', array(
            'chat_id' => $chatId,
            'message_id' => (int)$messageId,
            'reaction' => array_values($reaction),
        ));
    }

    /** @return array */
    public function pinChatMessage($chatId, $messageId, $disableNotification = false)
    {
        return $this->request('pinChatMessage', array(
            'chat_id' => $chatId,
            'message_id' => (int)$messageId,
            'disable_notification' => (bool)$disableNotification,
        ));
    }

    /** @return array */
    public function unpinChatMessage($chatId, $messageId = 0)
    {
        $params = array('chat_id' => $chatId);
        if ($messageId > 0) {
            $params['message_id'] = (int)$messageId;
        }
        return $this->request('unpinChatMessage', $params);
    }

    /** @return array */
    public function createForumTopic($chatId, $name, array $options = array())
    {
        $params = array_merge(array('chat_id' => $chatId, 'name' => (string)$name), $options);
        return $this->request('createForumTopic', $params);
    }

    /** @return array */
    public function closeForumTopic($chatId, $messageThreadId)
    {
        return $this->request('closeForumTopic', array('chat_id' => $chatId, 'message_thread_id' => (int)$messageThreadId));
    }

    /** @return array */
    public function reopenForumTopic($chatId, $messageThreadId)
    {
        return $this->request('reopenForumTopic', array('chat_id' => $chatId, 'message_thread_id' => (int)$messageThreadId));
    }

    /** @return array */
    public function leaveChat($chatId)
    {
        return $this->request('leaveChat', array('chat_id' => $chatId));
    }

    /** @return array */
    public function banChatMember($chatId, $userId, $revokeMessages = false)
    {
        return $this->request('banChatMember', array(
            'chat_id' => $chatId,
            'user_id' => $userId,
            'revoke_messages' => (bool)$revokeMessages,
        ));
    }

    /** @return array */
    public function unbanChatMember($chatId, $userId, $onlyIfBanned = true)
    {
        return $this->request('unbanChatMember', array(
            'chat_id' => $chatId,
            'user_id' => $userId,
            'only_if_banned' => (bool)$onlyIfBanned,
        ));
    }

    /** @return array */
    public function approveChatJoinRequest($chatId, $userId)
    {
        return $this->request('approveChatJoinRequest', array('chat_id' => $chatId, 'user_id' => $userId));
    }

    /** @return array */
    public function declineChatJoinRequest($chatId, $userId)
    {
        return $this->request('declineChatJoinRequest', array('chat_id' => $chatId, 'user_id' => $userId));
    }

    /** @return array */
    public function setChatTitle($chatId, $title)
    {
        return $this->request('setChatTitle', array('chat_id' => $chatId, 'title' => (string)$title));
    }

    /** @return array */
    public function setChatDescription($chatId, $description)
    {
        return $this->request('setChatDescription', array('chat_id' => $chatId, 'description' => (string)$description));
    }

    /** @return array */
    public function exportChatInviteLink($chatId)
    {
        return $this->request('exportChatInviteLink', array('chat_id' => $chatId));
    }

    /** @return array */
    public function getStickerSet($name)
    {
        return $this->request('getStickerSet', array('name' => (string)$name));
    }

    /**
     * Upload helper: a local file is sent as multipart, a file_id/URL as JSON.
     *
     * @param string $method
     * @param string $field
     * @param mixed  $chatId
     * @param string $value
     * @param array  $options
     * @return array
     */
    private function sendUpload($method, $field, $chatId, $value, array $options)
    {
        $params = array_merge(array('chat_id' => $chatId), $options);
        $files = array();
        if (is_string($value) && $value !== '' && is_file($value)) {
            $files[$field] = $value;
        } else {
            $params[$field] = $value;
        }
        return $this->request($method, $params, $files);
    }
}
