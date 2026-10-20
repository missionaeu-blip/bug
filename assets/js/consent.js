/* Missiona — gestion des choix cookies
 * Aucun script ou traceur optionnel n'est chargé ici. Ce module mémorise le
 * choix, permet de le modifier et conserve une preuve pseudonymisée côté site. */
(function () {
  'use strict';

  var KEY = 'missiona_cookie_consent';
  var MAX_AGE = 183 * 24 * 60 * 60 * 1000; // 6 mois
  var root = document.getElementById('cookie-consent');
  if (!root) return;

  var banner = root.querySelector('.cookie-banner');
  var settings = root.querySelector('.cookie-settings');
  var functional = root.querySelector('input[name="functional"]');
  var analytics = root.querySelector('input[name="analytics"]');
  var version = root.getAttribute('data-policy-version') || '1.0';
  var restoreFocus = null;

  function read() {
    try {
      var raw = localStorage.getItem(KEY);
      var value = raw ? JSON.parse(raw) : null;
      if (!value || value.version !== version || !value.updatedAt || (Date.now() - value.updatedAt) > MAX_AGE) return null;
      return value;
    } catch (e) { return null; }
  }

  function token() {
    if (window.crypto && window.crypto.getRandomValues) {
      var values = new Uint32Array(4);
      window.crypto.getRandomValues(values);
      return Array.prototype.map.call(values, function (v) { return v.toString(16); }).join('');
    }
    return String(Date.now()) + '-' + Math.random().toString(16).slice(2);
  }

  function syncForm(value) {
    if (functional) functional.checked = !!value.functional;
    if (analytics) analytics.checked = !!value.analytics;
  }

  function persist(value) {
    try { localStorage.setItem(KEY, JSON.stringify(value)); } catch (e) { /* choix conservé seulement pendant la session */ }
    var endpoint = root.getAttribute('data-endpoint');
    var csrf = root.getAttribute('data-csrf') || '';
    if (!endpoint || !window.fetch) return;
    var body = new URLSearchParams({
      csrf: csrf,
      consent_id: value.id,
      decision: value.decision,
      functional: value.functional ? '1' : '0',
      analytics: value.analytics ? '1' : '0',
      version: value.version
    });
    window.fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body.toString()
    }).catch(function () { /* l’accès au site ne dépend jamais de cette trace */ });
  }

  function close() {
    root.hidden = true;
    banner.hidden = false;
    settings.hidden = true;
    var target = restoreFocus;
    restoreFocus = null;
    if (target && document.contains(target)) window.setTimeout(function () { target.focus(); }, 0);
  }

  function open(which) {
    var current = read() || { functional: false, analytics: false };
    syncForm(current);
    if (root.hidden && document.activeElement && document.activeElement !== document.body) {
      restoreFocus = document.activeElement;
    }
    root.hidden = false;
    var custom = which === 'settings';
    banner.hidden = custom;
    settings.hidden = !custom;
    window.setTimeout(function () {
      var target = custom ? settings.querySelector('[data-cookie-action="save"]') : banner.querySelector('[data-cookie-action="accept"]');
      if (target) target.focus();
    }, 0);
  }

  function trapFocus(event) {
    if (event.key !== 'Tab' || root.hidden) return;
    var panel = settings.hidden ? banner : settings;
    var items = Array.prototype.slice.call(panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'));
    if (!items.length) return;
    var first = items[0];
    var last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  }

  function save(decision, options) {
    var previous = read();
    var value = {
      id: previous && previous.id ? previous.id : token(),
      version: version,
      decision: decision,
      functional: !!options.functional,
      analytics: !!options.analytics,
      updatedAt: Date.now()
    };
    persist(value);
    close();
    document.dispatchEvent(new CustomEvent('missiona:cookie-consent', { detail: value }));
  }

  root.addEventListener('keydown', trapFocus);
  root.addEventListener('click', function (event) {
    var action = event.target.closest('[data-cookie-action]');
    if (!action) return;
    if (action.dataset.cookieAction === 'accept') save('accept_all', { functional: true, analytics: true });
    if (action.dataset.cookieAction === 'refuse') save('refuse_all', { functional: false, analytics: false });
    if (action.dataset.cookieAction === 'customize') open('settings');
    if (action.dataset.cookieAction === 'save') save('custom', { functional: functional && functional.checked, analytics: analytics && analytics.checked });
    if (action.dataset.cookieAction === 'close') close();
  });

  document.querySelectorAll('[data-open-cookie-settings]').forEach(function (button) {
    button.addEventListener('click', function () { open('settings'); });
  });
  document.addEventListener('missiona:open-cookie-settings', function () { open('settings'); });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !root.hidden) close();
  });

  if (!read()) open('banner');
})();
