<?php
/**
 * Event card: update type, on/off switch and PHP handler.
 */

$id = isset($id) ? (int)$id : 0;
$tableName = 'tlg_event';
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
    'MIXLINE' => 16,
    'SHOWLINE' => 0,
    'TURNONSETTINGS' => 0,
);
foreach ($codeEditorSettings as $setting => $default) {
    $constantName = 'SETTINGS_CODEEDITOR_' . $setting;
    $value = defined($constantName) ? constant($constantName) : null;
    $out[$constantName] = ($value === null || $value === '') ? $default : $value;
}
$out['CODE_EDITOR_KEY'] = 'telegram_event_' . ($isNew ? 'new' : (int)$record['ID']);

if ($this->mode === 'update') {
    $errors = array();
    $title = trim((string)gr('title'));
    $description = trim((string)gr('description'));
    $typeEvent = (int)gr('type_event');
    $enable = (int)gr('enable') ? 1 : 0;
    $code = (string)gr('code');

    if ($title === '') {
        $errors[] = 'Укажите название события.';
    }
    $eventTypes = md_tlg_event_types();
    if (!isset($eventTypes[$typeEvent])) {
        $typeEvent = 1;
    }
    if (trim($code) !== '') {
        if (!function_exists('code_syntax_error_details')) {
            require_once(ROOT . 'lib/php_check.class.php');
        }
        $errorDetails = function_exists('code_syntax_error_details') ? code_syntax_error_details($code) : false;
        if (!$errorDetails) {
            $legacyError = function_exists('php_syntax_error') ? php_syntax_error($code) : false;
            if ($legacyError) {
                $errorDetails = array('line' => 0, 'message' => $legacyError, 'full' => $legacyError);
            }
        }
        if ($errorDetails) {
            $out['ERR_CODE'] = 1;
            $out['ERR_LINE'] = (int)$errorDetails['line'];
            $out['ERR_FULL'] = md_tlg_h(isset($errorDetails['full']) ? $errorDetails['full'] : $errorDetails['message']);
            $out['ERR_MESSAGE'] = md_tlg_h($errorDetails['message']);
            $out['ERR_OLD_CODE_B64'] = base64_encode(isset($record['CODE']) ? (string)$record['CODE'] : '');
            $errors[] = 'В коде обработчика есть синтаксическая ошибка.';
        }
    }

    if (empty($errors)) {
        $record = array_merge($record, array(
            'TITLE' => $title,
            'DESCRIPTION' => $description,
            'TYPE_EVENT' => $typeEvent,
            'ENABLE' => $enable,
            'CODE' => $code,
        ));
        if ($isNew) {
            $record['RUNS'] = 0;
            $record['ID'] = SQLInsert($tableName, $record);
            $id = (int)$record['ID'];
            $out['ID'] = $id;
        } else {
            SQLUpdate($tableName, $record);
        }
        $this->redirect('?tab=events&ok_msg=' . urlencode('Событие сохранено'));
    }

    $record = array_merge($record, array(
        'TITLE' => $title,
        'DESCRIPTION' => $description,
        'TYPE_EVENT' => $typeEvent,
        'ENABLE' => $enable,
        'CODE' => $code,
    ));
    $out['SAVE_ERRORS'] = $errors;
}

$record += array(
    'ID' => '',
    'TITLE' => '',
    'DESCRIPTION' => '',
    'TYPE_EVENT' => 1,
    'ENABLE' => 0,
    'CODE' => '',
    'RUNS' => 0,
    'LAST_RUN' => '',
);

$out['TYPE_OPTIONS'] = array();
foreach (md_tlg_event_types() as $value => $meta) {
    $out['TYPE_OPTIONS'][] = array(
        'VALUE' => $value,
        'LABEL' => md_tlg_h($meta['label']),
        'SHORT' => md_tlg_h($meta['short']),
        'ICON' => $meta['icon'],
        'SELECTED' => (int)$record['TYPE_EVENT'] === (int)$value ? 1 : 0,
    );
}

$typeMeta = md_tlg_event_type_meta($record['TYPE_EVENT']);
$out['TYPE'] = (int)$record['TYPE_EVENT'];
$out['TYPE_LABEL'] = md_tlg_h($typeMeta['label']);
$out['TYPE_ICON'] = $typeMeta['icon'];
$out['TYPE_TONE'] = $typeMeta['tone'];
$out['TYPE_DESCRIPTION'] = md_tlg_h($typeMeta['description']);
$out['TYPE_VARS'] = md_tlg_h($typeMeta['vars']);
$out['TYPE_EXAMPLE'] = md_tlg_h(md_tlg_event_example($record['TYPE_EVENT']));

$out['TITLE'] = md_tlg_h($record['TITLE']);
$out['TITLE_HEADING'] = $record['TITLE'] !== '' ? md_tlg_h($record['TITLE']) : 'Новое событие';
$out['DESCRIPTION'] = md_tlg_h($record['DESCRIPTION']);
$out['ENABLE'] = (int)$record['ENABLE'];
$out['CODE'] = md_tlg_h($record['CODE']);
$out['RUNS'] = (int)$record['RUNS'];
$out['LAST_RUN_HUMAN'] = md_tlg_relative_time($record['LAST_RUN']);
$out['HAS_CODE'] = trim((string)$record['CODE']) !== '' ? 1 : 0;
$out['EXPORT_URL'] = $id > 0 ? '?view_mode=export_event&id=' . $id : '';
$out['ACTIVE_TAB'] = 'events';
$out['PAGE_MODE'] = 'event';
$out['OK_MSG'] = md_tlg_h(gr('ok_msg'));
