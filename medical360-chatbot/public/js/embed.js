/**
 * Medical360 AI Chatbot — Single-line embed script
 * Usage:  <script src="https://medical360.org/wp-content/plugins/medical360-chatbot/public/js/embed.js" defer></script>
 *
 * This loader pulls in the full CSS + JS bundle from the same plugin directory
 * so any non-WordPress site can embed the chatbot with one line of HTML.
 */
(function () {
  'use strict';

  // Resolve the base URL from this script's own src attribute
  var scripts = document.getElementsByTagName('script');
  var thisScript = scripts[scripts.length - 1];
  var base = thisScript.src.replace(/\/public\/js\/embed\.js.*$/, '');

  // Override config if data-* attributes are present on the script tag
  var phone  = thisScript.dataset.phone  || '';
  var mapUrl = thisScript.dataset.mapUrl || '';
  var lang   = thisScript.dataset.lang   || '';
  var apiUrl = thisScript.dataset.api    || (base + '/wp-json/medical360/v1');
  var nonce  = thisScript.dataset.nonce  || '';

  window.M360Config = window.M360Config || {};
  Object.assign(window.M360Config, {
    apiUrl: apiUrl,
    nonce:  nonce,
    cssUrl: base + '/public/css/chatbot.css',
    lang:   lang || (document.documentElement.lang || 'he'),
    settings: Object.assign({
      phone:   phone,
      map_url: mapUrl,
    }, (window.M360Config.settings || {})),
    strings: window.M360Config.strings || {
      placeholder_he: 'שאל/י שאלה...',
      placeholder_en: 'Ask a question...',
      placeholder_ru: 'Задайте вопрос...',
      send_he: 'שלח', send_en: 'Send', send_ru: 'Отправить',
      typing_he: 'מקליד...', typing_en: 'Typing...', typing_ru: 'Печатает...',
      error_he: 'שגיאה. נסה שוב.', error_en: 'An error occurred. Please try again.',
      error_ru: 'Произошла ошибка. Попробуйте ещё раз.',
    },
  });

  function injectCSS() {
    var link  = document.createElement('link');
    link.rel  = 'stylesheet';
    link.type = 'text/css';
    link.href = base + '/public/css/chatbot.css';
    document.head.appendChild(link);
  }

  function injectRootDiv() {
    if (document.getElementById('m360-root')) return;
    var div = document.createElement('div');
    div.id  = 'm360-root';
    if (phone)  div.dataset.phone  = phone;
    if (mapUrl) div.dataset.mapUrl = mapUrl;
    div.setAttribute('aria-label', 'Medical360 AI Chatbot');
    div.setAttribute('role', 'complementary');
    document.body.appendChild(div);
  }

  function injectJS() {
    var script  = document.createElement('script');
    script.src  = base + '/public/js/chatbot.js';
    script.defer = true;
    document.body.appendChild(script);
  }

  function run() {
    // CSS is injected into the Shadow DOM by chatbot.js (see M360Config.cssUrl),
    // so no page-level stylesheet is needed — keeps the host page untouched.
    injectRootDiv();
    injectJS();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
