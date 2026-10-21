<?php
/**
 * Telegram polling cycle.
 *
 * The module persists the getUpdates offset in its config, so a restart does
 * not replay already processed updates.
 */
chdir(dirname(__FILE__) . '/../');

include_once('./config.php');
include_once('./lib/loader.php');
include_once('./lib/threads.php');

set_time_limit(0);

$db = new mysql(DB_HOST, '', DB_USER, DB_PASSWORD, DB_NAME);

include_once('./load_settings.php');
include_once(DIR_MODULES . 'control_modules/control_modules.class.php');
$ctl = new control_modules();
include_once(DIR_MODULES . 'telegram/telegram.class.php');

$cycle_name = str_replace('.php', '', basename(__FILE__));

echo date('H:i:s') . ' Running ' . basename(__FILE__) . PHP_EOL;

$telegram_module = new telegram();
setGlobal($cycle_name . 'Run', time(), 1);

echo date('H:i:s') . ' Init module' . PHP_EOL;
$telegram_module->init();

$latest_check = time();
$exit_code = 0;

while (1) {
    if ($latest_check + 30 < time()) {
        $latest_check = time();
        setGlobal($cycle_name . 'Run', time(), 1);
    }

    if (function_exists('gg') && gg('cycle_telegramControl') == 'restart') {
        setGlobal('cycle_telegramControl', '');
        echo date('H:i:s') . ' Restart requested, exiting' . PHP_EOL;
        break;
    }

    try {
        $result = $telegram_module->processCycle();
    } catch (Throwable $e) {
        DebMes('Telegram cycle error: ' . $e->getMessage(), 'telegram');
        echo date('Y-m-d H:i:s') . ' Error: ' . $e->getMessage() . PHP_EOL;
        $result = 0;
        sleep(3);
    }

    if ($result == -1) {
        echo date('H:i:s') . ' Invalid token, stopping cycle' . PHP_EOL;
        $exit_code = 1;
        break;
    }

    if (file_exists('./reboot') || isset($_GET['onetime'])) {
        break;
    }
    sleep(1);
}

$db->Disconnect();
exit($exit_code);
