<?php
/**
 * Command card: trigger, access rules, keyboard visibility and PHP handler.
 */

$id = isset($id) ? (int)$id : 0;
$tableName = 'tlg_cmd';
$record = $id > 0 ? SQLSelectOne('SELECT * FROM ' . $tableName . ' WHERE ID=' . $id) : array();
if (!is_array($record)) {
    $record = array();
}
if (empty($record['ID'])) {
    $id = 0;
}
$isNew = empty($record['ID']);
$out['ID'] = $isNew ? '' : (int)$record['ID'];
$out['IS_NEW'] = $isNew ? 1 : 0;

$codeEditorSettings = array(
    'AUTOCLOSEQUOTES' => 1,
    'WRAPLINES' => 0,
    'SHOWERROR' => 0,
    'UPTOLINE' => 0,
    'THEME' => 'auto',
    'MIXLINE' => 14,
    'SHOWLINE' => 0,
    'TURNONSETTINGS' => 0,
);
foreach ($codeEditorSettings as $setting => $default) {
    $constantName = 'SETTINGS_CODEEDITOR_' . $setting;
    $value = defined($constantName) ? constant($constantName) : null;
    $out[$constantName] = ($value === null || $value === '') ? $default : $value;
}
$out['CODE_EDITOR_KEY'] = 'telegram_cmd_' . ($isNew ? 'new' : (int)$record['ID']);

if ($this->mode === 'update') {
    $errors = array();
    $title = trim((string)gr('title'));
    $title = ltrim($title, '/');
    $aliases = trim((string)gr('aliases'));
    $description = trim((string)gr('description'));
    $code = (string)gr('code');
    $access = (int)gr('access');
    $showMode = (int)gr('show_mode');
    $priority = (int)gr('priority');
    $parseMode = trim((string)gr('parse_mode'));
    $linkedObject = trim((string)gr('linked_object_new'));
    $linkedProperty = trim((string)gr('linked_property_new'));
    $condition = (int)gr('condition_new');
    $conditionValue = trim((string)gr('condition_value_new'));

    if ($title === '') {
        $errors[] = 'Укажите название команды — именно его набирает пользователь.';
    }
    if (mb_strlen($title, 'UTF-8') > 64) {
        $errors[] = 'Название команды не должно быть длиннее 64 символов.';
    }
    if (!in_array($access, array(0, 1, 2, 3), true)) {
        $access = 0;
    }
    if (!in_array($showMode, array(1, 2, 3), true)) {
        $showMode = 1;
    }
    if (!in_array($parseMode, array('HTML', 'MarkdownV2', 'none'), true)) {
        $parseMode = 'HTML';
    }
    if (!in_array($condition, array(1, 2, 3, 4), true)) {
        $condition = 1;
    }
    $aliases = $this->normalizeAliases($aliases);

    if (trim($code) !== '') {
        if (!function_exists('code_syntax_error_details')) {
            require_once(ROOT . 'lib/php_check.class.php');
        }
        $errorDetails = function_exists('code_syntax_error_details') ? code_syntax_error_details($code) : false;
        if (!$errorDetails) {
            $legacyError = function_exists('php_syntax_error') ? php_syntax_error($code) : false;
            if ($legacyError) {
                $errorDetails = array(
                    'line' => 0,
                    'message' => $legacyError,
                    'full' => $legacyError,
                );
            }
        }
        if ($errorDetails) {
            $out['ERR_CODE'] = 1;
            $out['ERR_LINE'] = (int)$errorDetails['line'];
            $out['ERR_FULL'] = md_tlg_h(isset($errorDetails['full']) ? $errorDetails['full'] : $errorDetails['message']);
            $out['ERR_MESSAGE'] = md_tlg_h($errorDetails['message']);
            $out['ERR_OLD_CODE_B64'] = base64_encode(isset($record['CODE']) ? (string)$record['CODE'] : '');
            $errors[] = 'В коде команды есть синтаксическая ошибка.';
        }
    }

    if (empty($errors)) {
        $record = array_merge($record, array(
            'TITLE' => $title,
            'ALIASES' => $aliases,
            'DESCRIPTION' => $description,
            'CODE' => $code,
            'ACCESS' => $access,
            'SHOW_MODE' => $showMode,
            'PRIORITY' => $priority,
            'PARSE_MODE' => $parseMode,
            'LINKED_OBJECT' => $linkedObject,
            'LINKED_PROPERTY' => $linkedProperty,
            'CONDITION' => $condition,
            'CONDITION_VALUE' => $conditionValue,
        ));
        if ($isNew) {
            $record['RUNS'] = 0;
            $record['ID'] = SQLInsert($tableName, $record);
            $id = (int)$record['ID'];
            $out['ID'] = $id;
        } else {
            SQLUpdate($tableName, $record);
        }
        $this->saveCommandAccess($id, gr('users_id'));
        $this->redirect('?tab=commands&ok_msg=' . urlencode('Команда сохранена'));
    }

    $record = array_merge($record, array(
        'TITLE' => $title,
        'ALIASES' => $aliases,
        'DESCRIPTION' => $description,
        'CODE' => $code,
        'ACCESS' => $access,
        'SHOW_MODE' => $showMode,
        'PRIORITY' => $priority,
        'PARSE_MODE' => $parseMode,
        'LINKED_OBJECT' => $linkedObject,
        'LINKED_PROPERTY' => $linkedProperty,
        'CONDITION' => $condition,
        'CONDITION_VALUE' => $conditionValue,
    ));
    $out['SAVE_ERRORS'] = $errors;
}

