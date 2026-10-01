/**
 * Content Studio tracking.
 *
 * Wordt als los bestand geladen (Content_Studio_Tracking) zodat een strikte
 * Content-Security-Policy met script-src 'self' het script gewoon toestaat;
 * inline scripts worden op productie geblokkeerd.
 *
 * Configuratie komt uit de data-attributen op #content-studio-tracking.
 */
(function () {
    'use strict';

    var config = document.getElementById('content-studio-tracking');

    if (!config) {
        return;
    }

    var endpoint = config.getAttribute('data-endpoint') || '';

    // Alleen een absolute URL naar de engine; een relatieve waarde zou het
    // event naar de site zelf posten.
    if (!/^https?:\/\//i.test(endpoint)) {
        return;
    }

    function uuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            var bytes = window.crypto.getRandomValues(new Uint8Array(16));
            bytes[6] = (bytes[6] & 0x0f) | 0x40;
            bytes[8] = (bytes[8] & 0x3f) | 0x80;
            var hex = [];
            for (var i = 0; i < bytes.length; i++) {
                hex.push((bytes[i] + 0x100).toString(16).slice(1));
            }

            return hex.slice(0, 4).join('') + '-' + hex.slice(4, 6).join('') + '-' +
                hex.slice(6, 8).join('') + '-' + hex.slice(8, 10).join('') + '-' +
                hex.slice(10, 16).join('');
        }

        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    function storageGet(storage, name) {
        try {
            return storage.getItem(name);
        } catch (e) {
            return null;
        }
    }

    function storageSet(storage, name, value) {
        try {
            storage.setItem(name, value);
        } catch (e) {
            // Private mode / geblokkeerde storage: negeren.
        }
    }

    var cookieName = 'shazzoo_visitor_id';

    function getVisitorId() {
        var match = document.cookie.split('; ').filter(function (row) {
            return row.indexOf(cookieName + '=') === 0;
        })[0];

        if (match) {
            return match.split('=')[1];
        }

        var stored = storageGet(window.localStorage, cookieName);
        var visitorId = stored || uuid();

        if (!stored) {
            storageSet(window.localStorage, cookieName, visitorId);
        }

        var parts = [
            cookieName + '=' + visitorId,
            'Path=/',
            'Max-Age=31536000',
            'SameSite=Lax'
        ];

        if (window.location.protocol === 'https:') {
            parts.push('Secure');
        }

        document.cookie = parts.join('; ');

        return visitorId;
    }

    function getSessionId() {
        var sessionId = storageGet(window.sessionStorage, 'shazzoo_session_id');

        if (!sessionId) {
            sessionId = uuid();
            storageSet(window.sessionStorage, 'shazzoo_session_id', sessionId);
        }

        return sessionId;
    }

    var baseData = {
        project_key: config.getAttribute('data-project-key') || '',
        content_id: config.getAttribute('data-content-id') || '',
        article_slug: config.getAttribute('data-article-slug') || '',
        referrer: document.referrer || '',
        landing_url: window.location.href,
        path: window.location.pathname,
        user_agent: navigator.userAgent || '',
        session_id: getSessionId()
    };

    function send(payload) {
        var body = JSON.stringify(payload);

        if (navigator.sendBeacon) {
            var blob = new Blob([body], { type: 'text/plain;charset=UTF-8' });

            if (navigator.sendBeacon(endpoint, blob)) {
                return;
            }
        }

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: body,
            keepalive: true
        }).catch(function () {
            // Tracking mag de pagina nooit breken.
        });
    }

    function event(extra) {
        var payload = { visitor_id: getVisitorId() };

        for (var key in baseData) {
            if (Object.prototype.hasOwnProperty.call(baseData, key)) {
                payload[key] = baseData[key];
            }
        }

        for (var extraKey in extra) {
            if (Object.prototype.hasOwnProperty.call(extra, extraKey)) {
                payload[extraKey] = extra[extraKey];
            }
        }

        payload.visitor_id = getVisitorId();

        send(payload);
    }

    event({ event_type: 'page_view' });

    document.addEventListener('click', function (e) {
        var link = e.target && e.target.closest ? e.target.closest('a') : null;

        if (!link || !link.href) {
            return;
        }

        var href = link.getAttribute('href') || '';

        if (href.indexOf('javascript:') === 0 || href.indexOf('#') === 0) {
            return;
        }

        event({ event_type: 'click', target_url: link.href });
    }, true);
})();
