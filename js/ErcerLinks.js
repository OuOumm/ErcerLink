/**
 * ErcerLink 友链申请前端脚本
 *
 * 原生 JS，不依赖 jQuery：主题未加载 jQuery 时表单同样可以工作。
 * 所有可变配置（绝对 endpoint、CSRF token、Turnstile sitekey、文案）
 * 均通过后端注入的 window.ercerLinkConfig 提供，脚本自身不含站点相关硬编码。
 */
(function (window, document) {
    'use strict';

    var FALLBACK = {
        endpoint: '/link_add',
        csrf: '',
        csrfField: 'ercerlink_csrf',
        csrfHeader: 'X-ErcerLink-CSRF',
        siteKey: '',
        success: '200',
        messages: {
            captchaTitle: '请完成验证',
            captchaMessage: '请先完成人机验证',
            requestFail: '请求失败，请稍后重试',
            successTitle: '提交成功',
            successMessage: '等待站长审核哟！',
            failTitle: '提交失败',
            submitting: '提交中...'
        }
    };

    var FORM_ID = 'F-link';
    var CONTAINER_SELECTOR = '#postLink';
    var TURNSTILE_CONTAINER_ID = 'turnstile-widget';
    var NOTICE_ID = 'ercerlink-notice';
    var TURNSTILE_ACTION = 'link_add';
    var UNLOCK_DELAY = 3000;
    var COOKIE_TTL_DAYS = 1;

    var cfg = null;
    var turnstileWidgetId = null;

    function isString(value) {
        return typeof value === 'string';
    }

    function readConfig() {
        var injected = window.ercerLinkConfig;
        var result = {
            endpoint: FALLBACK.endpoint,
            csrf: FALLBACK.csrf,
            csrfField: FALLBACK.csrfField,
            csrfHeader: FALLBACK.csrfHeader,
            siteKey: FALLBACK.siteKey,
            success: FALLBACK.success,
            messages: {}
        };

        if (injected && typeof injected === 'object') {
            ['endpoint', 'csrf', 'csrfField', 'csrfHeader', 'siteKey', 'success'].forEach(function (key) {
                if (isString(injected[key]) && injected[key] !== '') {
                    result[key] = injected[key];
                }
            });
        }

        Object.keys(FALLBACK.messages).forEach(function (key) {
            result.messages[key] = FALLBACK.messages[key];
        });

        if (injected && injected.messages && typeof injected.messages === 'object') {
            Object.keys(FALLBACK.messages).forEach(function (key) {
                if (isString(injected.messages[key]) && injected.messages[key] !== '') {
                    result.messages[key] = injected.messages[key];
                }
            });
        }

        return result;
    }

    function setCookie(name, value, days) {
        var expires = new Date(Date.now() + days * 86400000).toUTCString();
        var secure = window.location.protocol === 'https:' ? '; Secure' : '';

        document.cookie = name + '=' + encodeURIComponent(value)
            + '; path=/; expires=' + expires + '; SameSite=Lax' + secure;
    }

    function getConfig() {
        if (cfg === null) {
            cfg = readConfig();

            // 双提交 CSRF：页面拿到 token 后落到同站 Cookie，
            // 提交时再通过请求头带回，服务端比对两者是否一致。
            if (cfg.csrf !== '') {
                setCookie(cfg.csrfField, cfg.csrf, COOKIE_TTL_DAYS);
            }
        }

        return cfg;
    }

    function hasCaptcha() {
        return getConfig().siteKey !== '';
    }

    function getContainer() {
        return document.querySelector(CONTAINER_SELECTOR);
    }

    function getForm() {
        return document.getElementById(FORM_ID);
    }

    function showNotice(title, message, type) {
        var host = document.getElementById(NOTICE_ID);
        var container = getContainer();

        if (!host) {
            host = document.createElement('div');
            host.id = NOTICE_ID;
            host.setAttribute('role', 'status');

            if (container && container.parentNode) {
                container.parentNode.insertBefore(host, container.nextSibling);
            } else {
                document.body.appendChild(host);
            }
        }

        host.textContent = title + '：' + message;
        host.style.cssText = 'margin:10px 0;padding:10px 12px;border-radius:6px;font-size:14px;'
            + 'background:' + (type === 'error' ? '#fdecea' : '#e8f5e9') + ';'
            + 'color:' + (type === 'error' ? '#b3261e' : '#1b5e20') + ';'
            + 'border:1px solid ' + (type === 'error' ? '#f5c6c0' : '#b7dfb9') + ';';
    }

    function showMessage(title, message, type) {
        var jq = typeof window.jQuery === 'function' ? window.jQuery : null;

        if (jq && typeof jq.message === 'function') {
            jq.message({ title: title, message: message, type: type });
            return;
        }

        showNotice(title, message, type);
    }

    function setSubmitting(submitting) {
        var form = getForm();
        var button = form ? form.querySelector('button[type="submit"]') : null;

        if (!button) {
            return;
        }

        button.disabled = submitting;
        button.textContent = submitting
            ? getConfig().messages.submitting
            : (button.getAttribute('data-label') || '申请');
    }

    function unlockLater() {
        window.setTimeout(function () {
            setSubmitting(false);
        }, UNLOCK_DELAY);
    }

    function getTurnstileApi() {
        return window.turnstile && typeof window.turnstile === 'object' ? window.turnstile : null;
    }

    function isTurnstileReady() {
        return turnstileWidgetId !== null && getTurnstileApi() !== null;
    }

    function getTurnstileToken() {
        var api = getTurnstileApi();

        if (!api || turnstileWidgetId === null || typeof api.getResponse !== 'function') {
            return '';
        }

        return String(api.getResponse(turnstileWidgetId) || '').trim();
    }

    function resetTurnstile() {
        var api = getTurnstileApi();

        if (api && turnstileWidgetId !== null && typeof api.reset === 'function') {
            api.reset(turnstileWidgetId);
        }
    }

    function removeTurnstileWidget() {
        var api = getTurnstileApi();

        if (api && turnstileWidgetId !== null && typeof api.remove === 'function') {
            try {
                api.remove(turnstileWidgetId);
            } catch (e) {
                // 组件可能已被父容器清空后销毁，忽略
            }
        }

        turnstileWidgetId = null;
    }

    function renderTurnstile() {
        var api = getTurnstileApi();
        var host = document.getElementById(TURNSTILE_CONTAINER_ID);

        if (!api || !host || turnstileWidgetId !== null || typeof api.render !== 'function') {
            return;
        }

        turnstileWidgetId = api.render(host, {
            sitekey: getConfig().siteKey,
            action: TURNSTILE_ACTION,
            theme: 'auto',
            size: 'flexible'
        });
    }

    function whenTurnstileReady(callback) {
        if (!hasCaptcha()) {
            return;
        }

        if (getTurnstileApi()) {
            callback();
            return;
        }

        var waited = 0;
        var timer = window.setInterval(function () {
            waited += 200;

            if (getTurnstileApi()) {
                window.clearInterval(timer);
                callback();
                return;
            }

            if (waited >= 10000) {
                window.clearInterval(timer);
            }
        }, 200);
    }

    // Cloudflare 显式渲染的回调入口（由脚本 URL 的 onload 参数指定）。
    // 后端会先注册一个占位函数记录「已触发」，本脚本加载后再接管，
    // 因此 onload 早于本脚本执行时也不会丢失渲染时机。
    function onTurnstileReady() {
        renderTurnstile();
    }

    function takeOverTurnstileCallback() {
        if (!hasCaptcha()) {
            return;
        }

        window.ercerLinkTurnstileReady = onTurnstileReady;

        if (window.ercerLinkTurnstileFired === true) {
            onTurnstileReady();
        }
    }

    function createField(name, type, placeholder) {
        var group = document.createElement('div');
        var input = document.createElement('input');

        group.className = 'form-group';
        input.type = type;
        input.name = name;
        input.className = 'form-control';
        input.placeholder = placeholder;
        input.required = true;
        input.maxLength = 200;

        group.appendChild(input);

        return group;
    }

    function buildForm() {
        var form = document.createElement('form');

        form.id = FORM_ID;
        form.className = 'form-inline panel-body';
        form.setAttribute('role', 'form');
        form.style.textAlign = 'center';

        var heading = document.createElement('h4');
        heading.textContent = '💞申请友链💞';
        form.appendChild(heading);

        form.appendChild(createField('host_name', 'text', '站点名称'));
        form.appendChild(createField('host_url', 'url', '站点链接（https://）'));
        form.appendChild(createField('host_png', 'url', '站点图标（https://）'));
        form.appendChild(createField('host_msg', 'text', '站点描述（不用太长）'));

        var footer = document.createElement('div');
        footer.className = 'checkbox m-l m-r-xs';

        var label = document.createElement('label');
        label.className = 'i-checks';

        var agree = document.createElement('input');
        agree.type = 'checkbox';
        agree.name = 'host_yes';
        agree.value = '1';
        agree.required = true;

        label.appendChild(agree);
        label.appendChild(document.createElement('i'));
        label.appendChild(document.createTextNode('已添加本站为友链'));

        var submit = document.createElement('button');
        submit.className = 'btn btn-danger';
        submit.type = 'submit';
        submit.textContent = '申请';
        submit.setAttribute('data-label', '申请');

        footer.appendChild(label);
        footer.appendChild(document.createTextNode(' '));
        footer.appendChild(submit);
        form.appendChild(footer);

        if (hasCaptcha()) {
            var captchaGroup = document.createElement('div');
            var captcha = document.createElement('div');

            captchaGroup.className = 'form-group';
            captchaGroup.style.margin = '10px 0';
            captcha.id = TURNSTILE_CONTAINER_ID;

            captchaGroup.appendChild(captcha);
            form.appendChild(captchaGroup);
        }

        return form;
    }

    function renderForm() {
        var container = getContainer();

        if (!container) {
            return false;
        }

        removeTurnstileWidget();
        container.textContent = '';
        container.appendChild(buildForm());

        whenTurnstileReady(renderTurnstile);

        return true;
    }

    function buildPayload(form) {
        var data = new FormData(form);
        var pairs = [];

        data.forEach(function (value, key) {
            if (isString(value)) {
                pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
            }
        });

        return pairs.join('&');
    }

    function handleResult(text) {
        var conf = getConfig();
        var ok = text === conf.success;

        showMessage(
            ok ? conf.messages.successTitle : conf.messages.failTitle,
            ok ? conf.messages.successMessage : text,
            ok ? 'success' : 'error'
        );

        setSubmitting(false);

        if (ok) {
            var form = getForm();

            if (form) {
                form.reset();
            }
        }

        resetTurnstile();
    }

    function handleFailure() {
        var conf = getConfig();

        showMessage(conf.messages.failTitle, conf.messages.requestFail, 'error');
        unlockLater();
        resetTurnstile();
    }

    function submit(form) {
        var conf = getConfig();
        var headers = {};

        headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
        headers['X-Requested-With'] = 'XMLHttpRequest';
        headers[conf.csrfHeader] = conf.csrf;

        window.fetch(conf.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: headers,
            body: buildPayload(form)
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.text();
        }).then(function (text) {
            handleResult(String(text).trim());
        }).catch(function () {
            handleFailure();
        });
    }

    function onSubmit(event) {
        event.preventDefault();

        var form = event.currentTarget;
        var conf = getConfig();

        if (form.getAttribute('data-submitting') === '1') {
            return;
        }

        if (hasCaptcha() && !isTurnstileReady()) {
            showMessage(conf.messages.captchaTitle, conf.messages.captchaMessage, 'error');
            return;
        }

        if (hasCaptcha() && getTurnstileToken() === '') {
            showMessage(conf.messages.captchaTitle, conf.messages.captchaMessage, 'error');
            return;
        }

        form.setAttribute('data-submitting', '1');
        setSubmitting(true);
        submit(form);

        window.setTimeout(function () {
            form.removeAttribute('data-submitting');
        }, UNLOCK_DELAY);
    }

    function bindSubmit() {
        var form = getForm();

        if (!form || form.getAttribute('data-bound') === '1') {
            return;
        }

        form.setAttribute('data-bound', '1');
        form.addEventListener('submit', onSubmit);
    }

    function init() {
        if (!renderForm()) {
            return false;
        }

        bindSubmit();
        takeOverTurnstileCallback();

        return true;
    }

    function refresh() {
        var rendered = renderForm();

        if (rendered) {
            bindSubmit();
            takeOverTurnstileCallback();
        }

        return rendered;
    }

    window.ErcerLink = {
        init: init,
        refresh: refresh,
        onTurnstileReady: onTurnstileReady
    };
    window.pjax_Link = init;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
