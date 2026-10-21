<?php
/**
 * User card: permissions, bound MajorDoMo user and delivery preferences.
 */

$id = isset($id) ? (int)$id : 0;
$tableName = 'tlg_user';
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

if ($this->mode === 'update') {
    $errors = array();
    $submitted = array(
        'USER_ID' => trim((string)gr('user_id')),
        'NAME' => trim((string)gr('name')),
        'ADMIN' => (int)gr('admin') ? 1 : 0,
        'SILENT' => (int)gr('silent') ? 1 : 0,
        'HISTORY' => (int)gr('history') ? 1 : 0,
        'HISTORY_LEVEL' => max(0, min(10, (int)gr('history_level'))),
        'HISTORY_SILENT' => max(0, min(10, (int)gr('history_silent'))),
        'CMD' => (int)gr('cmd') ? 1 : 0,
        'PATTERNS' => (int)gr('patterns') ? 1 : 0,
        'DOWNLOAD' => (int)gr('download') ? 1 : 0,
        'PLAY' => (int)gr('play') ? 1 : 0,
        'MEMBER_ID' => max(0, (int)gr('member_id')),
    );

    if ($submitted['USER_ID'] === '') {
        $errors[] = 'Укажите chat_id пользователя или @username канала.';
    } elseif (!preg_match('/^-?\d+$/', $submitted['USER_ID']) && !preg_match('/^@[A-Za-z0-9_]{4,}$/', $submitted['USER_ID'])) {
        $errors[] = 'chat_id должен быть числом (для группы — отрицательным) или @username канала.';
    } else {
        $duplicate = SQLSelectOne("SELECT * FROM " . $tableName . " WHERE USER_ID='" . DBSafe($submitted['USER_ID']) . "'" . ($id > 0 ? ' AND ID<>' . $id : ''));
        if ($duplicate) {
            $errors[] = 'Пользователь с таким chat_id уже добавлен.';
        }
    }

    if ($submitted['HISTORY_LEVEL'] > 0 && !$submitted['HISTORY']) {
        $submitted['HISTORY_LEVEL'] = 0;
    }
    if ($submitted['HISTORY_SILENT'] > 0 && !$submitted['HISTORY']) {
        $submitted['HISTORY_SILENT'] = 0;
    }

    if (empty($errors)) {
        $record = array_merge($record, $submitted);
        if ($isNew) {
            $record['CREATED'] = date('Y-m-d H:i:s');
            $record['LAST_SEEN'] = date('Y-m-d H:i:s');
            $record['ID'] = SQLInsert($tableName, $record);
            $id = (int)$record['ID'];
            $out['ID'] = $id;
        } else {
            SQLUpdate($tableName, $record);
        }
        if (!empty($submitted['USER_ID']) && preg_match('/^-?\d+$/', $submitted['USER_ID'])) {
            $this->updateInfo(SQLSelectOne('SELECT * FROM ' . $tableName . ' WHERE ID=' . (int)$record['ID']));
        }
        $this->redirect('?tab=users&ok_msg=' . urlencode('Пользователь сохранён'));
    }

    // Keep submitted values visible after a validation error.
    $record = array_merge($record, $submitted);
    $out['SAVE_ERRORS'] = $errors;
}

$record += array(
    'ID' => '',
    'USER_ID' => '',
    'NAME' => '',
    'USERNAME' => '',
    'CREATED' => '',
    'LAST_SEEN' => '',
    'ADMIN' => 0,
    'SILENT' => 0,
    'HISTORY' => 0,
    'HISTORY_LEVEL' => 0,
    'HISTORY_SILENT' => 0,
    'CMD' => 0,
    'PATTERNS' => 0,
    'DOWNLOAD' => 0,
    'PLAY' => 0,
    'MEMBER_ID' => 0,
);

$members = SQLSelect('SELECT ID, NAME FROM users ORDER BY NAME');
$out['MEMBERS'] = array();
$foundMember = false;
foreach ((array)$members as $member) {
    $selected = (int)$member['ID'] === (int)$record['MEMBER_ID'];
    if ($selected) {
        $foundMember = true;
    }
    $out['MEMBERS'][] = array(
        'ID' => (int)$member['ID'],
        'NAME' => md_tlg_h($member['NAME']),
        'SELECTED' => $selected ? 1 : 0,
    );
}
if (!$foundMember && (int)$record['MEMBER_ID'] > 0) {
    array_unshift($out['MEMBERS'], array(
        'ID' => (int)$record['MEMBER_ID'],
        'NAME' => 'Пользователь #' . (int)$record['MEMBER_ID'],
        'SELECTED' => 1,
    ));
}

$out['USER_ID'] = md_tlg_h($record['USER_ID']);
$out['NAME'] = md_tlg_h($record['NAME']);
$out['USERNAME'] = md_tlg_h($record['USERNAME']);
$out['HAS_USERNAME'] = $record['USERNAME'] !== '' ? 1 : 0;
$out['AVATAR'] = md_tlg_user_avatar($record['USER_ID']);
$out['INITIALS'] = md_tlg_h(md_tlg_initials($record['NAME'] !== '' ? $record['NAME'] : $record['USERNAME']));
$out['TITLE'] = $record['NAME'] !== '' ? md_tlg_h($record['NAME']) : ($record['USERNAME'] !== '' ? '@' . md_tlg_h($record['USERNAME']) : 'Новый пользователь');
$out['CREATED_HUMAN'] = md_tlg_relative_time($record['CREATED']);
$out['LAST_SEEN_HUMAN'] = md_tlg_relative_time($record['LAST_SEEN']);
$out['LAST_SEEN_EXACT'] = md_tlg_h($record['LAST_SEEN']);
$out['IS_GROUP'] = substr((string)$record['USER_ID'], 0, 1) === '-' ? 1 : 0;

foreach (array('ADMIN', 'SILENT', 'HISTORY', 'CMD', 'PATTERNS', 'DOWNLOAD', 'PLAY') as $flag) {
    $out[$flag] = (int)$record[$flag] ? 1 : 0;
}
$out['HISTORY_LEVEL'] = (int)$record['HISTORY_LEVEL'];
$out['HISTORY_SILENT'] = (int)$record['HISTORY_SILENT'];
$out['MEMBER_ID'] = (int)$record['MEMBER_ID'];

$out['LEVEL_OPTIONS'] = array();
for ($level = 0; $level <= 10; $level++) {
    $out['LEVEL_OPTIONS'][] = array(
        'VALUE' => $level,
        'TITLE' => $level === 0 ? 'Все сообщения' : 'Уровень ' . $level,
        'SELECTED' => (int)$record['HISTORY_LEVEL'] === $level ? 1 : 0,
    );
}
$out['SILENT_OPTIONS'] = array();
for ($level = 0; $level <= 10; $level++) {
    $out['SILENT_OPTIONS'][] = array(
        'VALUE' => $level,
        'TITLE' => $level === 0 ? 'Всегда без звука' : 'Со звуком от уровня ' . $level,
        'SELECTED' => (int)$record['HISTORY_SILENT'] === $level ? 1 : 0,
    );
}

$out['ACTIVE_TAB'] = 'users';
$out['PAGE_MODE'] = 'user';
$out['OK_MSG'] = md_tlg_h(gr('ok_msg'));
