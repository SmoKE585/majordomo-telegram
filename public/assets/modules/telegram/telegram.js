/*
 * Telegram module page behaviour.
 *
 * The markup is server-rendered; this file only adds the small dynamic parts:
 * connection tests, webhook management, proxy helpers, code examples, the RAW
 * drawer and the log viewer. All requests go to admin.php with `ajax=1&op=...`.
 */
(function (window, document) {
    'use strict';

    var AJAX_BASE = (window.ROOTHTML || '/') + 'admin.php?action=telegram&ajax=1';

    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function request(op, data) {
        var body = new URLSearchParams();
        // On POST the legacy globals are built from the body only, so the
        // module action must be repeated here (admin.php is used for AJAX).
        body.set('action', 'telegram');
        body.set('ajax', '1');
        body.set('op', op);
        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            if (value === undefined || value === null) {
                return;
            }
            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    body.append(key + '[]', item);
                });
            } else {
                body.set(key, value);
            }
        });

        return window.fetch(AJAX_BASE, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.text().then(function (text) {
                var payload;
                try {
                    payload = JSON.parse(text);
                } catch (error) {
                    payload = { ok: false, error: 'Сервер вернул не JSON. Откройте журнал модуля.' };
                }
                return payload;
            });
        }).catch(function () {
            return { ok: false, error: 'Сеть недоступна. Проверьте соединение и повторите.' };
        });
    }

    function toast(type, title, text) {
        if (window.MDJUIToast && typeof window.MDJUIToast.show === 'function') {
            window.MDJUIToast.show({ type: type, title: title, text: text || '' });
        } else if (window.console && window.console.info) {
            window.console.info('[telegram] ' + title + ' ' + (text || ''));
        }
    }

    function setResult(element, message, type) {
        if (!element) {
            return;
        }
        element.textContent = message || '';
        element.classList.remove('is-ok', 'is-error', 'is-pending');
        if (type) {
            element.classList.add('is-' + type);
        }
    }

    function copyText(text) {
        if (!text) {
            return Promise.resolve(false);
        }
        if (window.navigator.clipboard && window.navigator.clipboard.writeText) {
            return window.navigator.clipboard.writeText(text).then(function () {
                return true;
            }).catch(function () {
                return legacyCopy(text);
            });
        }
        return Promise.resolve(legacyCopy(text));
    }

    function legacyCopy(text) {
        try {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', 'readonly');
            area.style.position = 'fixed';
            area.style.left = '-9999px';
            document.body.appendChild(area);
            area.select();
            var ok = document.execCommand('copy');
            document.body.removeChild(area);
            return ok;
        } catch (error) {
            return false;
        }
    }

    function copyTarget(targetId) {
        var target = document.getElementById(targetId);
        if (!target) {
            return;
        }
        var text = target.value !== undefined ? target.value : target.textContent;
        copyText(text).then(function (ok) {
            toast(ok ? 'success' : 'warning', ok ? 'Скопировано' : 'Не удалось скопировать', ok ? '' : 'Выделите текст вручную.');
        });
    }

    function withEditor(targetId, callback) {
        var textarea = document.getElementById(targetId);
        if (!textarea) {
            return;
        }
        var wrapper = textarea.closest('[data-code-editor]');
        if (!wrapper) {
            callback(null, textarea);
            return;
        }

        function finish(api) {
            var editor = api && typeof api.getEditor === 'function' ? api.getEditor(wrapper) : wrapper._codeEditor;
            callback(editor || null, textarea);
        }

        if (window.MDCodeEditor && typeof window.MDCodeEditor.load === 'function') {
            window.MDCodeEditor.load().then(function (api) {
                if (api && typeof api.init === 'function') {
                    api.init(wrapper);
                }
                finish(api);
            }).catch(function () {
                callback(null, textarea);
            });
        } else {
            finish(null);
        }
    }

    function replaceEditorCode(targetId, code, append) {
        withEditor(targetId, function (editor, textarea) {
            if (editor && typeof editor.getValue === 'function' && typeof editor.setValue === 'function') {
                var next = String(code || '');
                if (append && editor.getValue().trim() !== '') {
                    next = editor.getValue().replace(/\s+$/, '') + '\n\n' + next;
                }
                editor.setValue(next);
                if (typeof editor.save === 'function') {
                    editor.save();
                }
                if (typeof editor.focus === 'function') {
                    editor.focus();
                }
            } else if (textarea) {
                if (append && textarea.value.trim() !== '') {
                    textarea.value = textarea.value.replace(/\s+$/, '') + '\n\n' + String(code || '');
                } else {
                    textarea.value = String(code || '');
                }
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            }
            toast('success', 'Код вставлен', 'Проверьте его перед сохранением.');
        });
    }

    function getEditorCode(targetId) {
        var textarea = document.getElementById(targetId);
        if (!textarea) {
            return '';
        }
        var wrapper = textarea.closest('[data-code-editor]');
        if (wrapper && wrapper._codeEditor && typeof wrapper._codeEditor.getValue === 'function') {
            return wrapper._codeEditor.getValue();
        }
        return textarea.value;
    }

    // -----------------------------------------------------------------------
    // Connection test
    // -----------------------------------------------------------------------

    function checkConnection(button) {
        var result = qs('[data-md-tlg-check-result]');
        qsa('[data-md-tlg-check]').forEach(function (item) {
            item.disabled = true;
        });
        if (result) {
            result.textContent = 'Проверяем соединение…';
        }

        request('test').then(function (data) {
            qsa('[data-md-tlg-check]').forEach(function (item) {
                item.disabled = false;
            });

            if (!data.ok) {
                if (result) {
                    result.innerHTML = '';
                    var status = document.createElement('span');
                    status.className = 'md-tlg-status md-tlg-status--error';
                    status.innerHTML = '<span class="md-status__dot" aria-hidden="true"></span>';
                    status.appendChild(document.createTextNode('Нет связи'));
                    result.appendChild(status);
                    var hint = document.createElement('span');
                    hint.className = 'md-tlg-muted';
                    hint.textContent = (data.error || 'Неизвестная ошибка') + (data.hint ? ' — ' + data.hint : '');
                    result.appendChild(hint);
                }
                toast('danger', 'Telegram недоступен', (data.error || '') + (data.hint ? ' ' + data.hint : ''));
                return;
            }

            var bot = data.bot || {};
            if (result) {
                result.innerHTML = '';
                var okStatus = document.createElement('span');
                okStatus.className = 'md-tlg-status md-tlg-status--online';
                okStatus.innerHTML = '<span class="md-status__dot" aria-hidden="true"></span>';
                okStatus.appendChild(document.createTextNode('Связь есть'));
                result.appendChild(okStatus);

                var meta = document.createElement('span');
                meta.className = 'md-tlg-muted';
                var parts = [];
                if (bot.username) {
                    parts.push('@' + bot.username);
                }
                if (data.latency_ms !== undefined) {
                    parts.push(data.latency_ms + ' мс');
                }
                if (data.webhook && data.webhook.url) {
                    parts.push('webhook: ' + data.webhook.url);
                }
                meta.textContent = parts.join(' · ');
                result.appendChild(meta);
            }

            toast('success', 'Связь с Telegram есть', (bot.username ? '@' + bot.username + ' · ' : '') + (data.latency_ms || 0) + ' мс');
        });
    }

    // -----------------------------------------------------------------------
    // Send test message
    // -----------------------------------------------------------------------

    function openSendModal(chatId) {
        var modal = document.getElementById('mdTlgSendTestModal');
        if (!modal || !window.bootstrap) {
            return;
        }
        var input = document.getElementById('mdTlgTestChat');
        if (chatId && input) {
            input.value = chatId;
        }
        var result = qs('[data-md-tlg-send-result]');
        setResult(result, '');
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    function sendTest(button) {
        var chatId = (document.getElementById('mdTlgTestChat') || {}).value || '';
        var text = (document.getElementById('mdTlgTestText') || {}).value || '';
        var result = qs('[data-md-tlg-send-result]');
        button.disabled = true;
        setResult(result, 'Отправляем…', 'pending');

        request('send_test', { chat_id: chatId, text: text }).then(function (data) {
            button.disabled = false;
            if (!data.ok) {
                setResult(result, (data.error || 'Ошибка отправки') + (data.hint ? ' — ' + data.hint : ''), 'error');
                toast('danger', 'Сообщение не отправлено', data.error || '');
                return;
            }
            setResult(result, 'Отправлено. message_id: ' + (data.message_id || '—') + ', ' + (data.latency_ms || 0) + ' мс', 'ok');
            toast('success', 'Сообщение отправлено', 'Проверьте чат в Telegram.');
        });
    }

    // -----------------------------------------------------------------------
    // Webhook
    // -----------------------------------------------------------------------

    function selectedUpdates() {
        return qsa('input[name="allowed_updates[]"]:checked').map(function (input) {
            return input.value;
        });
    }

    function webhookData() {
        return {
            url: (document.getElementById('mdTlgWebhookUrl') || {}).value || '',
            secret: (document.getElementById('mdTlgWebhookSecret') || {}).value || '',
            max_connections: (document.getElementById('mdTlgWebhookMaxConn') || {}).value || '40',
            drop_pending: qs('#mdTlgWebhookDrop') && qs('#mdTlgWebhookDrop').checked ? 1 : 0,
            allowed_updates: selectedUpdates().join(',')
        };
    }

    function renderWebhookStatus(container, payload) {
        if (!container) {
            return;
        }
        container.hidden = false;
        container.textContent = '';
        if (!payload || payload.ok === false) {
            container.textContent = (payload && (payload.error || (payload.webhook && payload.webhook.error))) || 'Не удалось получить статус webhook.';
            return;
        }
        var webhook = payload.webhook || payload;
        var rows = [
            ['URL', webhook.url || 'не задан'],
            ['Ожидает обновлений', String(webhook.pending_update_count || 0)],
            ['max_connections', String(webhook.max_connections || '—')],
            ['Последняя ошибка', webhook.last_error_message ? webhook.last_error_message + (webhook.last_error_date_human ? ' (' + webhook.last_error_date_human + ')' : '') : 'нет']
        ];
        rows.forEach(function (row) {
            var line = document.createElement('div');
            var label = document.createElement('strong');
            label.textContent = row[0] + ': ';
            line.appendChild(label);
            line.appendChild(document.createTextNode(row[1]));
            container.appendChild(line);
        });
    }

    function webhookAction(action, button) {
        var result = qs('[data-md-tlg-webhook-result]');
        if (button) {
            button.disabled = true;
        }
        setResult(result, 'Выполняем…', 'pending');
        request(action, webhookData()).then(function (data) {
            if (button) {
                button.disabled = false;
            }
            if (!data.ok) {
                setResult(result, (data.error || 'Ошибка') + (data.hint ? ' — ' + data.hint : ''), 'error');
                toast('danger', 'Webhook', data.error || 'Ошибка запроса');
                return;
            }
            setResult(result, data.message || 'Готово', 'ok');
            toast('success', 'Webhook', data.message || 'Готово');
            if (action === 'webhook_set') {
                var secret = document.getElementById('mdTlgWebhookSecret');
                if (secret && data.secret) {
                    secret.value = data.secret;
                }
            }
            if (action === 'webhook_info' && data.webhook) {
                renderWebhookStatus(qs('[data-md-tlg-webhook-status]'), data);
            }
        });
    }

    // -----------------------------------------------------------------------
    // Proxy helpers
    // -----------------------------------------------------------------------

    function checkProxy(button) {
        var host = (document.getElementById('mdTlgProxyHost') || {}).value || '';
        var port = (document.getElementById('mdTlgProxyPort') || {}).value || '';
        var result = qs('[data-md-tlg-proxy-result]');
        button.disabled = true;
        setResult(result, 'Проверяем порт…', 'pending');
        request('check_proxy', { host: host, port: port }).then(function (data) {
            button.disabled = false;
            if (!data.ok) {
                setResult(result, (data.error || 'Нет соединения') + (data.latency_ms !== undefined ? ' (' + data.latency_ms + ' мс)' : ''), 'error');
                return;
            }
            setResult(result, 'Порт доступен, ' + (data.latency_ms || 0) + ' мс', 'ok');
        });
    }

    function parseProxyLink(button) {
        var link = (document.getElementById('mdTlgMtUrl') || {}).value || '';
        var container = qs('[data-md-tlg-parse-result]');
        if (!link) {
            setResult(container, 'Вставьте ссылку tg://socks или tg://proxy.', 'error');
            return;
        }
        button.disabled = true;
        setResult(container, 'Разбираем…', 'pending');
        request('parse_proxy_link', { link: link }).then(function (data) {
            button.disabled = false;
            container.textContent = '';
            if (!data.ok) {
                container.classList.add('is-error');
                container.textContent = data.message || 'Не удалось разобрать ссылку.';
                return;
            }
            container.classList.remove('is-error');
            var text = document.createElement('p');
            text.textContent = data.message || '';
            container.appendChild(text);

            var facts = document.createElement('p');
            facts.textContent = 'Сервер: ' + (data.server || '—') + ', порт: ' + (data.port || '—') + (data.secret ? ', secret: ' + data.secret : '');
            container.appendChild(facts);

            if (data.type === 'socks') {
                var apply = document.createElement('button');
                apply.type = 'button';
                apply.className = 'md-btn md-btn--secondary md-btn--sm';
                apply.textContent = 'Применить к SOCKS5';
                apply.addEventListener('click', function () {
                    applySocks(data);
                });
                container.appendChild(apply);
            } else {
                var note = document.createElement('p');
                note.className = 'md-tlg-muted';
                note.textContent = 'Для работы через MTProto запустите локальный Bot API сервер и укажите его URL в поле «Базовый URL Bot API».';
                container.appendChild(note);
            }
        });
    }

    function applySocks(data) {
        var socksRadio = qs('[data-md-tlg-transport][value="socks5"]');
        if (socksRadio) {
            socksRadio.checked = true;
            socksRadio.dispatchEvent(new Event('change', { bubbles: true }));
        }
        var host = document.getElementById('mdTlgProxyHost');
        var port = document.getElementById('mdTlgProxyPort');
        var login = document.getElementById('mdTlgProxyLogin');
        var password = document.getElementById('mdTlgProxyPassword');
        if (host) {
            host.value = data.server || '';
        }
        if (port) {
            port.value = data.port || '';
        }
        if (login) {
            login.value = data.user || '';
        }
        if (password) {
            password.value = data.pass || '';
        }
        toast('success', 'Прокси заполнен', 'Проверьте поля и сохраните настройки.');
    }

    // -----------------------------------------------------------------------
    // Settings panels
    // -----------------------------------------------------------------------

    function syncTransport(root) {
        var selected = qs('[data-md-tlg-transport]:checked', root || document);
        var value = selected ? selected.value : 'direct';
        var isProxy = ['http', 'https', 'socks5', 'socks5h'].indexOf(value) >= 0;
        var hintElement = qs('[data-md-tlg-transport-hint] span', root || document);

        qsa('[data-md-tlg-panel="proxy"]', root || document).forEach(function (panel) {
            panel.hidden = !isProxy;
        });
        qsa('[data-md-tlg-panel="local"]', root || document).forEach(function (panel) {
            panel.hidden = value !== 'local_api';
        });
        qsa('[data-md-tlg-panel="transparent"]', root || document).forEach(function (panel) {
            panel.hidden = value !== 'transparent';
        });
        if (hintElement && selected && selected.getAttribute('data-md-tlg-hint')) {
            hintElement.textContent = selected.getAttribute('data-md-tlg-hint');
        }
    }

    function syncPanels(root) {
        var access = qs('[data-md-tlg-access]', root || document);
        if (access) {
            var showUsers = access.value === '1' || access.value === '2';
            qsa('[data-md-tlg-panel="access-users"]', root || document).forEach(function (panel) {
                panel.hidden = !showUsers;
            });
        }

        var showMode = qs('[data-md-tlg-show-mode]', root || document);
        if (showMode) {
            qsa('[data-md-tlg-panel="condition"]', root || document).forEach(function (panel) {
                panel.hidden = showMode.value !== '3';
            });
        }

        var webhook = qs('[data-md-tlg-webhook]', root || document);
        if (webhook) {
            qsa('[data-md-tlg-panel="webhook"]', root || document).forEach(function (panel) {
                panel.hidden = !webhook.checked;
            });
        }

        syncTransport(root);
    }

    function togglePassword(button) {
        var input = document.getElementById(button.getAttribute('data-md-tlg-password-toggle'));
        if (!input) {
            return;
        }
        var isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        button.setAttribute('aria-label', isPassword ? 'Скрыть' : 'Показать');
        button.setAttribute('title', isPassword ? 'Скрыть' : 'Показать');
    }

    // -----------------------------------------------------------------------
    // Code helpers
    // -----------------------------------------------------------------------

    function loadCommandExample(button) {
        var key = button.getAttribute('data-md-tlg-example-key');
        var targetId = button.getAttribute('data-md-tlg-example-target');
        request('example', { kind: 'command', key: key }).then(function (data) {
            if (!data.ok) {
                toast('danger', 'Примеры', data.error || 'Не удалось получить пример.');
                return;
            }
            replaceEditorCode(targetId, '// ' + data.title + '\n' + data.code + '\n', true);
        });
    }

    function loadEventMeta(type) {
        request('example', { kind: 'event', type: type }).then(function (data) {
            if (!data.ok) {
                return;
            }
            var label = qs('[data-md-tlg-event-type-label]');
            var description = qs('[data-md-tlg-event-type-description]');
            var vars = qs('[data-md-tlg-event-type-vars]');
            var example = qs('[data-md-tlg-event-example] .md-tlg-pre');
            if (label) {
                label.textContent = data.label || '';
            }
            if (description) {
                description.textContent = data.description || '';
            }
            if (vars) {
                vars.textContent = data.vars || '';
            }
            if (example) {
                example.textContent = data.code || '';
            }
        });
    }

    function insertEventExample(button) {
        var select = qs('[data-md-tlg-event-type]');
        var targetId = button.getAttribute('data-md-tlg-code-target');
        if (!select) {
            return;
        }
        request('example', { kind: 'event', type: select.value }).then(function (data) {
            if (!data.ok) {
                toast('danger', 'Пример', data.error || 'Не удалось получить пример.');
                return;
            }
            replaceEditorCode(targetId, '// Пример: ' + data.label + '\n' + data.code + '\n', true);
        });
    }

    function validateCode(button) {
        var targetId = button.getAttribute('data-md-tlg-code-target');
        var code = getEditorCode(targetId);
        var result = qs('[data-md-tlg-code-result]');
        button.disabled = true;
        setResult(result, 'Проверяем синтаксис…', 'pending');
        request('validate_code', { code: code }).then(function (data) {
            button.disabled = false;
            if (data.ok) {
                setResult(result, data.message || 'Синтаксических ошибок нет.', 'ok');
                return;
            }
            setResult(result, (data.line ? 'Строка ' + data.line + ': ' : '') + (data.error || 'Ошибка синтаксиса'), 'error');
        });
    }

    // -----------------------------------------------------------------------
    // Log tools
    // -----------------------------------------------------------------------

    function currentLogFilter() {
        var form = qs('[data-md-tlg-log-form]');
        var filter = form ? (form.querySelector('input[name="filter"]') || {}).value || '' : '';
        var limit = form ? (form.querySelector('select[name="limit"]') || {}).value || '200' : '200';
        return { filter: filter, limit: limit };
    }

    function renderLogLines(container, lines) {
        if (!container) {
            return;
        }
        container.textContent = '';
        if (!lines || !lines.length) {
            var empty = document.createElement('div');
            empty.className = 'md-tlg-console__empty';
            empty.textContent = 'Строк по фильтру нет.';
            container.appendChild(empty);
            return;
        }
        lines.forEach(function (line) {
            var row = document.createElement('div');
            row.className = 'md-tlg-console__line';
            var time = document.createElement('span');
            time.className = 'md-tlg-console__time';
            time.textContent = line.time || '';
            var text = document.createElement('span');
            text.className = 'md-tlg-console__text';
            text.textContent = line.text || '';
            row.appendChild(time);
            row.appendChild(text);
            container.appendChild(row);
        });
    }

    function refreshLog() {
        var container = qs('[data-md-tlg-log-output]');
        var params = currentLogFilter();
        request('log', params).then(function (data) {
            if (!data.ok) {
                return;
            }
            renderLogLines(container, data.lines || []);
        });
    }

    function downloadLog(button) {
        var params = currentLogFilter();
        button.disabled = true;
        request('log', params).then(function (data) {
            button.disabled = false;
            if (!data.ok) {
                toast('danger', 'Журнал', data.error || 'Не удалось прочитать журнал.');
                return;
            }
            var text = (data.lines || []).map(function (line) {
                return (line.time ? line.time + ' ' : '') + line.text;
            }).join('\n');
            var blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
            var url = URL.createObjectURL(blob);
            var link = document.createElement('a');
            link.href = url;
            link.download = 'telegram-log-' + new Date().toISOString().slice(0, 10) + '.log';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        });
    }

    function initLogLive(root) {
        var toggle = qs('[data-md-tlg-log-live]', root || document);
        if (!toggle) {
            return;
        }
        var timer = null;

        function start() {
            if (timer) {
                return;
            }
            timer = window.setInterval(function () {
                if (!document.hidden) {
                    refreshLog();
                }
            }, 5000);
        }

        function stop() {
            if (timer) {
                window.clearInterval(timer);
                timer = null;
            }
        }

        if (toggle.checked) {
            start();
        }
        toggle.addEventListener('change', function () {
            if (toggle.checked) {
                refreshLog();
                start();
            } else {
                stop();
            }
        });
    }

    // -----------------------------------------------------------------------
    // RAW drawer
    // -----------------------------------------------------------------------

    function openRaw(recordId) {
        var body = document.createElement('div');
        body.className = 'md-tlg-raw-drawer';
        var pre = document.createElement('pre');
        pre.className = 'md-tlg-pre';
        pre.textContent = 'Загрузка…';
        body.appendChild(pre);

        var footer = document.createElement('div');
        footer.className = 'md-tlg-raw-drawer__footer';
        var copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'md-btn md-btn--secondary md-btn--sm';
        copy.innerHTML = '<i class="ti ti-copy" aria-hidden="true"></i><span class="md-btn__label">Копировать</span>';
        copy.addEventListener('click', function () {
            copyText(pre.textContent).then(function (ok) {
                toast(ok ? 'success' : 'warning', ok ? 'Скопировано' : 'Не удалось скопировать');
            });
        });
        footer.appendChild(copy);

        if (window.MDJAdminUI && typeof window.MDJAdminUI.openDrawer === 'function') {
            window.MDJAdminUI.openDrawer({
                owner: 'telegram-raw',
                eyebrow: 'История Telegram',
                title: 'RAW запись #' + recordId,
                subtitle: 'Полный JSON, полученный или отправленный модулем.',
                width: '680px',
                body: body,
                footer: footer
            });
        } else {
            return;
        }

        request('history_raw', { id: recordId }).then(function (data) {
            if (!data.ok) {
                pre.textContent = data.error || 'Не удалось загрузить запись.';
                return;
            }
            pre.textContent = data.raw || '';
        });
    }

    // -----------------------------------------------------------------------
    // Init
    // -----------------------------------------------------------------------

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        if (scope.__mdTlgInitialized) {
            return;
        }
        scope.__mdTlgInitialized = true;

        qsa('[data-md-tlg-check]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                checkConnection(button);
            });
        });

        qsa('[data-md-tlg-open-send-test]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                openSendModal('');
            });
        });

        qsa('[data-md-tlg-send]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                openSendModal(button.getAttribute('data-chat-id') || '');
            });
        });

        var sendButton = qs('[data-md-tlg-send-test]', scope);
        if (sendButton) {
            sendButton.addEventListener('click', function () {
                sendTest(sendButton);
            });
        }

        qsa('[data-md-tlg-webhook-set]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                webhookAction('webhook_set', button);
            });
        });
        qsa('[data-md-tlg-webhook-delete]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                webhookAction('webhook_delete', button);
            });
        });
        qsa('[data-md-tlg-webhook-info]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                webhookAction('webhook_info', button);
            });
        });

        qsa('[data-md-tlg-check-proxy]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                checkProxy(button);
            });
        });
        qsa('[data-md-tlg-parse-link]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                parseProxyLink(button);
            });
        });
        qsa('[data-md-tlg-generate-secret]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                request('generate_secret').then(function (data) {
                    var input = document.getElementById('mdTlgWebhookSecret');
                    if (data.ok && input) {
                        input.value = data.secret;
                        toast('success', 'Secret создан', 'Не забудьте сохранить настройки.');
                    }
                });
            });
        });

        qsa('[data-md-tlg-password-toggle]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                togglePassword(button);
            });
        });

        qsa('[data-md-tlg-copy-target]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                copyTarget(button.getAttribute('data-md-tlg-copy-target'));
            });
        });

        qsa('[data-md-tlg-transport]', scope).forEach(function (input) {
            input.addEventListener('change', function () {
                syncTransport(scope);
            });
        });
        qsa('[data-md-tlg-access]', scope).forEach(function (select) {
            select.addEventListener('change', function () {
                syncPanels(scope);
            });
        });
        qsa('[data-md-tlg-show-mode]', scope).forEach(function (select) {
            select.addEventListener('change', function () {
                syncPanels(scope);
            });
        });
        qsa('[data-md-tlg-webhook]', scope).forEach(function (input) {
            input.addEventListener('change', function () {
                syncPanels(scope);
            });
        });

        qsa('[data-md-tlg-event-type]', scope).forEach(function (select) {
            select.addEventListener('change', function () {
                loadEventMeta(select.value);
            });
        });
        qsa('[data-md-tlg-insert-event-example]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                insertEventExample(button);
            });
        });
        qsa('[data-md-tlg-example-key]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                loadCommandExample(button);
            });
        });
        qsa('[data-md-tlg-validate-code]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                validateCode(button);
            });
        });

        qsa('[data-md-tlg-raw]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                openRaw(button.getAttribute('data-md-tlg-raw'));
            });
        });

        qsa('[data-md-tlg-log-refresh]', scope).forEach(function (button) {
            button.addEventListener('click', refreshLog);
        });
        qsa('[data-md-tlg-log-download]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                downloadLog(button);
            });
        });
        initLogLive(scope);

        syncPanels(scope);
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('telegram', { init: init });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})(window, document);
