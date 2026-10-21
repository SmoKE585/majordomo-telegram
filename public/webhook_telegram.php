<?php
/**
 * Telegram webhook entry point.
 *
 * Put this file in the public document root (together with admin.php) and use
 * its URL as the webhook endpoint. Telegram requires HTTPS with a valid
 * certificate. If a secret token is configured in the module, the request must
 * carry the matching X-Telegram-Bot-Api-Secret-Token header.
 */

require dirname(__DIR__) . '/bootstrap.php';

include_once('./config.php');
include_once('./lib/loader.php');

$db = new mysql(DB_HOST, '', DB_USER, DB_PASSWORD, DB_NAME);

include_once('./load_settings.php');
include_once(DIR_MODULES . 'control_modules/control_modules.class.php');
$ctl = new control_modules();
include_once(DIR_MODULES . 'telegram/telegram.class.php');

try {
    $telegram_module = new telegram();
    $telegram_module->handleWebhook();
} catch (Throwable $e) {
    DebMes('Telegram webhook fatal error: ' . $e->getMessage(), 'telegram');
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"ok":false}';
    }
}

$db->Disconnect();