$record += array(
    'ID' => '',
    'TITLE' => '',
    'ALIASES' => '',
    'DESCRIPTION' => '',
    'CODE' => '',
    'ACCESS' => 0,
    'SHOW_MODE' => 1,
    'PRIORITY' => 1,
    'PARSE_MODE' => 'HTML',
    'LINKED_OBJECT' => '',
    'LINKED_PROPERTY' => '',
    'CONDITION' => 1,
    'CONDITION_VALUE' => '',
    'RUNS' => 0,
    'LAST_RUN' => '',
);

$selectedUsers = array();
$accessRows = $id > 0 ? SQLSelect('SELECT USER_ID FROM tlg_user_cmd WHERE CMD_ID=' . (int)$id) : array();
foreach ((array)$accessRows as $row) {
    $selectedUsers[(int)$row['USER_ID']] = true;
}
if ($this->mode === 'update' && !empty($out['SAVE_ERRORS'])) {
    $submittedUsers = $this->submittedUserIds(gr('users_id'));
    $selectedUsers = array();
    foreach ($submittedUsers as $userId) {
        $selectedUsers[$userId] = true;
    }
}

$users = SQLSelect('SELECT ID, NAME, USERNAME, ADMIN FROM tlg_user ORDER BY ADMIN DESC, NAME ASC');
$out['LIST_ACCESS'] = array();
$out['ACCESS_ADMIN_COUNT'] = 0;
$out['ACCESS_USER_COUNT'] = 0;
foreach ((array)$users as $user) {
    $isAdmin = (int)$user['ADMIN'] === 1;
    $checked = isset($selectedUsers[(int)$user['ID']]);
    if ($isAdmin) {
        $out['ACCESS_ADMIN_COUNT']++;
    } else {
        $out['ACCESS_USER_COUNT']++;
    }
    $label = trim((string)$user['NAME']);
    if ($label === '') {
        $label = $user['USERNAME'] !== '' ? '@' . $user['USERNAME'] : (string)$user['ID'];
    }
    $out['LIST_ACCESS'][] = array(
        'ID' => (int)$user['ID'],
        'NAME' => md_tlg_h($label),
        'USERNAME' => $user['USERNAME'] !== '' ? md_tlg_h('@' . $user['USERNAME']) : '',
        'IS_ADMIN' => $isAdmin ? 1 : 0,
        'CHECKED' => $checked ? 1 : 0,
    );
}

$examples = array();
foreach (md_tlg_command_examples() as $key => $example) {
    $examples[] = array(
        'KEY' => $key,
        'TITLE' => md_tlg_h($example['title']),
        'DESCRIPTION' => md_tlg_h($example['description']),
    );
}
$out['COMMAND_EXAMPLES'] = $examples;

$out['TITLE'] = md_tlg_h($record['TITLE']);
$out['TITLE_HEADING'] = $record['TITLE'] !== '' ? md_tlg_h($record['TITLE']) : 'Новая команда';
$out['ALIASES'] = md_tlg_h($record['ALIASES']);
$out['DESCRIPTION'] = md_tlg_h($record['DESCRIPTION']);
$out['CODE'] = md_tlg_h($record['CODE']);
$out['ACCESS'] = (int)$record['ACCESS'];
$out['SHOW_MODE'] = (int)$record['SHOW_MODE'];
$out['PRIORITY'] = (int)$record['PRIORITY'];
$out['PARSE_MODE'] = md_tlg_h($record['PARSE_MODE']);
$out['LINKED_OBJECT'] = md_tlg_h($record['LINKED_OBJECT']);
$out['LINKED_PROPERTY'] = md_tlg_h($record['LINKED_PROPERTY']);
$out['CONDITION'] = (int)$record['CONDITION'];
$out['CONDITION_VALUE'] = md_tlg_h($record['CONDITION_VALUE']);
$out['RUNS'] = (int)$record['RUNS'];
$out['LAST_RUN_HUMAN'] = md_tlg_relative_time($record['LAST_RUN']);
$out['HAS_CODE'] = trim((string)$record['CODE']) !== '' ? 1 : 0;
$out['EXPORT_URL'] = $id > 0 ? '?view_mode=export_command&id=' . $id : '';

$out['ACCESS_ADMIN_SELECTED'] = in_array((int)$record['ACCESS'], array(1, 2), true) ? 1 : 0;
$out['ACTIVE_TAB'] = 'commands';
$out['PAGE_MODE'] = 'cmd';
$out['OK_MSG'] = md_tlg_h(gr('ok_msg'));
